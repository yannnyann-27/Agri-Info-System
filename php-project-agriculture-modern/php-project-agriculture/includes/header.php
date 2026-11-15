<?php
// expects: $root (path back to project root), $page_title, $active
function icon(string $name, string $cls = "w-5 h-5") {
    $paths = [
        'home'     => 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'users'    => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'sun'      => 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
        'archive'  => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
        'chart'    => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'plus'     => 'M12 4.5v15m7.5-7.5h-15',
        'pencil'   => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125',
        'trash'    => 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
        'map'      => 'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z',
        'search'   => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
        'x'        => 'M6 18L18 6M6 6l12 12',
        'back'     => 'M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18',
        'logout'   => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9',
        'menu'     => 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
        'check'    => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'alert'    => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
        'left'     => 'M15.75 19.5L8.25 12l7.5-7.5',
        'right'    => 'M8.25 4.5l7.5 7.5-7.5 7.5',
        'updown'   => 'M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9',
        'up'       => 'M4.5 10.5L12 3m0 0l7.5 7.5M12 3v18',
        'down'     => 'M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3',
        'printer'  => 'M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z',
        'inbox'    => 'M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z',
        'arrow-ur' => 'M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25',
        'eye'      => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
    ];
    echo '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="' . $cls . '"><path stroke-linecap="round" stroke-linejoin="round" d="' . $paths[$name] . '"/></svg>';
}

// ---- presentation helpers (formatting only) ----
function badge_class(string $value): ?string {
    static $map = ['Planted' => 'planted', 'Growing' => 'growing', 'Harvested' => 'harvested', 'Failed' => 'failed',
                   'Grade A' => 'gradea', 'Grade B' => 'gradeb', 'Grade C' => 'gradec'];
    return $map[$value] ?? null;
}
function badge(string $value): string {
    $cls = badge_class($value);
    $safe = htmlspecialchars($value);
    return $cls ? '<span class="badge badge-' . $cls . '">' . $safe . '</span>' : $safe;
}
// $key is the column name; status / grade columns become badges
function render_cell(string $key, $value): string {
    if (in_array($key, ['status', 'quality_grade'], true)) return badge((string) $value);
    return htmlspecialchars((string) $value);
}
function cell_class(string $key, $value): string {
    if (substr($key, -3) === '_id') return 'td-id';
    return is_numeric($value) ? 'td-num' : '';
}

$nav = [
    'dashboard' => ['Dashboard', 'index.php',            'home'],
    'farmers'   => ['Farmers',   'farmers/index.php',    'users'],
    'farms'     => ['Farms',     'farms/index.php',      'map'],
    'crops'     => ['Crops',     'crops/index.php',      'sun'],
    'plantings' => ['Plantings', 'plantings/index.php',  'calendar'],
    'harvests'  => ['Harvests',  'harvests/index.php',   'archive'],
    'reports'   => ['Reports',   'reports/index.php',    'chart'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f3a29">
    <title><?= htmlspecialchars($page_title ?? 'AIS') ?> | Agriculture Information System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'], display: ['Bricolage Grotesque', 'Figtree', 'sans-serif'] }
        } } };
    </script>
    <link rel="stylesheet" href="<?= $root ?>assets/css/app.css">
</head>
<body>

<div id="sidebar-overlay" class="sidebar-overlay" aria-hidden="true"></div>

<aside id="sidebar" class="sidebar" aria-label="Main navigation">
    <a href="<?= $root ?>index.php" class="brand">
        <span class="brand-mark">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M12 21v-9"/><path d="M12 12c0-4 2.6-6.5 7.5-6.5 0 4.2-2.8 6.5-7.5 6.5z"/><path d="M12 15.5c0-3-2-5-6.5-5 0 3.2 2.2 5 6.5 5z"/></svg>
        </span>
        <span>
            <span class="brand-name block">AIS</span>
            <span class="brand-sub block">Agriculture Information System</span>
        </span>
    </a>

    <nav class="nav-group">
        <p class="nav-title">Menu</p>
        <?php foreach ($nav as $key => [$label, $href, $ico]): ?>
            <a href="<?= $root . $href ?>" class="nav-link <?= ($active ?? '') === $key ? 'active' : '' ?>" <?= ($active ?? '') === $key ? 'aria-current="page"' : '' ?>>
                <?php icon($ico, 'w-5 h-5'); ?> <span><?= $label ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <div class="user-card">
            <span class="avatar"><?= htmlspecialchars(substr($_SESSION['username'], 0, 1)) ?></span>
            <div class="min-w-0">
                <div class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="user-role">(<?= htmlspecialchars($_SESSION['role']) ?>)</div>
            </div>
            <a href="<?= $root ?>auth/logout.php" class="logout-btn" title="Logout" aria-label="Logout"><?php icon('logout', 'w-5 h-5'); ?></a>
        </div>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <button type="button" id="menu-btn" class="icon-btn" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar"><?php icon('menu', 'w-5 h-5'); ?></button>
        <div class="crumb"><span>AIS</span><span aria-hidden="true">/</span><strong><?= htmlspecialchars($page_title ?? '') ?></strong></div>
        <div class="topbar-right">
            <span class="today-chip" data-today></span>
        </div>
    </header>

<?php if (!empty($_SESSION['flash'])): $is_ok = $_SESSION['flash']['type'] === 'success'; ?>
    <div class="toast-stack" role="status" aria-live="polite">
        <div class="toast <?= $is_ok ? 'toast-success' : 'toast-error' ?>">
            <span class="toast-ico"><?php icon($is_ok ? 'check' : 'alert', 'w-5 h-5'); ?></span>
            <span><?= htmlspecialchars($_SESSION['flash']['msg']) ?></span>
            <button type="button" class="toast-close" aria-label="Dismiss"><?php icon('x', 'w-4 h-4'); ?></button>
            <span class="toast-bar"></span>
        </div>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<main class="content page-enter">
