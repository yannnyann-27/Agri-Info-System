<?php
// =============================================================================
// reports/database_access.php  |  Report queries (read only)
//
// Reports: Farmers & Their Farms, Crops Planted per Farm, Plantings by Status,
// Harvest Records, Harvest Summary (total quantity + estimated value).
//
// $f is the filter array built by reports/index.php:
//   from / to (YYYY-MM-DD), search, address, irrigation, farm, crop, status, grade
// Every value goes into the query as a bound parameter, never into the SQL text, and the
// date range is checked first, so a tampered URL cannot change the query.
// =============================================================================

class Database_access {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // config/Database.php connects through Supabase's transaction pooler (port 6543).
        // That pooler does not support the server-side prepared statements PDO uses by
        // default; emulated prepares work on every port.
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    // "YYYY-MM-DD" and a real calendar date, otherwise ''
    public static function validDate(string $value): string {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : '';
    }

    private function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // [[id, label], ...] for the filter dropdowns
    private function pairs(string $sql): array {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
    }

    // search text -> LIKE pattern in which % and _ typed by the user match literally
    private function like(string $search): string {
        return '%' . addcslashes($search, '\\%_') . '%';
    }

    // " AND col >= :from AND col <= :to" for whichever dates were given
    private function dateRange(string $column, array $f): array {
        $sql = '';
        $params = [];
        if (($from = self::validDate((string) ($f['from'] ?? ''))) !== '') {
            $sql .= " AND $column >= :from";
            $params[':from'] = $from;
        }
        if (($to = self::validDate((string) ($f['to'] ?? ''))) !== '') {
            $sql .= " AND $column <= :to";
            $params[':to'] = $to;
        }
        return [$sql, $params];
    }

    // " AND col = :key" when the filter holds a positive id
    private function idFilter(string $column, array $f, string $key): array {
        $id = (int) ($f[$key] ?? 0);
        return $id > 0 ? [" AND $column = :$key", [":$key" => $id]] : ['', []];
    }

    // joins several [sql, params] parts into one
    private function parts(array ...$parts): array {
        $sql = '';
        $params = [];
        foreach ($parts as [$s, $p]) {
            $sql   .= $s;
            $params = array_merge($params, $p);
        }
        return [$sql, $params];
    }

    // -------------------------------------------------------------------------
    // Filter dropdown options
    // -------------------------------------------------------------------------
    public function getFilterOptions(): array {
        return [
            'address'    => $this->pdo->query("SELECT DISTINCT address FROM farmer ORDER BY address")->fetchAll(PDO::FETCH_COLUMN),
            'irrigation' => $this->pairs("SELECT irrigation_type_id, irrigation_type_name FROM irrigation_type ORDER BY irrigation_type_name"),
            'farm'       => $this->pairs("SELECT farm_id, farm_name FROM farm ORDER BY farm_name"),
            'crop'       => $this->pairs("SELECT crop_id, crop_name FROM crop ORDER BY crop_name"),
            'status'     => $this->pairs("SELECT status_id, status_name FROM planting_status ORDER BY status_id"),
            'grade'      => $this->pairs("SELECT grade_id, grade_name FROM quality_grade ORDER BY grade_id"),
        ];
    }

    // -------------------------------------------------------------------------
    // 1. Farmers & Their Farms: one row per farm; a farmer with no farm still appears once.
    //    Filters: search (farmer / address / farm / location), farmer address, irrigation type
    // -------------------------------------------------------------------------
    public function getFarmersReport(array $f): array {
        $params = [];
        $where  = '';
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $where .= " AND concat_ws(' ', fr.first_name, fr.last_name, fr.address, f.farm_name, f.location) ILIKE :search ESCAPE '\\'";
            $params[':search'] = $this->like($search);
        }
        $address = trim((string) ($f['address'] ?? ''));
        if ($address !== '') {
            $where .= " AND fr.address = :address";
            $params[':address'] = $address;
        }
        [$irrSql, $irrParams] = $this->idFilter('f.irrigation_type_id', $f, 'irrigation');

        return $this->fetchAll(
            "SELECT fr.farmer_id, (fr.first_name || ' ' || fr.last_name) AS farmer_name, fr.contact_number, fr.address,
                    f.farm_id, f.farm_name, f.location, f.area_hectares,
                    it.irrigation_type_name AS irrigation, st.soil_type_name AS soil
               FROM farmer fr
               LEFT JOIN farm            f  ON f.farmer_id           = fr.farmer_id
               LEFT JOIN irrigation_type it ON it.irrigation_type_id = f.irrigation_type_id
               LEFT JOIN soil_type       st ON st.soil_type_id       = f.soil_type_id
              WHERE TRUE $where $irrSql
              ORDER BY fr.last_name, fr.first_name, fr.farmer_id, f.farm_id",
            array_merge($params, $irrParams)
        );
    }

    // -------------------------------------------------------------------------
    // 2. Crops Planted per Farm: one row per planting
    //    Filters: planting date range, farm, crop, status
    // -------------------------------------------------------------------------
    public function getCropsReport(array $f): array {
        [$w, $params] = $this->parts(
            $this->dateRange('p.planting_date', $f),
            $this->idFilter('p.farm_id', $f, 'farm'),
            $this->idFilter('cv.crop_id', $f, 'crop'),
            $this->idFilter('p.status_id', $f, 'status')
        );
        return $this->fetchAll(
            "SELECT p.planting_id, f.farm_name, (fr.first_name || ' ' || fr.last_name) AS farmer_name,
                    (c.crop_name || ' (' || cv.variety_name || ')') AS crop_name,
                    p.planting_date, p.area_planted, p.seed_quantity, ps.status_name AS status
               FROM planting p
               JOIN farm            f  ON f.farm_id     = p.farm_id
               JOIN farmer          fr ON fr.farmer_id  = f.farmer_id
               JOIN crop_variety    cv ON cv.variety_id = p.variety_id
               JOIN crop            c  ON c.crop_id     = cv.crop_id
               JOIN planting_status ps ON ps.status_id  = p.status_id
              WHERE TRUE $w
              ORDER BY f.farm_name, p.planting_date, p.planting_id",
            $params
        );
    }

    // -------------------------------------------------------------------------
    // 3. Plantings by Status: every status, with how many plantings and their total area (0 if none)
    //    Filters: planting date range, farm, crop
    // -------------------------------------------------------------------------
    public function getStatusReport(array $f): array {
        [$w, $params] = $this->parts(
            $this->dateRange('p.planting_date', $f),
            $this->idFilter('p.farm_id', $f, 'farm'),
            $this->idFilter('cv.crop_id', $f, 'crop')
        );
        return $this->fetchAll(
            "SELECT ps.status_name AS status, count(p.planting_id) AS plantings,
                    COALESCE(SUM(p.area_planted), 0) AS total_area
               FROM planting_status ps
               LEFT JOIN (SELECT p.planting_id, p.status_id, p.area_planted
                            FROM planting p
                            JOIN crop_variety cv ON cv.variety_id = p.variety_id
                           WHERE TRUE $w) p ON p.status_id = ps.status_id
              GROUP BY ps.status_id, ps.status_name
              ORDER BY ps.status_id",
            $params
        );
    }

    // -------------------------------------------------------------------------
    // 4. Harvest Records: one row per harvest with its estimated value (quantity x price)
    //    Filters: harvest date range, farm, crop, quality grade
    // -------------------------------------------------------------------------
    public function getHarvestReport(array $f): array {
        [$w, $params] = $this->parts(
            $this->dateRange('h.harvest_date', $f),
            $this->idFilter('p.farm_id', $f, 'farm'),
            $this->idFilter('cv.crop_id', $f, 'crop'),
            $this->idFilter('h.grade_id', $f, 'grade')
        );
        return $this->fetchAll(
            "SELECT h.harvest_id, ('PL-' || p.planting_id || ' ' || f.farm_name || ' / ' || c.crop_name || ' (' || cv.variety_name || ')') AS planting_label,
                    h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
                    (h.quantity_kg * h.selling_price_per_kg) AS est_value,
                    qg.grade_name AS quality_grade, hr.remark_description
               FROM harvest h
               JOIN planting       p  ON p.planting_id = h.planting_id
               JOIN farm           f  ON f.farm_id     = p.farm_id
               JOIN crop_variety   cv ON cv.variety_id = p.variety_id
               JOIN crop           c  ON c.crop_id     = cv.crop_id
               JOIN quality_grade  qg ON qg.grade_id   = h.grade_id
               JOIN harvest_remark hr ON hr.remark_id  = h.remark_id
              WHERE TRUE $w
              ORDER BY h.harvest_date, h.harvest_id",
            $params
        );
    }

    // -------------------------------------------------------------------------
    // 5. Harvest Summary: total harvested quantity and estimated harvest value
    //    (SUM of quantity x selling price) grouped by crop, farm or quality grade.
    //    Filters: harvest date range, farm, crop, quality grade
    // -------------------------------------------------------------------------
    public function getHarvestSummary(array $f, string $groupBy): array {
        // only these two SQL fragments per choice are ever used, so $groupBy cannot inject SQL
        $groups = [
            'crop'  => ['c.crop_name',   'c.crop_id, c.crop_name'],
            'farm'  => ['f.farm_name',   'f.farm_id, f.farm_name'],
            'grade' => ['qg.grade_name', 'qg.grade_id, qg.grade_name'],
        ];
        [$label, $group] = $groups[$groupBy] ?? $groups['crop'];

        [$w, $params] = $this->parts(
            $this->dateRange('h.harvest_date', $f),
            $this->idFilter('p.farm_id', $f, 'farm'),
            $this->idFilter('cv.crop_id', $f, 'crop'),
            $this->idFilter('h.grade_id', $f, 'grade')
        );
        return $this->fetchAll(
            "SELECT $label AS group_name, count(*) AS harvests,
                    SUM(h.quantity_kg) AS total_kg,
                    SUM(h.quantity_kg * h.selling_price_per_kg) AS total_value
               FROM harvest h
               JOIN planting      p  ON p.planting_id = h.planting_id
               JOIN farm          f  ON f.farm_id     = p.farm_id
               JOIN crop_variety  cv ON cv.variety_id = p.variety_id
               JOIN crop          c  ON c.crop_id     = cv.crop_id
               JOIN quality_grade qg ON qg.grade_id   = h.grade_id
              WHERE TRUE $w
              GROUP BY $group
              ORDER BY total_value DESC, group_name",
            $params
        );
    }
}
