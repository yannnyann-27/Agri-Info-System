<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$root = '../'; $page_title = 'View Planting'; $active = 'plantings';

$db = new Database_access((new Database())->connect());

$id  = (int) ($_GET['id'] ?? 0);
try {
    $row = $db->getPlanting($id);
} catch (PDOException $e) {
    error_log('getPlanting failed: ' . $e->getMessage());
    $row = null;
}
if (!$row) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Record not found.'];
    header("Location: index.php");
    exit;
}

// the ids are only needed by the edit form, so they are not shown on the details page
$display = array_diff_key($row, array_flip(['farm_id', 'variety_id', 'status_id']));
include '../includes/header.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="page-head"><h1 class="page-title">Planting Details</h1></div>
    <dl class="card card-pad detail-grid">
        <?php foreach ($display as $key => $value): ?>
            <div class="detail-item">
                <dt><?= htmlspecialchars(ucwords(str_replace('_', ' ', $key))) ?></dt>
                <dd><?= render_cell((string) $key, $value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <div class="flex flex-wrap gap-2 mt-4">
        <a href="index.php" class="btn btn-secondary"><?php icon('back', 'w-4 h-4'); ?> Back</a>
        <a href="edit.php?id=<?= $row['planting_id'] ?>" class="btn btn-gold"><?php icon('pencil', 'w-4 h-4'); ?> Edit</a>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
