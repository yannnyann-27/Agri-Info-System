<?php
// =============================================================================
// farms/database_access.php  |  Farm queries
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

    // PostgreSQL error -> [SQLSTATE, text found in the message, form field, message for the user]
    private const RULES = [
        ['23505', 'uq_farm_farmer_name',     'farm_name',          'This farmer already has a farm with that name.'],
        ['23514', 'ck_farm_area',            'area_hectares',      'Area (hectares) must be greater than 0.'],
        ['23514', 'cannot be smaller than',  'area_hectares',      'Area (hectares) cannot be smaller than the area already used by this farm\'s plantings.'],
        ['23503', 'fk_planting_farm',        '_db',                'This farm cannot be deleted because planting records still use it.'],
        ['23503', 'fk_farm_farmer',          'farmer_id',          'The selected farmer no longer exists.'],
        ['23503', 'fk_farm_irrigation',      'irrigation_type_id', 'The selected irrigation type no longer exists.'],
        ['23503', 'fk_farm_soil',            'soil_type_id',       'The selected soil type no longer exists.'],
    ];

    // ?sort= value -> SQL column
    private const SORT = [
        'farm_id'         => 'f.farm_id',
        'farm_name'       => 'f.farm_name',
        'farmer_name'     => "(fr.first_name || ' ' || fr.last_name)",
        'location'        => 'f.location',
        'area_hectares'   => 'f.area_hectares',
        'irrigation_type' => 'it.irrigation_type_name',
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

    public function getFarmers(): array {
        return $this->options("SELECT farmer_id, first_name || ' ' || last_name FROM farmer ORDER BY first_name, last_name, farmer_id");
    }

    public function getSoilTypes(): array {
        return $this->options("SELECT soil_type_id, soil_type_name FROM soil_type ORDER BY soil_type_name");
    }

    public function getIrrigationTypes(): array {
        return $this->options("SELECT irrigation_type_id, irrigation_type_name FROM irrigation_type ORDER BY irrigation_type_name");
    }

    // -------------------------------------------------------------------------
    // List / view
    // -------------------------------------------------------------------------

    // Search + irrigation filter + sort + pagination, all in SQL.
    public function listFarms(string $search, string $irrigationId, string $sort, string $dir,
                              int $page, int $perPage): array {
        $from = "FROM farm f
                 JOIN farmer          fr ON fr.farmer_id          = f.farmer_id
                 JOIN irrigation_type it ON it.irrigation_type_id = f.irrigation_type_id
                 JOIN soil_type       st ON st.soil_type_id       = f.soil_type_id";

        [$where, $params] = $this->filters(
            "concat_ws(' ', f.farm_name, fr.first_name, fr.last_name, f.location, it.irrigation_type_name,
                            st.soil_type_name, CAST(f.farm_id AS text), CAST(f.area_hectares AS text))",
            $search, 'it.irrigation_type_id', $irrigationId
        );

        $select = "f.farm_id, f.farm_name, fr.first_name || ' ' || fr.last_name AS farmer_name,
                   f.location, f.area_hectares, it.irrigation_type_name AS irrigation_type";

        return $this->runList($from, $where, $params, $select,
            $this->orderBy(self::SORT, $sort, $dir, 'f.farm_id', 'f.farm_id'), $page, $perPage);
    }

    // One farm for the view and edit pages, or null. The *_id columns feed the edit form;
    // created_by / updated_by come back as the user's full name.
    public function getFarm(int $farmId): ?array {
        $sql = "SELECT f.farm_id, f.farmer_id, fr.first_name || ' ' || fr.last_name AS farmer_name,
                       f.farm_name, f.location, f.area_hectares,
                       st.soil_type_id, st.soil_type_name AS soil_type,
                       it.irrigation_type_id, it.irrigation_type_name AS irrigation_type,
                       cb.full_name AS created_by, to_char(f.created_at AT TIME ZONE '" . self::DISPLAY_TZ . "', 'YYYY-MM-DD HH24:MI') AS created_at, ub.full_name AS updated_by, to_char(f.updated_at AT TIME ZONE '" . self::DISPLAY_TZ . "', 'YYYY-MM-DD HH24:MI') AS updated_at
                FROM farm f
                JOIN farmer          fr ON fr.farmer_id          = f.farmer_id
                JOIN soil_type       st ON st.soil_type_id       = f.soil_type_id
                JOIN irrigation_type it ON it.irrigation_type_id = f.irrigation_type_id
                JOIN app_user        cb ON cb.user_id            = f.created_by
                JOIN app_user        ub ON ub.user_id            = f.updated_by
                WHERE f.farm_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $farmId]);
        return $stmt->fetch() ?: null;
    }

    // -------------------------------------------------------------------------
    // Create / update / delete
    // -------------------------------------------------------------------------

    // Returns the new farm_id.
    public function createFarm(int $farmer_id, int $irrigation_type_id, int $soil_type_id, string $farm_name,
                               string $location, string $area_hectares, int $userId): int {
        $this->checkUser($userId);
        $sql = "INSERT INTO farm (farmer_id, irrigation_type_id, soil_type_id, farm_name, location,
                                  area_hectares, created_by, updated_by)
                VALUES (:farmer_id, :irrigation_type_id, :soil_type_id, :farm_name, :location,
                        :area_hectares, :created_by, :updated_by)
                RETURNING farm_id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':farmer_id'          => $farmer_id,
                ':irrigation_type_id' => $irrigation_type_id,
                ':soil_type_id'       => $soil_type_id,
                ':farm_name'          => $farm_name,
                ':location'           => $location,
                ':area_hectares'      => $area_hectares,
                ':created_by'         => $userId,
                ':updated_by'         => $userId,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Sets updated_by only. A save that changes nothing does not touch updated_by / updated_at.
    public function updateFarm(int $farmId, int $farmer_id, int $irrigation_type_id, int $soil_type_id,
                               string $farm_name, string $location, string $area_hectares, int $userId): void {
        $this->checkUser($userId);
        try {
            $stmt = $this->pdo->prepare(
                "SELECT farmer_id, irrigation_type_id, soil_type_id, farm_name, location, area_hectares
                   FROM farm WHERE farm_id = :id"
            );
            $stmt->execute([':id' => $farmId]);
            $c = $stmt->fetch();
            if (!$c) {
                throw new DataException('_db', 'This farm no longer exists.');
            }

            $changed = (int) $c['farmer_id'] !== $farmer_id
                    || (int) $c['irrigation_type_id'] !== $irrigation_type_id
                    || (int) $c['soil_type_id'] !== $soil_type_id
                    || $c['farm_name'] !== $farm_name
                    || $c['location'] !== $location
                    || abs((float) $c['area_hectares'] - (float) $area_hectares) >= 0.005;
            if (!$changed) {
                return;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE farm
                    SET farmer_id = :farmer_id, irrigation_type_id = :irrigation_type_id, soil_type_id = :soil_type_id,
                        farm_name = :farm_name, location = :location, area_hectares = :area_hectares,
                        updated_by = :updated_by
                  WHERE farm_id = :id"
            );
            $stmt->execute([
                ':farmer_id'          => $farmer_id,
                ':irrigation_type_id' => $irrigation_type_id,
                ':soil_type_id'       => $soil_type_id,
                ':farm_name'          => $farm_name,
                ':location'           => $location,
                ':area_hectares'      => $area_hectares,
                ':updated_by'         => $userId,
                ':id'                 => $farmId,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Returns false if the farm does not exist. If planting records still use the farm the
    // database refuses (ON DELETE RESTRICT) and a DataException is thrown.
    public function deleteFarm(int $farmId): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM farm WHERE farm_id = :id");
            $stmt->execute([':id' => $farmId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }
}
