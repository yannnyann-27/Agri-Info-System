<?php
// =============================================================================
// config/Database.php  |  PostgreSQL (Supabase) connection
//
// No credentials live in the source code. They are read from environment
// variables, which can come from either:
//   1) the real environment (Docker --env-file, hosting dashboard, Apache SetEnv), or
//   2) a local ".env" file in the project root (copy ".env.example" to ".env").
//      ".env" is listed in .gitignore and .dockerignore, so it is never pushed or
//      baked into a Docker image.
// A real environment variable always wins over the same key in ".env".
//
// Required keys: DB_HOST, DB_USER, DB_PASSWORD
// Optional keys: DB_PORT (default 5432), DB_NAME (default postgres),
//                DB_SSLMODE (default require)
// =============================================================================

// Never show PHP errors or warnings to visitors (they can reveal file paths and code).
// They go to the server's error log instead. Every page loads this file, so this covers the whole system.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

class Database {

    // Reads KEY=VALUE lines from the project's .env file (if there is one).
    // Blank lines and lines starting with # are ignored; surrounding quotes are removed.
    private static function loadEnvFile(): array {
        $file = dirname(__DIR__) . '/.env';
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[trim($key)] = $value;
        }
        return $values;
    }

    // One setting: real environment first, then .env, then the default.
    private static function setting(array $env, string $key, ?string $default = null): ?string {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        return (isset($env[$key]) && $env[$key] !== '') ? $env[$key] : $default;
    }

    public function connect(): PDO {
        $env = self::loadEnvFile();

        $host     = self::setting($env, 'DB_HOST');
        $port     = self::setting($env, 'DB_PORT', '5432');
        $dbname   = self::setting($env, 'DB_NAME', 'postgres');
        $username = self::setting($env, 'DB_USER');
        $password = self::setting($env, 'DB_PASSWORD');
        $sslmode  = self::setting($env, 'DB_SSLMODE', 'require');

        $missing = [];
        foreach (['DB_HOST' => $host, 'DB_USER' => $username, 'DB_PASSWORD' => $password] as $key => $value) {
            if ($value === null) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            // names of the missing keys go to the server log only, never to the browser
            error_log('Database configuration missing: ' . implode(', ', $missing)
                . '. Copy .env.example to .env and fill it in, or set them as environment variables.');
            $this->fail();
        }

        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Supabase's transaction pooler (port 6543) does not support the server-side
            // prepared statements PDO uses by default; emulated prepares work on every port.
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            return $pdo;
        } catch (PDOException $e) {
            // the real reason (host, user, ...) is logged, not shown to the visitor
            error_log('Database connection failed: ' . $e->getMessage());
            $this->fail();
        }
    }

    private function fail(): never {
        http_response_code(500);
        die('The system could not connect to the database. Please try again later.');
    }
}