<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/models/database_access.php';
$root = ''; $page_title = 'Dashboard'; $active = 'dashboard';

$db = new Database_access((new Database())->connect());

// everything on this page comes from the database
$counts = ['farmers' => 0, 'farms' => 0, 'crops' => 0, 'plantings' => 0, 'harvests' => 0];
$status_count = []; $total_kg = 0; $recent_plantings = []; $recent_harvests = [];

try {
    $d = $db->getDashboard();
    $counts           = $d['counts'];
    $status_count     = $d['status_count'];
    $total_kg         = $d['total_kg'];
    $recent_plantings = $d['recent_plantings'];
    $recent_harvests  = $d['recent_harvests'];
} catch (PDOException $e) {
    error_log('dashboard failed: ' . $e->getMessage());   // details go to the server log only
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'The dashboard could not be loaded. Please try again.'];
}

$cards = [
    ['Farmers',   $counts['farmers'],   'farmers/index.php',   'users',    'bg-green-100 text-green-700'],
    ['Farms',     $counts['farms'],     'farms/index.php',     'map',      'bg-blue-100 text-blue-700'],
    ['Crops',     $counts['crops'],     'crops/index.php',     'sun',      'bg-yellow-100 text-yellow-700'],
    ['Plantings', $counts['plantings'], 'plantings/index.php', 'calendar', 'bg-purple-100 text-purple-700'],
    ['Harvests',  $counts['harvests'],  'harvests/index.php',  'archive',  'bg-orange-100 text-orange-700'],
];
$total_plantings = $counts['plantings'];
$status_colors = ['Planted' => 'bg-blue-500', 'Growing' => 'bg-yellow-500', 'Harvested' => 'bg-green-500', 'Failed' => 'bg-red-500'];

include 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>. Here is what is happening on the farms.</p>
    </div>
</div>

<div class="stagger">

<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4 mb-4 sm:mb-5" style="animation:none">
    <?php foreach ($cards as $n => [$label, $count, $href, $ico, $color]): ?>
        <a href="<?= $href ?>" class="card stat" style="animation:rise .6s var(--ease-out) both;animation-delay:<?= $n * 70 + 60 ?>ms">
            <div class="stat-chip <?= $color ?>"><?php icon($ico, 'w-6 h-6'); ?></div>
            <span class="stat-go"><?php icon('arrow-ur', 'w-5 h-5'); ?></span>
            <p class="stat-num" data-count="<?= (int) $count ?>"><?= $count ?></p>
            <p class="stat-label">Total <?= $label ?></p>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4 sm:gap-5 mb-4 sm:mb-5">
    <div class="card card-pad">
        <h2 class="card-title">Plantings by Status</h2>
        <?php foreach ($status_count as $status => $n): ?>
            <div class="mb-4 last:mb-0">
                <div class="flex justify-between items-center text-sm mb-1.5"><span><?= badge($status) ?></span><span class="tabular font-semibold"><?= $n ?></span></div>
                <div class="track">
                    <div class="fill <?= $status_colors[$status] ?? 'bg-gray-500' ?>" style="width: <?= ($n / max(1, $total_plantings)) * 100 ?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card harvest-hero">
        <div class="furrows"></div>
        <p style="color:rgba(255,255,255,.7);font-size:.9rem;margin:0 0 .5rem">Total Harvested Quantity</p>
        <p class="harvest-num tabular" data-count="<?= (float) $total_kg ?>" data-decimals="2"><?= number_format($total_kg, 2) ?></p>
        <p style="color:rgba(255,255,255,.7);font-size:.9rem;margin:.4rem 0 0">kilograms</p>
    </div>

    <div class="card card-pad">
        <h2 class="card-title">Quick Actions</h2>
        <div class="flex flex-col gap-2.5">
            <a href="plantings/create.php" class="btn btn-primary btn-block"><?php icon('plus', 'w-4 h-4'); ?> New Planting</a>
            <a href="harvests/create.php" class="btn btn-primary btn-block"><?php icon('plus', 'w-4 h-4'); ?> New Harvest</a>
            <a href="reports/index.php" class="btn btn-dark btn-block"><?php icon('chart', 'w-4 h-4'); ?> View Reports</a>
        </div>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-5">
    <div class="card">
        <h2 class="card-title" style="padding:1.25rem 1.25rem 0">Recent Planting Records</h2>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Farm</th><th>Crop</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent_plantings as $row): ?>
                <tr>
                    <td class="font-medium"><?= htmlspecialchars($row['farm_name']) ?></td>
                    <td><?= htmlspecialchars($row['crop_name']) ?></td>
                    <td class="td-num"><?= htmlspecialchars($row['planting_date']) ?></td>
                    <td><?= badge($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title" style="padding:1.25rem 1.25rem 0">Recent Harvest Records</h2>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Crop</th><th>Date</th><th>Qty (kg)</th><th>Grade</th></tr></thead>
            <tbody>
            <?php foreach ($recent_harvests as $row): ?>
                <tr>
                    <td class="font-medium"><?= htmlspecialchars($row['crop_name']) ?></td>
                    <td class="td-num"><?= htmlspecialchars($row['harvest_date']) ?></td>
                    <td class="td-num"><?= number_format($row['quantity_kg'], 2) ?></td>
                    <td><?= badge($row['quality_grade']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div><!-- /.stagger -->
<?php include 'includes/footer.php'; ?>
