<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    // works from any folder depth
    $prefix = (basename(dirname($_SERVER['SCRIPT_FILENAME'])) === basename(dirname(__DIR__))) ? '' : '../';
    header("Location: {$prefix}auth/login.php");
    exit;
}

require_once __DIR__ . '/csrf.php';

// The role comes from role.role_name ('Administrator' or 'Staff').
function is_admin(): bool {
    return in_array(strtolower((string) ($_SESSION['role'] ?? '')), ['admin', 'administrator'], true);
}
