<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/database_access.php';
$root = '../'; $page_title = 'Reports'; $active = 'reports';

// -----------------------------------------------------------------------------
// The reports. cols = [row key, column heading, type]
//   types: text, date, badge, num (2 decimals), int, money (pesos), share (% bar)
//   filters: which filter controls the report shows
// -----------------------------------------------------------------------------
$reports = [
    'farmers' => [
        'title'   => 'Farmers & Their Farms',
        'sub'     => 'Every farmer with the farms they own',
        'filters' => ['search', 'address', 'irrigation'],
        'cols'    => [['farmer_name', 'Farmer', 'text'], ['contact_number', 'Contact', 'text'], ['address', 'Address', 'text'],
                      ['farm_name', 'Farm', 'text'], ['location', 'Farm Location', 'text'], ['irrigation', 'Irrigation', 'text'],
                      ['soil', 'Soil', 'text'], ['area_hectares', 'Area (ha)', 'num']],
    ],
    'crops' => [
        'title'   => 'Crops Planted per Farm',
        'sub'     => 'Every planting record, grouped by farm',
        'filters' => ['date', 'farm', 'crop', 'status'],
        'cols'    => [['farm_name', 'Farm', 'text'], ['farmer_name', 'Farmer', 'text'], ['crop_name', 'Crop', 'text'],
                      ['planting_date', 'Planting Date', 'date'], ['area_planted', 'Area (ha)', 'num'],
                      ['seed_quantity', 'Seed Qty', 'num'], ['status', 'Status', 'badge']],
    ],
    'status' => [
        'title'   => 'Plantings by Status',
        'sub'     => 'Number of plantings and planted area for each status',
        'filters' => ['date', 'farm', 'crop'],
        'cols'    => [['status', 'Status', 'badge'], ['plantings', 'No. of Plantings', 'int'], ['total_area', 'Total Area (ha)', 'num']],
    ],
    'harvests' => [
        'title'   => 'Harvest Records',
        'sub'     => 'Every harvest with its estimated value (quantity x selling price)',
        'filters' => ['date', 'farm', 'crop', 'grade'],
        'cols'    => [['planting_label', 'Planting', 'text'], ['harvest_date', 'Date', 'date'], ['quantity_kg', 'Qty (kg)', 'num'],
                      ['selling_price_per_kg', 'Price/kg', 'num'], ['est_value', 'Est. Value', 'money'],
                      ['quality_grade', 'Grade', 'badge'], ['remark_description', 'Remarks', 'text']],
    ],
    'summary' => [
        'title'   => 'Harvest Summary',
        'sub'     => 'Total harvested quantity and estimated harvest value',
        'filters' => ['date', 'farm', 'crop', 'grade', 'group'],
        'cols'    => [['group_name', 'Crop', 'text'], ['harvests', 'Harvests', 'int'], ['total_kg', 'Total Qty (kg)', 'num'],
                      ['total_value', 'Est. Value', 'money'], ['share', 'Share of Value', 'share']],
    ],
];

$report = (string) ($_GET['report'] ?? 'farmers');
if (!isset($reports[$report])) $report = 'farmers';

$groups = ['crop' => 'Crop', 'farm' => 'Farm', 'grade' => 'Quality Grade'];
$group  = (string) ($_GET['group'] ?? 'crop');
if (!isset($groups[$group])) $group = 'crop';
$reports['summary']['cols'][0][1] = $groups[$group];
$def = $reports[$report];

// -----------------------------------------------------------------------------
// Filters (only the ones this report uses are kept)
// -----------------------------------------------------------------------------
$select_filters = ['address' => 'Farmer Address', 'irrigation' => 'Irrigation', 'farm' => 'Farm', 'crop' => 'Crop',
                   'status' => 'Status', 'grade' => 'Quality Grade'];
$flt = [];
foreach (['from', 'to', 'search', 'address', 'irrigation', 'farm', 'crop', 'status', 'grade'] as $k) {
    $v = $_GET[$k] ?? '';
    $flt[$k] = is_string($v) ? trim($v) : '';
}
if (!in_array('date', $def['filters'], true)) { $flt['from'] = ''; $flt['to'] = ''; }
foreach (array_merge(['search'], array_keys($select_filters)) as $k) {
    if (!in_array($k, $def['filters'], true)) $flt[$k] = '';
}

$filter_error = '';
foreach (['from' => 'From', 'to' => 'To'] as $k => $label) {
    if ($filter_error === '' && $flt[$k] !== '' && Database_access::validDate($flt[$k]) === '') {
        $filter_error = "The $label date is not a valid date.";
    }
}
if ($filter_error === '' && $flt['from'] !== '' && $flt['to'] !== '' && $flt['from'] > $flt['to']) {
    $filter_error = 'The From date cannot be later than the To date.';
}

// -----------------------------------------------------------------------------
// Load the data: every report comes from a database query
// -----------------------------------------------------------------------------
$db = new Database_access((new Database())->connect());
$options = []; $rows = []; $load_error = false;
try {
    $options = $db->getFilterOptions();
    if ($filter_error === '') {
        switch ($report) {
            case 'farmers':  $rows = $db->getFarmersReport($flt); break;
            case 'crops':    $rows = $db->getCropsReport($flt); break;
            case 'status':   $rows = $db->getStatusReport($flt); break;
            case 'harvests': $rows = $db->getHarvestReport($flt); break;
            default:         $rows = $db->getHarvestSummary($flt, $group);
        }
    }
} catch (PDOException $e) {
    error_log('report failed: ' . $e->getMessage());   // details go to the server log only
    $load_error = true;
    $rows = [];
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'The report could not be loaded. Please try again.'];
}

// -----------------------------------------------------------------------------
// Summary cards (totals of exactly the rows shown)
// -----------------------------------------------------------------------------
$sum = fn(string $key) => array_sum(array_map(fn($r) => (float) $r[$key], $rows));
$cards = [];
switch ($report) {
    case 'farmers':
        $farmer_ids = []; $farm_n = 0; $area = 0.0;
        foreach ($rows as $r) {
            $farmer_ids[$r['farmer_id']] = true;
            if ($r['farm_id'] !== null) { $farm_n++; $area += (float) $r['area_hectares']; }
        }
        $cards = [['Farmers', number_format(count($farmer_ids))], ['Farms', number_format($farm_n)],
                  ['Total Farm Area', number_format($area, 2) . ' ha']];
        break;
    case 'crops':
        $cards = [['Planting Records', number_format(count($rows))], ['Total Area Planted', number_format($sum('area_planted'), 2) . ' ha']];
        break;
    case 'status':
        $cards = [['Total Plantings', number_format($sum('plantings'))], ['Total Area', number_format($sum('total_area'), 2) . ' ha']];
        break;
    case 'harvests':
        $cards = [['Harvest Records', number_format(count($rows))],
                  ['Total Harvested Quantity', number_format($sum('quantity_kg'), 2) . ' kg'],
                  ['Estimated Harvest Value', "\u{20B1}" . number_format($sum('est_value'), 2)]];
        break;
    default:
        $grand = $sum('total_value');
        foreach ($rows as &$r) {
            $r['share'] = $grand > 0 ? ((float) $r['total_value'] / $grand) * 100 : 0;
        }
        unset($r);
        $cards = [['Harvest Records', number_format($sum('harvests'))],
                  ['Total Harvested Quantity', number_format($sum('total_kg'), 2) . ' kg'],
                  ['Estimated Harvest Value', "\u{20B1}" . number_format($grand, 2)]];
}

// -----------------------------------------------------------------------------
// Cell formatting (HTML and CSV)
// -----------------------------------------------------------------------------
function rpt_cell(string $type, $v): string {
    if ($v === null || $v === '') return '<span style="color:var(--muted)">&mdash;</span>';
    switch ($type) {
        case 'badge': return badge((string) $v);
        case 'num':   return number_format((float) $v, 2);
        case 'int':   return number_format((int) $v);
        case 'money': return '&#8369;' . number_format((float) $v, 2);
        case 'share':
            $p = max(0, min(100, (float) $v));
            return '<div class="bar-cell"><span class="tabular text-sm">' . number_format($p, 1) . '%</span>'
                 . '<div class="track"><div class="fill bg-green-500" style="width:' . $p . '%"></div></div></div>';
        default:      return htmlspecialchars((string) $v);
    }
}

function rpt_csv(string $type, $v): string {
    if ($v === null) return '';
    switch ($type) {
        case 'num': case 'money': return number_format((float) $v, 2, '.', '');
        case 'int':               return (string) (int) $v;
        case 'share':             return number_format((float) $v, 1, '.', '');
        default:
            $s = (string) $v;
            // a cell that starts with = + - @ would run as a formula in Excel
            if ($s !== '' && strpos('=+-@', $s[0]) !== false) $s = "'" . $s;
            return $s;
    }
}

// -----------------------------------------------------------------------------
// CSV export (same filters, same rows). Must run before any HTML is sent.
// -----------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv' && $filter_error === '' && !$load_error) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $report . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                                  // so Excel reads UTF-8 correctly
    fputcsv($out, array_column($def['cols'], 1), ',', '"', '\\');
    foreach ($rows as $r) {
        $line = [];
        foreach ($def['cols'] as [$key, $heading, $type]) {
            $line[] = rpt_csv($type, $r[$key] ?? null);
        }
        fputcsv($out, $line, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// -----------------------------------------------------------------------------
// Helpers for the page
// -----------------------------------------------------------------------------
// the text behind a selected dropdown value
$opt_text = function (string $key, string $value) use ($options): string {
    if ($key === 'address') return $value;
    foreach ($options[$key] ?? [] as [$id, $text]) {
        if ((string) $id === $value) return (string) $text;
    }
    return $value;
};

// readable list of the filters that are applied (shown when printing)
$applied = [];
if ($flt['from'] !== '')   $applied[] = 'From ' . $flt['from'];
if ($flt['to'] !== '')     $applied[] = 'To ' . $flt['to'];
if ($flt['search'] !== '') $applied[] = 'Search: ' . $flt['search'];
foreach ($select_filters as $k => $label) {
    if ($flt[$k] !== '') $applied[] = $label . ': ' . $opt_text($k, $flt[$k]);
}
if ($report === 'summary') $applied[] = 'Grouped by ' . $groups[$group];

// the URL parameters of the current view (for the CSV link)
$current = ['report' => $report];
foreach ($flt as $k => $v) if ($v !== '') $current[$k] = $v;
if ($report === 'summary') $current['group'] = $group;
$export_url = '?' . http_build_query($current + ['export' => 'csv']);
$print_url  = '?' . http_build_query($current + ['all' => 1, 'autoprint' => 1]);

// Pagination: the screen shows 10 rows per page. Totals, CSV export and the print view always use ALL rows.
$per_page   = 10;
$show_all   = isset($_GET['all']);
$total_rows = count($rows);
$pages      = max(1, (int) ceil($total_rows / $per_page));
$page       = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$page_rows  = $show_all ? $rows : array_slice($rows, ($page - 1) * $per_page, $per_page);
$first_row  = $total_rows === 0 ? 0 : ($show_all ? 1 : ($page - 1) * $per_page + 1);
$last_row   = $show_all ? $total_rows : min($total_rows, $page * $per_page);
$page_url   = fn(int $n) => '?' . http_build_query($current + ['page' => $n]);

// page numbers to show: first, last and the ones around the current page (with "..." between)
$page_list = [];
for ($i = 1; $i <= $pages; $i++) {
    if ($i === 1 || $i === $pages || abs($i - $page) <= 2) $page_list[] = $i;
}

$generated = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('F j, Y g:i A');

include '../includes/header.php';
?>
<style>
    /* numeric columns: header and cells are right-aligned together so the numbers sit under their heading */
    table.data thead th.col-num,
    table.data tbody td.col-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; padding-right: 1.6rem; }
</style>
<div class="page-head">
    <div>
        <h1 class="page-title">Reports</h1>
        <p class="page-sub"><?= htmlspecialchars($def['title']) ?> &mdash; <?= htmlspecialchars($def['sub']) ?></p>
    </div>
    <div class="flex flex-wrap gap-2 no-print">
        <?php if ($filter_error === '' && !$load_error): ?>
            <a href="<?= htmlspecialchars($export_url) ?>" class="btn btn-secondary"><?php icon('down', 'w-4 h-4'); ?> Export CSV</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($print_url) ?>" target="_blank" rel="noopener" class="btn btn-dark"><?php icon('printer', 'w-4 h-4'); ?> Print Report</a>
    </div>
</div>

<div class="print-only">
    <h1>Agriculture Information System &mdash; <?= htmlspecialchars($def['title']) ?></h1>
    <p>Generated <?= htmlspecialchars($generated) ?> by <?= htmlspecialchars($_SESSION['username']) ?></p>
    <p>Filters: <?= $applied ? htmlspecialchars(implode(' | ', $applied)) : 'none (all records)' ?></p>
</div>

<div class="seg seg-reports">
    <?php foreach ($reports as $key => $r): ?>
        <?php
            $tab = ['report' => $key];
            if (in_array('date', $r['filters'], true)) {
                if ($flt['from'] !== '') $tab['from'] = $flt['from'];
                if ($flt['to'] !== '')   $tab['to']   = $flt['to'];
            }
        ?>
        <a href="?<?= htmlspecialchars(http_build_query($tab)) ?>" class="<?= $report === $key ? 'on' : '' ?>"><?= htmlspecialchars($r['title']) ?></a>
    <?php endforeach; ?>
</div>

<form method="GET" class="card toolbar rpt-filters no-print" style="align-items:flex-end">
    <input type="hidden" name="report" value="<?= htmlspecialchars($report) ?>">
    <?php foreach ($def['filters'] as $fk): ?>
        <?php if ($fk === 'date'): ?>
            <div class="field"><label class="field-label" for="f-from">From</label><input id="f-from" type="date" name="from" value="<?= htmlspecialchars($flt['from']) ?>" class="input"></div>
            <div class="field"><label class="field-label" for="f-to">To</label><input id="f-to" type="date" name="to" value="<?= htmlspecialchars($flt['to']) ?>" class="input"></div>
        <?php elseif ($fk === 'search'): ?>
            <div class="field"><label class="field-label" for="f-search">Search</label><input id="f-search" type="text" name="search" value="<?= htmlspecialchars($flt['search']) ?>" placeholder="Farmer, farm, address..." class="input"></div>
        <?php elseif ($fk === 'group'): ?>
            <div class="field"><label class="field-label" for="f-group">Group by</label>
                <select id="f-group" name="group" class="select">
                    <?php foreach ($groups as $gk => $gl): ?>
                        <option value="<?= $gk ?>" <?= $group === $gk ? 'selected' : '' ?>><?= $gl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="field"><label class="field-label" for="f-<?= $fk ?>"><?= $select_filters[$fk] ?></label>
                <select id="f-<?= $fk ?>" name="<?= $fk ?>" class="select">
                    <option value="">All</option>
                    <?php foreach ($options[$fk] ?? [] as $opt): ?>
                        <?php $val = $fk === 'address' ? $opt : $opt[0]; $text = $fk === 'address' ? $opt : $opt[1]; ?>
                        <option value="<?= htmlspecialchars((string) $val) ?>" <?= $flt[$fk] === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars((string) $text) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary">Apply Filters</button>
    <a href="?report=<?= htmlspecialchars($report) ?>" class="btn btn-secondary">Reset</a>
</form>

<?php if ($filter_error !== ''): ?>
    <div class="alert alert-error" role="alert"><?php icon('alert', 'w-5 h-5'); ?> <?= htmlspecialchars($filter_error) ?></div>
<?php endif; ?>

<?php if ($filter_error === '' && !$load_error): ?>
<div class="rpt-cards stagger">
    <?php foreach ($cards as [$label, $value]): ?>
        <div class="card rpt-card"><p class="lbl"><?= htmlspecialchars($label) ?></p><p class="val font-display tabular"><?= htmlspecialchars($value) ?></p></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card table-wrap">
<table class="data">
    <thead>
        <tr>
            <?php foreach ($def['cols'] as [$key, $heading, $type]): ?>
                <th class="<?= in_array($type, ['num', 'int', 'money'], true) ? 'col-num' : '' ?>"><?= htmlspecialchars($heading) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody class="rows-in">
    <?php if (!$rows): ?>
        <tr><td colspan="<?= count($def['cols']) ?>"><div class="empty"><div class="empty-icon"><?php icon('inbox', 'w-7 h-7'); ?></div>
            <p style="margin:0;font-weight:600;color:var(--ink)">No records found.</p>
            <p style="margin:.25rem 0 0;font-size:.88rem">Try a different date range or reset the filters.</p></div></td></tr>
    <?php endif; ?>
    <?php foreach ($page_rows as $r): ?>
        <tr>
            <?php foreach ($def['cols'] as $i => [$key, $heading, $type]): ?>
                <td class="<?= $i === 0 ? 'font-medium' : '' ?> <?= in_array($type, ['num', 'int', 'money'], true) ? 'col-num' : '' ?>"><?= rpt_cell($type, $r[$key] ?? null) ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($show_all): ?>
    <p class="no-print" style="color:var(--muted);font-size:.85rem;margin-top:.75rem">Showing all <?= $total_rows ?> row(s) for printing. <a href="<?= htmlspecialchars('?' . http_build_query($current)) ?>" style="text-decoration:underline;font-weight:600">Back to paged view</a></p>
<?php else: ?>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm no-print">
        <span style="color:var(--muted)">Showing <?= $first_row ?>&ndash;<?= $last_row ?> of <?= $total_rows ?> row(s). Totals above include all <?= $total_rows ?> row(s). <strong>Export CSV</strong> and <strong>Print Report</strong> include every row.</span>
        <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?><a href="<?= htmlspecialchars($page_url($page - 1)) ?>" class="page-btn"><?php icon('left', 'w-4 h-4'); ?> Prev</a><?php endif; ?>
            <?php $prev = 0; foreach ($page_list as $n): ?>
                <?php if ($n - $prev > 1): ?><span class="page-btn" style="border-color:transparent;background:transparent">&hellip;</span><?php endif; ?>
                <a href="<?= htmlspecialchars($page_url($n)) ?>" class="page-btn <?= $n === $page ? 'current' : '' ?>"><?= $n ?></a>
                <?php $prev = $n; ?>
            <?php endforeach; ?>
            <?php if ($page < $pages): ?><a href="<?= htmlspecialchars($page_url($page + 1)) ?>" class="page-btn">Next <?php icon('right', 'w-4 h-4'); ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($show_all && isset($_GET['autoprint'])): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>