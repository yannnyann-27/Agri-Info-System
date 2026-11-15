<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$root = '../'; $page_title = 'Crops'; $active = 'crops';

$db = new Database_access((new Database())->connect());

$search = (string) trim($_GET['search'] ?? '');
$filter = (string) trim($_GET['filter'] ?? '');          // a category_id
$sort   = (string) ($_GET['sort'] ?? 'variety_id');
$dir    = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

// each row of this screen is one crop variety, so the ID column is variety_id
$columns = [
    ['variety_id', 'ID'],
    ['crop_name', 'Crop'],
    ['variety', 'Variety'],
    ['crop_category', 'Category'],
    ['growing_period_days', 'Days'],
    ['recommended_season', 'Season'],
];

$per_page = 5;
$rows = []; $total = 0; $pages = 1; $page = 1; $filter_options = [];

try {
    $filter_options = $db->getCropCategories();           // [[category_id, name], ...]

    // search + filter + sorting + pagination are all done by the query
    $result = $db->listCrops($search, $filter, $sort, $dir, (int) ($_GET['page'] ?? 1), $per_page);
    $rows   = $result['rows'];
    $total  = $result['total'];
    $pages  = $result['pages'];
    $page   = $result['page'];
} catch (PDOException $e) {
    error_log('crops list failed: ' . $e->getMessage());   // details go to the server log only
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'The crops could not be loaded. Please try again.'];
}

$query = fn($extra) => '?' . http_build_query(array_merge(['search' => $search, 'filter' => $filter, 'sort' => $sort, 'dir' => $dir], $extra));

include '../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Crops</h1>
        <p class="page-sub"><?= $total ?> record(s) in total</p>
    </div>
    <a href="create.php" class="btn btn-primary">
        <?php icon('plus', 'w-4 h-4'); ?> Add Crop
    </a>
</div>

<form method="GET" class="card toolbar">
    <div class="search-wrap">
        <?php icon('search', 'w-5 h-5'); ?>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." class="input" aria-label="Search">
        <span class="kbd hidden sm:inline">/</span>
    </div>
    <select name="filter" class="select" aria-label="Filter">
        <option value="">All Category</option>
        <?php foreach ($filter_options as [$val, $text]): ?>
            <option value="<?= htmlspecialchars($val) ?>" <?= $filter === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars($text) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Search</button>
    <a href="index.php" class="btn btn-secondary">Reset</a>
</form>

<div class="card table-wrap">
    <table class="data">
        <thead>
            <tr>
                <?php foreach ($columns as [$key, $label]): ?>
                    <th <?= $sort === $key ? 'aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                        <a href="<?= $query(['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc', 'page' => 1]) ?>" class="sort-link <?= $sort === $key ? 'on' : '' ?>">
                            <?= $label ?> <?php icon($sort === $key ? ($dir === 'asc' ? 'up' : 'down') : 'updown', 'w-3.5 h-3.5'); ?>
                        </a>
                    </th>
                <?php endforeach; ?>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody class="rows-in">
        <?php if (!$rows): ?>
            <tr><td colspan="<?= count($columns) + 1 ?>"><div class="empty"><div class="empty-icon"><?php icon('inbox', 'w-7 h-7'); ?></div><p style="margin:0;font-weight:600;color:var(--ink)">No records found.</p><p style="margin:.25rem 0 0;font-size:.88rem">Try a different search or reset the filters.</p></div></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php foreach ($columns as [$key, $label]): ?>
                    <td class="<?= cell_class($key, $row[$key]) ?>"><?= render_cell($key, $row[$key]) ?></td>
                <?php endforeach; ?>
                <td>
                    <div class="row-actions">
                        <a href="view.php?id=<?= $row['variety_id'] ?>" title="View" aria-label="View" class="act act-view"><?php icon('eye'); ?></a>
                        <a href="edit.php?id=<?= $row['variety_id'] ?>" title="Edit" aria-label="Edit" class="act act-edit"><?php icon('pencil'); ?></a>
                        <?php if (is_admin()): ?>
                            <form method="POST" action="delete.php" class="delete-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $row['variety_id'] ?>"><button type="submit" title="Delete" aria-label="Delete" data-confirm="Are you sure you want to delete this record?" class="act act-del"><?php icon('trash'); ?></button></form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm">
    <span style="color:var(--muted)">Showing <?= count($rows) ?> of <?= $total ?> record(s)</span>
    <div class="pager">
        <?php if ($page > 1): ?><a href="<?= $query(['page' => $page - 1]) ?>" class="page-btn"><?php icon('left', 'w-4 h-4'); ?> Prev</a><?php endif; ?>
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="<?= $query(['page' => $i]) ?>" class="page-btn <?= $i === $page ? 'current' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="<?= $query(['page' => $page + 1]) ?>" class="page-btn">Next <?php icon('right', 'w-4 h-4'); ?></a><?php endif; ?>
    </div>
</div>
<?php include '../includes/footer.php'; ?>