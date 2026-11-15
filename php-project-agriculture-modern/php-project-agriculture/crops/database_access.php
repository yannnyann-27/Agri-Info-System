<?php
// =============================================================================
// Database_access: every SQL query of the application lives in this class.
// Pages never contain SQL; they call these methods. All queries are prepared
// statements, so user input is never concatenated into SQL.
//
// AUDIT COLUMNS (created_by / updated_by)
//   * Every INSERT sets BOTH created_by and updated_by to the logged-in user.
//   * Every UPDATE (added later) sets ONLY updated_by. created_by is never
//     written after the insert (the database trigger also protects it).
//   * $userId always comes from $_SESSION['user_id'] in the page, never from
//     $_POST, so a user cannot pretend to be someone else.
//   * It must be an existing app_user.user_id, otherwise the foreign key
//     fails and the INSERT is rejected.
// =============================================================================

// A "the user can fix this" error. $field is the form field the message belongs
// to, or '_db' for a message about the whole form.
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

class Database_access {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
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

        if ($state === '23505') {                                   // unique_violation
            if (str_contains($msg, 'uq_farmer_contact')) {
                return new DataException('contact_number', 'This contact number is already registered to another farmer.');
            }
            if (str_contains($msg, 'uq_crop_variety_name')) {
                return new DataException('variety', 'This variety already exists for that crop.');
            }
            if (str_contains($msg, 'uq_crop_name')) {
                return new DataException('crop_name', 'Another user just added this crop. Please try again.');
            }
        }

        if ($state === '23503') {                                   // foreign_key_violation
            if (str_contains($msg, 'fk_planting_variety')) {
                return new DataException('_db', 'This crop cannot be deleted because planting records still use it.');
            }
            if (str_contains($msg, '_created_by') || str_contains($msg, '_updated_by')) {
                return new DataException('_db', 'Your user account was not found. Please log in again.');
            }
            return new DataException('_db', 'A selected value no longer exists. Please reload the page and try again.');
        }

        if ($state === '23514') {                                   // check_violation
            if (str_contains($msg, 'ck_farmer_registered')) {
                return new DataException('date_registered', 'Date Registered cannot be in the future.');
            }
            if (str_contains($msg, 'ck_farmer_contact')) {
                return new DataException('contact_number', 'Contact Number may only contain digits, +, -, spaces and parentheses (7 to 20 characters).');
            }
            if (str_contains($msg, 'ck_crop_variety_days')) {
                return new DataException('growing_period_days', 'Growing Period (days) must be between 1 and 3650.');
            }
        }

        return $e;
    }

    // -------------------------------------------------------------------------
    // Lookups (dropdown options). Each returns [[id, label], ...], the same
    // shape the form templates already use: foreach ($options[$name] as [$val, $text]).
    // -------------------------------------------------------------------------

    public function getCropCategories(): array {
        $sql = "SELECT category_id, category_name FROM crop_category ORDER BY category_name";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
    }

    public function getSeasons(): array {
        $sql = "SELECT season_id, season_name FROM season ORDER BY season_id";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
    }
    // -------------------------------------------------------------------------
    // Crop
    //   The ERD splits a crop in two tables:
    //     crop         (crop_name, category)                 e.g. Rice / Cereal
    //     crop_variety (variety, growing days, season)       e.g. NSIC Rc 222 / 115 / Wet Season
    //   so one form saves two rows. Category and season are chosen from the
    //   dropdowns, so the form sends their IDs; lookup rows are never inserted here.
    //   Both inserts run in one transaction: either both are saved or neither.
    //   Returns the new variety_id.
    // -------------------------------------------------------------------------

    public function createCrop(string $crop_name, string $variety_name, int $category_id,
                               int $season_id, int $growing_period_days, int $userId): int {
        $this->checkUser($userId);

        $this->pdo->beginTransaction();
        try {
            // 1) Reuse the crop if it already exists (same name, ignoring upper/lower case)
            $stmt = $this->pdo->prepare(
                "SELECT c.crop_id, c.category_id, cc.category_name
                   FROM crop c
                   JOIN crop_category cc ON cc.category_id = c.category_id
                  WHERE lower(c.crop_name) = lower(:crop_name)"
            );
            $stmt->execute([':crop_name' => $crop_name]);
            $existing = $stmt->fetch();

            if ($existing) {
                // A crop belongs to exactly one category. Do not silently move it,
                // because that would change the category of its other varieties too.
                if ((int) $existing['category_id'] !== $category_id) {
                    throw new DataException('category_id',
                        "\"{$crop_name}\" already exists under the category \"{$existing['category_name']}\". "
                        . "Choose that category, or use a different crop name.");
                }
                $cropId = (int) $existing['crop_id'];
            } else {
                // 2) New crop
                $stmt = $this->pdo->prepare(
                    "INSERT INTO crop (category_id, crop_name, created_by, updated_by)
                     VALUES (:category_id, :crop_name, :created_by, :updated_by)
                     RETURNING crop_id"
                );
                $stmt->execute([
                    ':category_id' => $category_id,
                    ':crop_name'   => $crop_name,
                    ':created_by'  => $userId,
                    ':updated_by'  => $userId,
                ]);
                $cropId = (int) $stmt->fetchColumn();
            }

            // 3) The variety (always new)
            $stmt = $this->pdo->prepare(
                "INSERT INTO crop_variety
                        (crop_id, season_id, variety_name, growing_period_days, created_by, updated_by)
                 VALUES (:crop_id, :season_id, :variety_name, :growing_period_days, :created_by, :updated_by)
                 RETURNING variety_id"
            );
            $stmt->execute([
                ':crop_id'             => $cropId,
                ':season_id'           => $season_id,
                ':variety_name'        => $variety_name,
                ':growing_period_days' => $growing_period_days,
                ':created_by'          => $userId,
                ':updated_by'          => $userId,
            ]);
            $varietyId = (int) $stmt->fetchColumn();

            $this->pdo->commit();
            return $varietyId;

        } catch (DataException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->translate($e);
        }
    }

    // -------------------------------------------------------------------------
    // Crop list / view / update / delete
    //   One row of the Crops screen = one crop VARIETY (crop_variety joined with
    //   its crop, category and season). The id used in the links is variety_id.
    // -------------------------------------------------------------------------

    // Time zone used when showing created_at / updated_at on the view page.
    private const DISPLAY_TZ = 'Asia/Manila';

    // ?sort= value -> SQL column. Only these ever reach ORDER BY, so a tampered
    // URL cannot inject SQL.
    private const CROP_SORT = [
        'variety_id'          => 'cv.variety_id',
        'crop_name'           => 'c.crop_name',
        'variety'             => 'cv.variety_name',
        'crop_category'       => 'cc.category_name',
        'growing_period_days' => 'cv.growing_period_days',
        'recommended_season'  => 'se.season_name',
    ];

    // Search + category filter + sort + pagination, all done in SQL.
    // Returns ['rows' => [...], 'total' => int, 'pages' => int, 'page' => int]
    // ('page' is the page actually shown, after clamping to 1..pages).
    public function listCrops(string $search, string $categoryId, string $sort, string $dir,
                              int $page, int $perPage): array {
        $from = "FROM crop_variety cv
                 JOIN crop          c  ON c.crop_id      = cv.crop_id
                 JOIN crop_category cc ON cc.category_id = c.category_id
                 JOIN season        se ON se.season_id   = cv.season_id";

        $where  = [];
        $params = [];

        if ($search !== '') {
            // searches every column shown in the list; % and _ typed by the user are matched literally
            $where[] = "concat_ws(' ', c.crop_name, cv.variety_name, cc.category_name, se.season_name,
                                  CAST(cv.variety_id AS text), CAST(cv.growing_period_days AS text))
                        ILIKE :search ESCAPE '\\'";
            $params[':search'] = '%' . addcslashes($search, '\\%_') . '%';
        }
        if ($categoryId !== '' && ctype_digit($categoryId)) {
            $where[] = "cc.category_id = :category_id";
            $params[':category_id'] = (int) $categoryId;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // total first, so the requested page can be clamped to a page that exists
        $stmt = $this->pdo->prepare("SELECT count(*) $from $whereSql");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($pages, max(1, $page));

        $column    = self::CROP_SORT[$sort] ?? 'cv.variety_id';
        $direction = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT cv.variety_id,
                       c.crop_name,
                       cv.variety_name       AS variety,
                       cc.category_name      AS crop_category,
                       cv.growing_period_days,
                       se.season_name        AS recommended_season
                $from $whereSql
                ORDER BY $column $direction, cv.variety_id ASC
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

    // One crop variety for the view and edit pages, or null if it does not exist.
    // crop_id / category_id / season_id are for the edit form; created_by and
    // updated_by come back as the user's full name, ready to display.
    public function getCrop(int $varietyId): ?array {
        $tz  = self::DISPLAY_TZ;
        $sql = "SELECT cv.variety_id,
                       c.crop_id,
                       c.crop_name,
                       cv.variety_name        AS variety,
                       cc.category_id,
                       cc.category_name       AS crop_category,
                       cv.growing_period_days,
                       se.season_id,
                       se.season_name         AS recommended_season,
                       cb.full_name           AS created_by,
                       to_char(cv.created_at AT TIME ZONE '$tz', 'YYYY-MM-DD HH24:MI') AS created_at,
                       ub.full_name           AS updated_by,
                       to_char(cv.updated_at AT TIME ZONE '$tz', 'YYYY-MM-DD HH24:MI') AS updated_at
                FROM crop_variety cv
                JOIN crop          c  ON c.crop_id      = cv.crop_id
                JOIN crop_category cc ON cc.category_id = c.category_id
                JOIN season        se ON se.season_id   = cv.season_id
                JOIN app_user      cb ON cb.user_id     = cv.created_by
                JOIN app_user      ub ON ub.user_id     = cv.updated_by
                WHERE cv.variety_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $varietyId]);
        return $stmt->fetch() ?: null;
    }

    // Updates one variety (and its crop where needed).
    //   AUDIT: only updated_by is written (to $userId); created_by is never touched,
    //   and a save that changes nothing does not touch updated_by / updated_at.
    //   Typing a crop name that:
    //     * is the same crop           -> its name spelling / category are updated
    //                                    (the category belongs to the crop, so it applies to all its varieties)
    //     * is another existing crop   -> the variety moves there (category must match that crop's)
    //     * is a brand new name        -> the crop is renamed if this was its only variety,
    //                                    otherwise a new crop is created and the variety moves to it
    //   A crop left with no varieties is removed. Everything runs in one transaction.
    public function updateCrop(int $varietyId, string $crop_name, string $variety_name, int $category_id,
                               int $season_id, int $growing_period_days, int $userId): void {
        $this->checkUser($userId);

        $this->pdo->beginTransaction();
        try {
            // current values (row is locked until the transaction ends)
            $stmt = $this->pdo->prepare(
                "SELECT crop_id, season_id, variety_name, growing_period_days
                   FROM crop_variety WHERE variety_id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $varietyId]);
            $current = $stmt->fetch();
            if (!$current) {
                throw new DataException('_db', 'This crop record no longer exists.');
            }
            $currentCropId = (int) $current['crop_id'];

            // is there already a crop with the typed name (ignoring upper/lower case)?
            $stmt = $this->pdo->prepare(
                "SELECT c.crop_id, c.crop_name, c.category_id, cc.category_name
                   FROM crop c
                   JOIN crop_category cc ON cc.category_id = c.category_id
                  WHERE lower(c.crop_name) = lower(:crop_name)"
            );
            $stmt->execute([':crop_name' => $crop_name]);
            $target = $stmt->fetch();

            if ($target && (int) $target['crop_id'] === $currentCropId) {
                // A) same crop: update its name spelling / category only if they changed
                $cropId = $currentCropId;
                if ($target['crop_name'] !== $crop_name || (int) $target['category_id'] !== $category_id) {
                    $this->updateCropRow($cropId, $crop_name, $category_id, $userId);
                }

            } elseif ($target) {
                // B) move the variety to another existing crop
                if ((int) $target['category_id'] !== $category_id) {
                    throw new DataException('category_id',
                        "\"{$target['crop_name']}\" already exists under the category \"{$target['category_name']}\". "
                        . "Choose that category, or use a different crop name.");
                }
                $cropId = (int) $target['crop_id'];

            } else {
                // C) a brand new crop name
                $stmt = $this->pdo->prepare(
                    "SELECT count(*) FROM crop_variety WHERE crop_id = :crop_id AND variety_id <> :id"
                );
                $stmt->execute([':crop_id' => $currentCropId, ':id' => $varietyId]);

                if ((int) $stmt->fetchColumn() === 0) {
                    // C1) it was the only variety: simply rename the crop
                    $cropId = $currentCropId;
                    $this->updateCropRow($cropId, $crop_name, $category_id, $userId);
                } else {
                    // C2) the crop has other varieties: create the new crop and move this variety to it
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO crop (category_id, crop_name, created_by, updated_by)
                         VALUES (:category_id, :crop_name, :created_by, :updated_by)
                         RETURNING crop_id"
                    );
                    $stmt->execute([
                        ':category_id' => $category_id,
                        ':crop_name'   => $crop_name,
                        ':created_by'  => $userId,
                        ':updated_by'  => $userId,
                    ]);
                    $cropId = (int) $stmt->fetchColumn();
                }
            }

            // the variety row: written only if something about it really changed
            $changed = $cropId !== $currentCropId
                    || (int) $current['season_id'] !== $season_id
                    || $current['variety_name'] !== $variety_name
                    || (int) $current['growing_period_days'] !== $growing_period_days;

            if ($changed) {
                $stmt = $this->pdo->prepare(
                    "UPDATE crop_variety
                        SET crop_id = :crop_id, season_id = :season_id, variety_name = :variety_name,
                            growing_period_days = :days, updated_by = :updated_by
                      WHERE variety_id = :id"
                );
                $stmt->execute([
                    ':crop_id'      => $cropId,
                    ':season_id'    => $season_id,
                    ':variety_name' => $variety_name,
                    ':days'         => $growing_period_days,
                    ':updated_by'   => $userId,
                    ':id'           => $varietyId,
                ]);
            }

            // the old crop is removed if the variety moved away and nothing is left in it
            if ($cropId !== $currentCropId) {
                $stmt = $this->pdo->prepare(
                    "DELETE FROM crop
                      WHERE crop_id = :old
                        AND NOT EXISTS (SELECT 1 FROM crop_variety v WHERE v.crop_id = crop.crop_id)"
                );
                $stmt->execute([':old' => $currentCropId]);
            }

            $this->pdo->commit();

        } catch (DataException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->translate($e);
        }
    }

    // crop table update used by updateCrop(): sets updated_by, never created_by
    private function updateCropRow(int $cropId, string $crop_name, int $category_id, int $userId): void {
        $stmt = $this->pdo->prepare(
            "UPDATE crop
                SET crop_name = :crop_name, category_id = :category_id, updated_by = :updated_by
              WHERE crop_id = :id"
        );
        $stmt->execute([
            ':crop_name'   => $crop_name,
            ':category_id' => $category_id,
            ':updated_by'  => $userId,
            ':id'          => $cropId,
        ]);
    }

    // Deletes one variety. A crop left with no varieties is deleted with it.
    // Returns false if the record does not exist. If planting records still use the
    // variety, the database refuses (ON DELETE RESTRICT) and a DataException is thrown.
    public function deleteCrop(int $varietyId): bool {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("DELETE FROM crop_variety WHERE variety_id = :id RETURNING crop_id");
            $stmt->execute([':id' => $varietyId]);
            $cropId = $stmt->fetchColumn();

            if ($cropId === false) {
                $this->pdo->rollBack();
                return false;
            }

            $stmt = $this->pdo->prepare(
                "DELETE FROM crop
                  WHERE crop_id = :crop_id
                    AND NOT EXISTS (SELECT 1 FROM crop_variety v WHERE v.crop_id = crop.crop_id)"
            );
            $stmt->execute([':crop_id' => (int) $cropId]);

            $this->pdo->commit();
            return true;

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->translate($e);
        }
    }
}