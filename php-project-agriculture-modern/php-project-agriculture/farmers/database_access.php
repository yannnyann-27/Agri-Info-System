<?php
// =============================================================================
// farmers/database_access.php  |  Farmer queries
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

    // Time zone used when showing created_at / updated_at on the view page.
    private const DISPLAY_TZ = 'Asia/Manila';

    // PostgreSQL error -> [SQLSTATE, text found in the message, form field, message for the user]
    private const RULES = [
        ['23505', 'uq_farmer_contact',      'contact_number',  'This contact number is already registered to another farmer.'],
        ['23514', 'ck_farmer_registered',   'date_registered', 'Date Registered cannot be in the future.'],
        ['23514', 'ck_farmer_contact',      'contact_number',  'Contact Number may only contain digits, +, -, spaces and parentheses (7 to 20 characters).'],
        // deleting a farmer that still owns farms (the message names the table being deleted from)
        ['23503', 'update or delete on table "farmer"', '_db', 'This farmer cannot be deleted because farm records still belong to them.'],
    ];

    // ?sort= value -> SQL column. Only these ever reach ORDER BY, so a tampered URL cannot inject SQL.
    private const SORT = [
        'farmer_id'       => 'fr.farmer_id',
        'first_name'      => 'fr.first_name',
        'last_name'       => 'fr.last_name',
        'contact_number'  => 'fr.contact_number',
        'address'         => 'fr.address',
        'date_registered' => 'fr.date_registered',
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
            return new DataException('_db', 'A related record no longer exists. Please reload the page and try again.');
        }
        if ($state === '22001') {                                   // string_data_right_truncation
            return new DataException('_db', 'One of the values is too long.');
        }
        if ($state === '22007' || $state === '22008') {
            return new DataException('date_registered', 'Date Registered is not a valid date.');
        }
        return $e;
    }

    // search text -> LIKE pattern in which % and _ typed by the user match literally
    private function like(string $search): string {
        return '%' . addcslashes($search, '\\%_') . '%';
    }

    // -------------------------------------------------------------------------
    // Dropdown options
    // -------------------------------------------------------------------------

    // Every address in use, for the "filter by address" dropdown on the list page.
    public function getAddresses(): array {
        return $this->pdo->query("SELECT DISTINCT address FROM farmer ORDER BY address")->fetchAll(PDO::FETCH_COLUMN);
    }

    // The database's own "today" (CURRENT_DATE). ck_farmer_registered compares against it, and the
    // database runs in UTC, so using PHP's clock/time zone could disagree around midnight.
    public function getToday(): string {
        return (string) $this->pdo->query("SELECT CURRENT_DATE")->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // List / view
    // -------------------------------------------------------------------------

    // Search + address filter + sort + pagination, all in SQL.
    // Returns ['rows' => [...], 'total' => int, 'pages' => int, 'page' => int]
    // ('page' is the page actually shown, after clamping to 1..pages).
    public function listFarmers(string $search, string $address, string $sort, string $dir,
                                int $page, int $perPage): array {
        $where  = [];
        $params = [];

        if ($search !== '') {
            // searches every column shown in the list
            $where[] = "concat_ws(' ', CAST(fr.farmer_id AS text), fr.first_name, fr.last_name,
                                  fr.contact_number, fr.address, CAST(fr.date_registered AS text))
                        ILIKE :search ESCAPE '\\'";
            $params[':search'] = $this->like($search);
        }
        if ($address !== '') {
            $where[] = "fr.address = :address";
            $params[':address'] = $address;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // total first, so the requested page can be clamped to a page that exists
        $stmt = $this->pdo->prepare("SELECT count(*) FROM farmer fr $whereSql");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($pages, max(1, $page));

        $column    = self::SORT[$sort] ?? 'fr.farmer_id';
        $direction = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT fr.farmer_id, fr.first_name, fr.last_name, fr.contact_number, fr.address, fr.date_registered
                  FROM farmer fr
                  $whereSql
                 ORDER BY $column $direction, fr.farmer_id ASC
                 LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    // One farmer for the view and edit pages, or null if it does not exist.
    // created_by / updated_by come back as the user's full name, ready to display.
    public function getFarmer(int $farmerId): ?array {
        $tz  = self::DISPLAY_TZ;
        $sql = "SELECT fr.farmer_id, fr.first_name, fr.last_name, fr.contact_number, fr.address,
                       fr.date_registered,
                       cb.full_name AS created_by,
                       to_char(fr.created_at AT TIME ZONE '$tz', 'YYYY-MM-DD HH24:MI') AS created_at,
                       ub.full_name AS updated_by,
                       to_char(fr.updated_at AT TIME ZONE '$tz', 'YYYY-MM-DD HH24:MI') AS updated_at
                  FROM farmer fr
                  JOIN app_user cb ON cb.user_id = fr.created_by
                  JOIN app_user ub ON ub.user_id = fr.updated_by
                 WHERE fr.farmer_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $farmerId]);
        return $stmt->fetch() ?: null;
    }

    // -------------------------------------------------------------------------
    // Create / update / delete
    // -------------------------------------------------------------------------

    // Returns the new farmer_id.
    public function createFarmer(string $first_name, string $last_name, string $contact_number,
                                 string $address, string $date_registered, int $userId): int {
        $this->checkUser($userId);
        $sql = "INSERT INTO farmer (first_name, last_name, contact_number, address, date_registered,
                                    created_by, updated_by)
                VALUES (:first_name, :last_name, :contact_number, :address, :date_registered,
                        :created_by, :updated_by)
                RETURNING farmer_id";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':first_name'      => $first_name,
                ':last_name'       => $last_name,
                ':contact_number'  => $contact_number,
                ':address'         => $address,
                ':date_registered' => $date_registered,
                ':created_by'      => $userId,
                ':updated_by'      => $userId,
            ]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Sets updated_by only. A save that changes nothing does not touch updated_by / updated_at.
    public function updateFarmer(int $farmerId, string $first_name, string $last_name, string $contact_number,
                                 string $address, string $date_registered, int $userId): void {
        $this->checkUser($userId);
        try {
            $stmt = $this->pdo->prepare(
                "SELECT first_name, last_name, contact_number, address, date_registered
                   FROM farmer WHERE farmer_id = :id"
            );
            $stmt->execute([':id' => $farmerId]);
            $c = $stmt->fetch();
            if (!$c) {
                throw new DataException('_db', 'This farmer no longer exists.');
            }

            $changed = $c['first_name'] !== $first_name
                    || $c['last_name'] !== $last_name
                    || $c['contact_number'] !== $contact_number
                    || $c['address'] !== $address
                    || (string) $c['date_registered'] !== $date_registered;
            if (!$changed) {
                return;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE farmer
                    SET first_name = :first_name, last_name = :last_name, contact_number = :contact_number,
                        address = :address, date_registered = :date_registered, updated_by = :updated_by
                  WHERE farmer_id = :id"
            );
            $stmt->execute([
                ':first_name'      => $first_name,
                ':last_name'       => $last_name,
                ':contact_number'  => $contact_number,
                ':address'         => $address,
                ':date_registered' => $date_registered,
                ':updated_by'      => $userId,
                ':id'              => $farmerId,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }

    // Returns false if the farmer does not exist. A farmer who still has farms is never
    // deleted (checked here first, so it holds even if the foreign key were ON DELETE
    // CASCADE); a DataException explains why.
    public function deleteFarmer(int $farmerId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT count(*) FROM farm WHERE farmer_id = :id");
            $stmt->execute([':id' => $farmerId]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new DataException('_db', 'This farmer cannot be deleted because farm records still belong to them.');
            }

            $stmt = $this->pdo->prepare("DELETE FROM farmer WHERE farmer_id = :id");
            $stmt->execute([':id' => $farmerId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw $this->translate($e);
        }
    }
}
