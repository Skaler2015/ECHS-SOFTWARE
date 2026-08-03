<?php
/**
 * Common page header + top navigation.
 * Expects $page_title and optionally $active (menu key) and $scheme.
 */
require_once __DIR__ . '/auth.php';
require_login();

$scheme      = $scheme ?? current_scheme();
$meta        = scheme_meta($scheme);
$page_title  = $page_title ?? APP_NAME;
$active      = $active ?? '';
$u           = current_user();
$f           = flash();
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>:root{ --scheme-color: <?= e($meta['color']) ?>; }</style>
</head>
<body>
<header class="topbar" style="border-top:4px solid <?= e($meta['color']) ?>">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>/home.php">
            <span class="brand-mark">◈</span> <?= e(APP_NAME) ?>
        </a>
        <div class="scheme-badge" style="background:<?= e($meta['color']) ?>">
            <?= e($meta['icon']) ?> <?= e($meta['short']) ?>
        </div>
        <nav class="topnav">
            <a class="<?= $active==='dashboard'?'on':'' ?>" href="<?= BASE_URL ?>/dashboard.php?scheme=<?= $scheme ?>">Dashboard</a>
            <?php if ($scheme === 'ECHS'): ?>
                <a class="<?= $active==='claims'?'on':'' ?>" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">Claims</a>
                <a class="<?= $active==='upload'?'on':'' ?>" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">📥 Upload Excel</a>
            <?php else: ?>
                <a class="<?= $active==='patients'?'on':'' ?>"  href="<?= BASE_URL ?>/patients.php?scheme=<?= $scheme ?>">Patients</a>
                <a class="<?= $active==='bills'?'on':'' ?>"     href="<?= BASE_URL ?>/bills.php?scheme=<?= $scheme ?>">Bills / Claims</a>
                <a class="<?= $active==='medicines'?'on':'' ?>" href="<?= BASE_URL ?>/medicines.php?scheme=<?= $scheme ?>">Medicines</a>
                <a class="<?= $active==='reports'?'on':'' ?>"   href="<?= BASE_URL ?>/reports.php?scheme=<?= $scheme ?>">Reports</a>
            <?php endif; ?>
        </nav>
        <div class="topbar-right">
            <a class="switch" href="<?= BASE_URL ?>/home.php" title="Switch scheme">⇄ Switch</a>
            <span class="user"><?= e($u['full_name'] ?? '') ?></span>
            <a class="logout" href="<?= BASE_URL ?>/logout.php">Logout</a>
        </div>
    </div>
</header>
<main class="container">
<?php if ($f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endif; ?>
