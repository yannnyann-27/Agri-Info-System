<?php
// =============================================================================
// plantings/database_access.php  |  Planting queries
// Every SQL query of this module lives in this class; the pages never contain SQL.
// All queries are prepared statements, so user input is never added into the SQL text.
//
// AUDIT COLUMNS: an INSERT sets created_by AND updated_by to the logged-in user, an
// UPDATE sets only updated_by (created_by is never changed). $userId always comes from
// $_SESSION['user_id'] in the page, never from the form.
// =============================================================================

// A "the user can fix this" error. $field is the form field the message belongs to,
// or '_db' for a message about the whole form.
if (!class_exists('DataException')) {
    class DataException extends Exception {
        private string $field;

        public function __construct(string $field, string $message) {
            parent::__construct($message);
            $this->field = $field;
        }

        public function getField(): string {
            return $this->field;
        }
    }
}

class Database_access {
    private PDO $pdo;

    // Time zone used when showing created_at / updated_at on the view pages.
    private const DISPLAY_TZ = 'Asia/Manila';

    private const RULES = [
        ['23514', 'cannot exceed the farm area',       'area_planted',  'Area Planted cannot be more than the area of the selected farm.'],
        ['23514', 'cannot be after an existing harvest', 'planting_date', 'Planting Date cannot be after a harvest date already recorded for this planting.'],
        ['23514', 'ck_planting_area',                  'area_planted',  'Area Planted must be greater than 0.'],
        ['23514', 'ck_planting_seed',                  'seed_quantity', 'Seed Quantity must be greater than 0.'],
        ['23503', 'fk_planting_farm',                  'farm_id',       'The selected farm no longer exists.'],
        ['23503', 'fk_planting_variety',               'variety_id',    'The selected crop no longer exists.'],
        ['23503', 'fk_planting_status',                'status_id',     'The selected status no longer exists.'],
    ];

    private const SORT = [
        'planting_id'   => 'p.planting_id',
        'farm_name'     => 'f.farm_name',
        'crop_name'     => "(c.crop_name || ' ' || cv.variety_name)",
        'planting_date' => 'p.planting_date',
        'area_planted'  => 'p.area_planted',
        'status'        => 'ps.status_name',
    ];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // config/Database.php connects through Supabase's transaction pooler (port 6543).
        // That pooler does not support the server-side prepared statements PDO uses by
        // default, which makes queries fail at random. Emulated prepares make PDO put the
        // (safely escaped) values into the query on the PHP side instead, so every query
        // below works on port 6543 and on the session pooler (5432) alike.
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    // The audit user must be a real, logged-in user id.
    private function checkUser(int $userId): void {
        if ($userId <= 0) {
            throw new DataException('_db', 'Your session is not valid. Please log in again.');
        }
    }

    // Turns a raw PostgreSQL error into a message a user can understand.
    // Unknown errors are returned unchanged so the page can log them.
    private function translate(PDOException $e): Exception {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $msg   = $e->getMessage();

        foreach (self::RULES as [$sqlState, $needle, $field, $text]) {
            if ($state === $sqlState && str_contains($msg, $needle)) {
                return new DataException($field, $text);
            }
        }
        if ($state === '23503' && (str_contains($msg, '_created_by') || str_contains($msg, '_updated_by'))) {
            return new DataException('_db', 'Your user account was not found. Please log in again.');
        }
        if ($state === '23503') {
            return new DataException('_db', 'A selected value no longer exists. Please reload the page and try again.');
        }
        if ($state === '22007' || $state === '22008') {
            return new DataException('_db', 'One of the dates is not valid.');
        }
        if ($state === '22003') {
            return new DataException('_db', 'One of the numbers is too large.');
        }
        return $e;
    }

    // search text -> LIKE pattern in which % and _ typed by the user match literally
    private function like(string $search): string {
        return '%' . addcslashes($search, '\\%_') . '%';
    }

    // WHERE parts for "search" (one ILIKE over $searchExpr) and "filter" (an id compared with $filterColumn)
    private function filters(string $searchExpr, string $search, string $filterColumn, string $filter): array {
        $where  = [];
        $params = [];
        if ($search !== '') {
            $where[] = "$searchExpr ILIKE :search ESCAPE '\\'";
            $params[':search'] = $this->like($search);
        }
        if ($filter !== '' && ctype_digit($filter)) {
            $where[] = "$filterColumn = :filter";
            $params[':filter'] = (int) $filter;
        }
        return [$where, $params];
    }

    // Count, clamp the page, then fetch just that page.
    // Returns ['rows' => [...], 'total' => int, 'pages' => int, 'page' => int]
    private function runList(string $from, array $where, array $params, string $select,
                             string $orderBy, int $page, int $perPage): array {
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->pdo->prepare("SELECT count(*) $from $whereSql");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($pages, max(1, $page));

        $stmt = $this->pdo->prepare("SELECT $select $from $whereSql ORDER BY $orderBy LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    // [[id, label], ...] for dropdowns: the same shape the form templates use
    private function options(string $sql): array {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
    }

    // "ORDER BY" text from a whitelist, so a tampered ?sort= / ?dir= cannot inject SQL
    private function orderBy(array $map, string $sort, string $dir, string $default, string $tieBreak): string {
        $column    = $map[$sort] ?? $default;
        $direction = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        return "$column $direction, $tieBreak ASC";
    }

    // -------------------------------------------------------------------------
    // Dropdown options: [[id stored, label shown], ...]
    // -------------------------------------------------------------------------

    public function getFarms(): array {
        return $this->options("SELECT farm_id, farm_name FROM farm ORDER BY farm_name, farm_id");
    }

    // the "crop" of a planting is a crop variety, shown as  Rice (NSIC Rc 222)
    public function getVarieties(): array {
        return $this->options("SELECT cv.variety_id, c.crop_name || ' (' || cv.variety_name || ')'
                                 FROM crop_variety cv JOIN crop c ON c.crop_id = cv.crop_id
                                ORDER BY c.crop_name, cv.variety_name");
    }

    // Helpers for the logical rules checked in the create / edit forms
    // (the database triggers enforce the same rules, so nothing can slip past).

    // area of a farm in hectares, or null if the farm does not exist
    public function getFarmArea(int $farmId): ?string {
        $stmt = $this->pdo->prepare("SELECT area_hectares FROM farm WHERE farm_id = :id");
        $stmt->execute([':id' => $farmId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    // the earliest harvest date already recorded for a planting (YYYY-MM-DD), or null if none
    public function getFirstHarvestDate(int $plantingId): ?string {
        $stmt = $this->pdo->prepare("SELECT min(harvest_date) FROM harvest WHERE planting_id = :id");
        $stmt->execute([':id' => $plantingId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (string) $v;
    }

    public function getPlantingStatuses(): array {
        return $this->options("SELECT status_id, status_name FROM planting_status ORDER BY status_id");
    }

    // -------------------------------------------------------------------------
    // List / view
    // -------------------------------------------------------------------------

    public function listPlantings(string $search, string $statusId, string $sort, string $dir,
                                  int $page, int $perPage): array {
        $from = "FROM planting p
                 JOIN farm            f  ON f.farm_id     = p.farm_id
                 JOIN crop_variety    cv ON cv.variety_id = p.variety_id
                 JOIN crop            c  ON c.crop_id     = cv.crop_id
                 JOIN planting_status ps ON ps.status_id  = p.status_id";

        [$where, $params] = $this->filters(
            "concat_ws(' ', f.farm_name, c.crop_name, cv.variety_name, ps.status_name, CAST(p.planting_id AS text),
                            to_char(p.planting_date, 'YYYY-MM-DD'), CAST(p.area_planted AS text))",
            $search, 'ps.status_id', $statusId
        );

        $select = "p.planting_id, f.farm_name, (c.crop_name || ' (' || cv.variety_name || ')') AS crop_name,
                   p.planting_date, p.area_planted, ps.status_name AS status";

        return $this->runList($from, $where, $params, $select,
            $this->orderBy(self::SORT, $sort, $dir, 'p.planting_id', 'p.planting_id'), $page, $perPage);
    }

    // One planting for the view and edit pages, or null.
    // expected_harvest_date is calculated (planting date + the variety's growing days), never stored.
    public function getPlanting(int $plantingId): ?array {
        $sql = "SELECT p.planting_id, p.farm_id, f.farm_name,
                       p.variety_id, (c.crop_name || ' (' || cv.variety_name || ')') AS crop_name,
                       p.planting_date, p.area_planted, p.seed_quantity,
                       (p.planting_date + cv.growing_period_days) AS expected_harvest_date,
                       ps.status_id, ps.status_name AS status,
                       cb.full_name AS created_by, to_char(p.created_at AT TIME ZONE '" . self::DISPLAY_TZ . "', 'YYYY-MM-DD HH24:MI') AS created_at, ub.full_name AS updated_by, to_char(p.updated_at AT TIME ZONE '" . self::DISPLAY_TZ . "', 'YYYY-MM-DD HH24:MI') AS updated_at
                FROM planting p
                JOIN farm            f  ON f.farm_id     = p.farm_id
                JOIN crop_variety    cv ON cv.variety_id = p.variety_id
                JOIN crop            c  ON c.crop_id     = cv.crop_id
                JOIN planting_status ps ON ps.status_id  = p.status_id
                JOIN app_user        cb ON cb.user_id    = p.created_by
                JOIN app_user        ub ON ub.user_id    = p.updated_by
                WHERE p.planting_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $plantingId]);
        return $stmt->fetch() ?: null;
    }

    // -------------------------------------------------------------------------
    // Create / update / delete
    // -------------------------------------------------------------------------

    // Returns the new planting_id.
    public function createPlanting(int $farm_id, int $variety_id, int $status_id, string $planting_date,
                                   string $area_planted, string $seed_quantity, int $userId): int {
        $this->checkUser($userId);
        $sql = "INSERT INTO planting (farm_id, variety_id, status_id, planting_date, area_planted,
                                      seed_quantity, created_by, updated_by)
                VALUES (:farm_id, :variety_id, :status_id, :planting_date, :area_planted,
                        :seed_quantity, :created_by, :updated_by)
                RETURNING planting_id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':farm_id'       => $farm_id,
                ':variety_id'    => $variety_id,
                ':status_id'     => $status_id,
                ':planting_date' => $planting_date,
                ':area_planted'  => $area_planted,
                ':seed_quantity' => $seed_quantity,
                ':created_by'    => $userId,
                ':updated_by'    => $userId,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Sets updated_by only. A save that changes nothing does not touch updated_by / updated_at.
    public function updatePlanting(int $plantingId, int $farm_id, int $variety_id, int $status_id,
                                   string $planting_date, string $area_planted, string $seed_quantity, int $userId): void {
        $this->checkUser($userId);
        try {
            $stmt = $this->pdo->prepare(
                "SELECT farm_id, variety_id, status_id, to_char(planting_date, 'YYYY-MM-DD') AS planting_date,
                        area_planted, seed_quantity
                   FROM planting WHERE planting_id = :id"
            );
            $stmt->execute([':id' => $plantingId]);
            $c = $stmt->fetch();
            if (!$c) {
                throw new DataException('_db', 'This planting no longer exists.');
            }

            $changed = (int) $c['farm_id'] !== $farm_id
                    || (int) $c['variety_id'] !== $variety_id
                    || (int) $c['status_id'] !== $status_id
                    || $c['planting_date'] !== $planting_date
                    || abs((float) $c['area_planted'] - (float) $area_planted) >= 0.005
                    || abs((float) $c['seed_quantity'] - (float) $seed_quantity) >= 0.005;
            if (!$changed) {
                return;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE planting
                    SET farm_id = :farm_id, variety_id = :variety_id, status_id = :status_id,
                        planting_date = :planting_date, area_planted = :area_planted,
                        seed_quantity = :seed_quantity, updated_by = :updated_by
                  WHERE planting_id = :id"
            );
            $stmt->execute([
                ':farm_id'       => $farm_id,
                ':variety_id'    => $variety_id,
                ':status_id'     => $status_id,
                ':planting_date' => $planting_date,
                ':area_planted'  => $area_planted,
                ':seed_quantity' => $seed_quantity,
                ':updated_by'    => $userId,
                ':id'            => $plantingId,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Returns false if the planting does not exist.
    // NOTE: the harvest records of this planting are deleted with it (ON DELETE CASCADE).
    public function deletePlanting(int $plantingId): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM planting WHERE planting_id = :id");
            $stmt->execute([':id' => $plantingId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }
}