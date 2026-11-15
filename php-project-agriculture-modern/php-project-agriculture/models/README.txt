models/database_access.php holds the read-only dashboard queries.
Each module keeps its own SQL in <module>/database_access.php (farmers, farms, crops, plantings, harvests, reports).
includes/sample_data.php is no longer used by any page.
Database credentials are NOT stored in code: see .env.example and config/Database.php.
