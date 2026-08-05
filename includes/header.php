<?php
/**
 * App shell: top bar + collapsible sidebar. Content flows into <main>.
 * Expects $page_title, optional $active (menu key), $scheme.
 * Backend/logic untouched — this only frames the page.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';
require_login();

$scheme      = $scheme ?? current_scheme();
$meta        = scheme_meta($scheme);
$page_title  = $page_title ?? APP_NAME;
$active      = $active ?? '';
$u           = current_user();
$f           = flash();

// ---- scheme-aware sidebar menu (all links point at EXISTING routes) ----
if ($scheme === 'ECHS') {
    $menu = [
        ['grp' => 'Main', 'items' => [
            ['k'=>'dashboard','t'=>'Dashboard','i'=>'dashboard','u'=>BASE_URL.'/dashboard.php?scheme=ECHS'],
            ['k'=>'claims','t'=>'Claims','i'=>'file','u'=>BASE_URL.'/echs_claims.php?scheme=ECHS'],
            ['k'=>'queries','t'=>'Query Panel','i'=>'help','u'=>BASE_URL.'/echs_queries.php?scheme=ECHS'],
            ['k'=>'changes','t'=>'Kya Badla','i'=>'bell','u'=>BASE_URL.'/echs_changes.php?scheme=ECHS'],
            ['k'=>'tasks','t'=>'Tasks','i'=>'checks','u'=>BASE_URL.'/echs_tasks.php?scheme=ECHS'],
        ]],
        ['grp' => 'Lookup', 'items' => [
            ['k'=>'patients','t'=>'Patient / Card','i'=>'patients','u'=>BASE_URL.'/echs_patient.php?scheme=ECHS'],
            ['k'=>'doctors','t'=>'Doctors','i'=>'patients','u'=>BASE_URL.'/echs_doctors.php?scheme=ECHS'],
        ]],
        ['grp' => 'Data', 'items' => [
            ['k'=>'reports','t'=>'Reports','i'=>'chart','u'=>BASE_URL.'/echs_reports.php?scheme=ECHS'],
            ['k'=>'upload','t'=>'Upload Excel','i'=>'upload','u'=>BASE_URL.'/echs_upload.php?scheme=ECHS'],
            ['k'=>'backup','t'=>'Data Safety','i'=>'database','u'=>BASE_URL.'/echs_backup.php?scheme=ECHS'],
        ]],
        ['grp' => 'Account', 'items' => [
            ['k'=>'users','t'=>'Staff Users','i'=>'users','u'=>BASE_URL.'/echs_users.php?scheme=ECHS'],
            ['k'=>'activity','t'=>'Activity Log','i'=>'chart','u'=>BASE_URL.'/echs_activity.php?scheme=ECHS'],
            ['k'=>'settings','t'=>'Settings','i'=>'settings','u'=>BASE_URL.'/settings.php'],
        ]],
    ];
    $searchAction = BASE_URL.'/echs_claims.php';
} elseif ($scheme === 'STORE') {
    $menu = [
        ['grp' => 'Main', 'items' => [
            ['k'=>'dashboard','t'=>'Dashboard','i'=>'dashboard','u'=>BASE_URL.'/dashboard.php?scheme=STORE'],
            ['k'=>'claims','t'=>'Invoices','i'=>'file','u'=>BASE_URL.'/store_claims.php?scheme=STORE'],
        ]],
        ['grp' => 'Data', 'items' => [
            ['k'=>'reports','t'=>'Reports','i'=>'chart','u'=>BASE_URL.'/store_reports.php?scheme=STORE'],
            ['k'=>'upload','t'=>'Upload Excel','i'=>'upload','u'=>BASE_URL.'/store_upload.php?scheme=STORE'],
        ]],
        ['grp' => 'Account', 'items' => [
            ['k'=>'settings','t'=>'Settings','i'=>'settings','u'=>BASE_URL.'/settings.php'],
        ]],
    ];
    $searchAction = BASE_URL.'/store_claims.php';
} else {
    $menu = [
        ['grp' => 'Main', 'items' => [
            ['k'=>'dashboard','t'=>'Dashboard','i'=>'dashboard','u'=>BASE_URL.'/dashboard.php?scheme=RGHS'],
            ['k'=>'claims','t'=>'Claims','i'=>'file','u'=>BASE_URL.'/rghs_claims.php?scheme=RGHS'],
            ['k'=>'queries','t'=>'Query Panel','i'=>'help','u'=>BASE_URL.'/rghs_queries.php?scheme=RGHS'],
            ['k'=>'changes','t'=>'Kya Badla','i'=>'bell','u'=>BASE_URL.'/rghs_changes.php?scheme=RGHS'],
            ['k'=>'tasks','t'=>'Tasks','i'=>'checks','u'=>BASE_URL.'/rghs_tasks.php?scheme=RGHS'],
        ]],
        ['grp' => 'Lookup', 'items' => [
            ['k'=>'patients','t'=>'Patient / Card','i'=>'patients','u'=>BASE_URL.'/rghs_patient.php?scheme=RGHS'],
            ['k'=>'doctors','t'=>'Doctors','i'=>'patients','u'=>BASE_URL.'/rghs_doctors.php?scheme=RGHS'],
        ]],
        ['grp' => 'Data', 'items' => [
            ['k'=>'reports','t'=>'Reports','i'=>'chart','u'=>BASE_URL.'/rghs_reports.php?scheme=RGHS'],
            ['k'=>'bank','t'=>'Bank Reconcile','i'=>'card','u'=>BASE_URL.'/rghs_bank.php?scheme=RGHS'],
            ['k'=>'upload','t'=>'Upload Excel','i'=>'upload','u'=>BASE_URL.'/rghs_upload.php?scheme=RGHS'],
            ['k'=>'backup','t'=>'Data Safety','i'=>'database','u'=>BASE_URL.'/rghs_backup.php?scheme=RGHS'],
        ]],
        ['grp' => 'Account', 'items' => [
            ['k'=>'users','t'=>'Staff Users','i'=>'users','u'=>BASE_URL.'/rghs_users.php?scheme=RGHS'],
            ['k'=>'activity','t'=>'Activity Log','i'=>'chart','u'=>BASE_URL.'/rghs_activity.php?scheme=RGHS'],
            ['k'=>'settings','t'=>'Settings','i'=>'settings','u'=>BASE_URL.'/settings.php'],
        ]],
    ];
    $searchAction = BASE_URL.'/rghs_claims.php';
}

// ---- notifications (real data, best-effort) ----
$notifs = []; $notifCount = 0;
if ($scheme === 'ECHS') {
    try {
        $od = (int)db()->query("SELECT COUNT(*) n FROM echs_tasks WHERE done=0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetch()['n'];
        if ($od > 0) { $notifs[] = ['t'=>"$od task overdue", 'u'=>BASE_URL.'/echs_tasks.php?scheme=ECHS', 'i'=>'checks']; $notifCount += $od; }
        $nmi = (int)db()->query("SELECT COUNT(*) n FROM echs_claims WHERE status LIKE '%Need More Information%'")->fetch()['n'];
        if ($nmi > 0) { $notifs[] = ['t'=>"$nmi claim need info", 'u'=>BASE_URL.'/echs_claims.php?scheme=ECHS&q=', 'i'=>'file']; $notifCount += $nmi; }
        $lu = db()->query("SELECT MAX(uploaded_at) m FROM echs_uploads")->fetch();
        if (!empty($lu['m']) && (time()-strtotime($lu['m'])) > 5*86400) {
            $d = (int)floor((time()-strtotime($lu['m']))/86400);
            $notifs[] = ['t'=>"Upload $d din se pending", 'u'=>BASE_URL.'/echs_upload.php?scheme=ECHS', 'i'=>'upload']; $notifCount++;
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
    <?php $cssv = @filemtime(__DIR__ . '/../assets/css/style.css') ?: APP_VERSION; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= $cssv ?>">
    <?php $mf = $scheme === 'ECHS' ? 'manifest-echs.webmanifest' : 'manifest-rghs.webmanifest';
          $ic = $scheme === 'ECHS' ? 'echs' : 'rghs'; ?>
    <link rel="manifest" href="<?= BASE_URL ?>/<?= $mf ?>">
    <meta name="theme-color" content="#0F1E3D">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= $scheme==='ECHS'?'ECHS':'RGHS' ?>">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icon.php?s=192&c=<?= $ic ?>">
    <style>:root{ --scheme-color: <?= e($meta['color']) ?>; }</style>
</head>
<body>
<script>if(localStorage.getItem('theme')==='dark')document.body.classList.add('dark');
if(localStorage.getItem('sidebar')==='collapsed')document.body.classList.add('sb-collapsed');</script>

<div class="app">
    <aside class="sidebar" id="sidebar">
        <div class="sb-brand">
            <span class="sb-logo">◈</span>
            <span class="sb-name"><?= e(APP_NAME) ?></span>
        </div>
        <div class="sb-scheme" style="--sc:<?= e($meta['color']) ?>">
            <?= e($meta['icon']) ?> <span class="sb-txt"><?= e($meta['short']) ?></span>
        </div>
        <nav class="sb-nav">
        <?php foreach ($menu as $group): ?>
            <div class="sb-grp"><?= e($group['grp']) ?></div>
            <?php foreach ($group['items'] as $it): ?>
                <a class="sb-link <?= $active===$it['k']?'on':'' ?>" href="<?= $it['u'] ?>">
                    <?= icon($it['i']) ?><span class="sb-txt"><?= e($it['t']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </nav>
        <a class="sb-link sb-switch" href="<?= BASE_URL ?>/home.php"><?= icon('switch') ?><span class="sb-txt">Switch Scheme</span></a>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button class="icon-btn menu-btn" onclick="toggleSidebar()" aria-label="Menu"><?= icon('menu') ?></button>
            <div class="scheme-switch" title="Portal switch karein">
                <a class="ss-opt <?= $scheme==='RGHS'?'on':'' ?>" href="<?= BASE_URL ?>/dashboard.php?scheme=RGHS">RGHS</a>
                <a class="ss-opt <?= $scheme==='ECHS'?'on':'' ?>" href="<?= BASE_URL ?>/dashboard.php?scheme=ECHS">ECHS</a>
                <a class="ss-opt <?= $scheme==='STORE'?'on':'' ?>" href="<?= BASE_URL ?>/dashboard.php?scheme=STORE">💊 Store</a>
            </div>
            <form class="topsearch" method="get" action="<?= $searchAction ?>" role="search">
                <input type="hidden" name="scheme" value="<?= $scheme ?>">
                <?= icon('search',18) ?>
                <input type="text" name="q" placeholder="Search claim, card, patient, doctor…" aria-label="Search">
            </form>
            <div class="topbar-actions">
                <div class="dd">
                    <button class="icon-btn" onclick="toggleDd(event,'ddNotif')" aria-label="Notifications">
                        <?= icon('bell') ?><?php if ($notifCount>0): ?><span class="badge-dot"><?= $notifCount>9?'9+':$notifCount ?></span><?php endif; ?>
                    </button>
                    <div class="dd-panel" id="ddNotif">
                        <div class="dd-head">Notifications</div>
                        <?php if (!$notifs): ?><div class="dd-empty">Sab clear hai ✅</div><?php else: foreach ($notifs as $n): ?>
                            <a class="dd-item" href="<?= $n['u'] ?>"><?= icon($n['i'],16) ?> <span><?= e($n['t']) ?></span></a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <button class="icon-btn" onclick="toggleTheme()" aria-label="Theme"><?= icon('sun') ?></button>
                <div class="dd">
                    <button class="profile-btn" onclick="toggleDd(event,'ddUser')">
                        <span class="avatar"><?= e(strtoupper(mb_substr($u['full_name'] ?? 'U',0,1))) ?></span>
                        <span class="pname"><?= e($u['full_name'] ?? '') ?></span>
                        <?= icon('chevron',16) ?>
                    </button>
                    <div class="dd-panel dd-right" id="ddUser">
                        <div class="dd-head"><?= e($u['full_name'] ?? '') ?><div class="muted small"><?= e($u['role'] ?? '') ?></div></div>
                        <a class="dd-item" href="<?= BASE_URL ?>/settings.php"><?= icon('settings',16) ?> Settings</a>
                        <a class="dd-item" href="<?= BASE_URL ?>/home.php"><?= icon('home',16) ?> Home</a>
                        <a class="dd-item danger" href="<?= BASE_URL ?>/logout.php"><?= icon('logout',16) ?> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="container">
        <?php if ($f): ?>
            <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
        <?php endif; ?>
