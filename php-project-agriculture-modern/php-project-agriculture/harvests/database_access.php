<?php
// =============================================================================
// harvests/database_access.php  |  Harvest queries
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
        ['23514', 'cannot be before the planting date', 'harvest_date',         'Harvest Date cannot be before the planting date of the selected planting.'],
        ['23514', 'ck_harvest_quantity',                'quantity_kg',          'Quantity (kg) must be greater than 0.'],
        ['23514', 'ck_harvest_price',                   'selling_price_per_kg', 'Selling Price per kg must be greater than 0.'],
        ['23503', 'fk_harvest_planting',                'planting_id',          'The selected planting record no longer exists.'],
        ['23503', 'fk_harvest_grade',                   'grade_id',             'The selected quality grade no longer exists.'],
        ['23503', 'fk_harvest_remark',                  'remark_id',            'The selected remark no longer exists.'],
    ];

    private const SORT = [
        'harvest_id'           => 'h.harvest_id',
        'planting_label'       => 'p.planting_id',
        'harvest_date'         => 'h.harvest_date',
        'quantity_kg'          => 'h.quantity_kg',
        'selling_price_per_kg' => 'h.selling_price_per_kg',
        'quality_grade'        => 'qg.grade_name',
        'remark_description'   => 'hr.remark_description',
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

    // a planting is shown as  PL-12 Navarro Rice Field / Rice (NSIC Rc 222)
    public function getPlantings(): array {
        return $this->options("SELECT p.planting_id, ('PL-' || p.planting_id || ' ' || f.farm_name || ' / ' || c.crop_name || ' (' || cv.variety_name || ')')
                                 FROM planting p
                                 JOIN farm         f  ON f.farm_id     = p.farm_id
                                 JOIN crop_variety cv ON cv.variety_id = p.variety_id
                                 JOIN crop         c  ON c.crop_id     = cv.crop_id
                                ORDER BY p.planting_id");
    }

    public function getQualityGrades(): array {
        return $this->options("SELECT grade_id, grade_name FROM quality_grade ORDER BY grade_name");
    }

    public function getHarvestRemarks(): array {
        return $this->options("SELECT remark_id, remark_description FROM harvest_remark ORDER BY remark_id");
    }

    // -------------------------------------------------------------------------
    // List / view
    // -------------------------------------------------------------------------

    public function listHarvests(string $search, string $gradeId, string $sort, string $dir,
                                 int $page, int $perPage): array {
        $from = "FROM harvest h
                 JOIN planting       p  ON p.planting_id = h.planting_id
                 JOIN farm           f  ON f.farm_id     = p.farm_id
                 JOIN crop_variety   cv ON cv.variety_id = p.variety_id
                 JOIN crop           c  ON c.crop_id     = cv.crop_id
                 JOIN quality_grade  qg ON qg.grade_id   = h.grade_id
                 JOIN harvest_remark hr ON hr.remark_id  = h.remark_id";

        [$where, $params] = $this->filters(
            "concat_ws(' ', ('PL-' || p.planting_id || ' ' || f.farm_name || ' / ' || c.crop_name || ' (' || cv.variety_name || ')'), qg.grade_name, hr.remark_description, CAST(h.harvest_id AS text),
                            to_char(h.harvest_date, 'YYYY-MM-DD'), CAST(h.quantity_kg AS text),
                            CAST(h.selling_price_per_kg AS text))",
            $search, 'qg.grade_id', $gradeId
        );

        $select = "h.harvest_id, ('PL-' || p.planting_id || ' ' || f.farm_name || ' / ' || c.crop_name || ' (' || cv.variety_name || ')') AS planting_label, h.harvest_date, h.quantity_kg,
                   h.selling_price_per_kg, qg.grade_name AS quality_grade, hr.remark_description";

        return $this->runList($from, $where, $params, $select,
            $this->orderBy(self::SORT, $sort, $dir, 'h.harvest_id', 'h.harvest_id'), $page, $perPage);
    }

    // One harvest for the view and edit pages, or null.
    // estimated_value is calculated (quantity x price), never stored.
    public function getHarvest(int $harvestId): ?array {
        $sql = "SELECT h.harvest_id, h.planting_id, ('PL-' || p.planting_id || ' ' || f.farm_name || ' / ' || c.crop_name || ' (' || cv.variety_name || ')') AS planting_label,
                       h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
                       ROUND(h.quantity_kg * h.selling_price_per_kg, 2) AS estimated_value,
                       qg.grade_id, qg.grade_name AS quality_grade,
                       hr.remark_id, hr.remark_description,
                       cb.full_name AS created_by, to_char(h.created_at AT TIME ZONE '" . self::DISPLAY_TZ . "', 'YYYY-MM-DD HH24:MI') AS created_at, ub.full_name AS updated_by, to_char(h.updated_at AT TIME ZONE '" . self::DISPLAY_TZ . "', 'YYYY-MM-DD HH24:MI') AS updated_at
                FROM harvest h
                 JOIN planting       p  ON p.planting_id = h.planting_id
                 JOIN farm           f  ON f.farm_id     = p.farm_id
                 JOIN crop_variety   cv ON cv.variety_id = p.variety_id
                 JOIN crop           c  ON c.crop_id     = cv.crop_id
                 JOIN quality_grade  qg ON qg.grade_id   = h.grade_id
                 JOIN harvest_remark hr ON hr.remark_id  = h.remark_id
                 JOIN app_user       cb ON cb.user_id    = h.created_by
                 JOIN app_user       ub ON ub.user_id    = h.updated_by
                WHERE h.harvest_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $harvestId]);
        return $stmt->fetch() ?: null;
    }

    // -------------------------------------------------------------------------
    // Create / update / delete
    // -------------------------------------------------------------------------

    // Returns the new harvest_id.
    public function createHarvest(int $planting_id, int $grade_id, int $remark_id, string $harvest_date,
                                  string $quantity_kg, string $selling_price_per_kg, int $userId): int {
        $this->checkUser($userId);
        $sql = "INSERT INTO harvest (planting_id, grade_id, remark_id, harvest_date, quantity_kg,
                                     selling_price_per_kg, created_by, updated_by)
                VALUES (:planting_id, :grade_id, :remark_id, :harvest_date, :quantity_kg,
                        :selling_price_per_kg, :created_by, :updated_by)
                RETURNING harvest_id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':planting_id'          => $planting_id,
                ':grade_id'             => $grade_id,
                ':remark_id'            => $remark_id,
                ':harvest_date'         => $harvest_date,
                ':quantity_kg'          => $quantity_kg,
                ':selling_price_per_kg' => $selling_price_per_kg,
                ':created_by'           => $userId,
                ':updated_by'           => $userId,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Sets updated_by only. A save that changes nothing does not touch updated_by / updated_at.
    public function updateHarvest(int $harvestId, int $planting_id, int $grade_id, int $remark_id,
                                  string $harvest_date, string $quantity_kg, string $selling_price_per_kg, int $userId): void {
        $this->checkUser($userId);
        try {
            $stmt = $this->pdo->prepare(
                "SELECT planting_id, grade_id, remark_id, to_char(harvest_date, 'YYYY-MM-DD') AS harvest_date,
                        quantity_kg, selling_price_per_kg
                   FROM harvest WHERE harvest_id = :id"
            );
            $stmt->execute([':id' => $harvestId]);
            $c = $stmt->fetch();
            if (!$c) {
                throw new DataException('_db', 'This harvest no longer exists.');
            }

            $changed = (int) $c['planting_id'] !== $planting_id
                    || (int) $c['grade_id'] !== $grade_id
                    || (int) $c['remark_id'] !== $remark_id
                    || $c['harvest_date'] !== $harvest_date
                    || abs((float) $c['quantity_kg'] - (float) $quantity_kg) >= 0.005
                    || abs((float) $c['selling_price_per_kg'] - (float) $selling_price_per_kg) >= 0.005;
            if (!$changed) {
                return;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE harvest
                    SET planting_id = :planting_id, grade_id = :grade_id, remark_id = :remark_id,
                        harvest_date = :harvest_date, quantity_kg = :quantity_kg,
                        selling_price_per_kg = :selling_price_per_kg, updated_by = :updated_by
                  WHERE harvest_id = :id"
            );
            $stmt->execute([
                ':planting_id'          => $planting_id,
                ':grade_id'             => $grade_id,
                ':remark_id'            => $remark_id,
                ':harvest_date'         => $harvest_date,
                ':quantity_kg'          => $quantity_kg,
                ':selling_price_per_kg' => $selling_price_per_kg,
                ':updated_by'           => $userId,
                ':id'                   => $harvestId,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Returns false if the harvest does not exist.
    public function deleteHarvest(int $harvestId): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM harvest WHERE harvest_id = :id");
            $stmt->execute([':id' => $harvestId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }
}
