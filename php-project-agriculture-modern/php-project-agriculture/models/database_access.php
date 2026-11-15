<?php
// =============================================================================
// models/database_access.php  |  Dashboard queries (read only)
// =============================================================================

class Database_access {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        // config/Database.php connects through Supabase's transaction pooler (port 6543).
        // That pooler does not support the server-side prepared statements PDO uses by
        // default, which makes queries fail at random. Emulated prepares make PDO put the
        // (safely escaped) values into the query on the PHP side instead, so every query
        // below works on port 6543 and on the session pooler (5432) alike.
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    }

    // Everything the dashboard shows, in one call:
    //   counts           farmers / farms / crops / plantings / harvests
    //                    ("crops" counts crop varieties, the rows listed on the Crops page)
    //   status_count     [status name => number of plantings], only statuses that are in use
    //   total_kg         total harvested quantity
    //   recent_plantings the 5 newest plantings
    //   recent_harvests  the 5 newest harvests
    public function getDashboard(): array {
        $counts = $this->pdo->query(
            "SELECT (SELECT count(*) FROM farmer)       AS farmers,
                    (SELECT count(*) FROM farm)         AS farms,
                    (SELECT count(*) FROM crop_variety) AS crops,
                    (SELECT count(*) FROM planting)     AS plantings,
                    (SELECT count(*) FROM harvest)      AS harvests"
        )->fetch();

        $status_count = [];
        $stmt = $this->pdo->query(
            "SELECT ps.status_name, count(*) AS n
               FROM planting p
               JOIN planting_status ps ON ps.status_id = p.status_id
              GROUP BY ps.status_id, ps.status_name
              ORDER BY ps.status_id"
        );
        foreach ($stmt->fetchAll() as $r) {
            $status_count[$r['status_name']] = (int) $r['n'];
        }

        $total_kg = $this->pdo->query("SELECT COALESCE(SUM(quantity_kg), 0) FROM harvest")->fetchColumn();

        $recent_plantings = $this->pdo->query(
            "SELECT f.farm_name, (c.crop_name || ' (' || cv.variety_name || ')') AS crop_name, p.planting_date, ps.status_name AS status
               FROM planting p
               JOIN farm            f  ON f.farm_id     = p.farm_id
               JOIN crop_variety    cv ON cv.variety_id = p.variety_id
               JOIN crop            c  ON c.crop_id     = cv.crop_id
               JOIN planting_status ps ON ps.status_id  = p.status_id
              ORDER BY p.planting_id DESC
              LIMIT 5"
        )->fetchAll();

        $recent_harvests = $this->pdo->query(
            "SELECT (c.crop_name || ' (' || cv.variety_name || ')') AS crop_name, h.harvest_date, h.quantity_kg, qg.grade_name AS quality_grade
               FROM harvest h
               JOIN planting      p  ON p.planting_id = h.planting_id
               JOIN crop_variety  cv ON cv.variety_id = p.variety_id
               JOIN crop          c  ON c.crop_id     = cv.crop_id
               JOIN quality_grade qg ON qg.grade_id   = h.grade_id
              ORDER BY h.harvest_id DESC
              LIMIT 5"
        )->fetchAll();

        return [
            'counts'           => array_map('intval', $counts),
            'status_count'     => $status_count,
            'total_kg'         => $total_kg,
            'recent_plantings' => $recent_plantings,
            'recent_harvests'  => $recent_harvests,
        ];
    }
}
