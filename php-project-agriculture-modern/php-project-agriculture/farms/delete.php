<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';

// the login stores the role name from the database ("Administrator"); the old "admin" is accepted too
$is_admin = in_array(strtolower((string) $_SESSION['role']), ['admin', 'administrator'], true);

// Only Admin can delete
if (!$is_admin) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only an administrator can delete records.'];
    header("Location: index.php");
    exit;
}

// Deleting changes data, so it only accepts a POST that carries the session's CSRF token
// (a link, a prefetch or a forged request from another site cannot delete anything).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid or expired request. Please try again.'];
    header("Location: index.php");
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

try {
    $db = new Database_access((new Database())->connect());
    if ($db->deleteFarm($id)) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Farm deleted successfully.'];
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Record not found.'];
    }
} catch (DataException $e) {
    // e.g. the record is still used by other records (ON DELETE RESTRICT)
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
} catch (PDOException $e) {
    error_log('deleteFarm failed: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'The farm could not be deleted. Please try again.'];
}
header("Location: index.php");
exit;
