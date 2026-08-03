<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$u = current_user();

// quick counts per scheme
function scheme_counts($scheme) {
    $c = ['patients'=>0,'bills'=>0,'pending'=>0,'amount'=>0];
    try {
        $p = db()->prepare('SELECT COUNT(*) n FROM patients WHERE scheme=?'); $p->execute([$scheme]);
        $c['patients'] = (int)$p->fetch()['n'];
        $b = db()->prepare('SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) s FROM bills WHERE scheme=?'); $b->execute([$scheme]);
        $row = $b->fetch(); $c['bills'] = (int)$row['n']; $c['amount'] = (float)$row['s'];
        $pd = db()->prepare("SELECT COUNT(*) n FROM bills WHERE scheme=? AND status='Pending'"); $pd->execute([$scheme]);
        $c['pending'] = (int)$pd->fetch()['n'];
    } catch (Exception $e) {}
    return $c;
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="home-page">
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?= BASE_URL ?>/home.php"><span class="brand-mark">◈</span> <?= e(APP_NAME) ?></a>
        <div class="topbar-right">
            <span class="user"><?= e($u['full_name']) ?></span>
            <a class="logout" href="<?= BASE_URL ?>/logout.php">Logout</a>
        </div>
    </div>
</header>
<main class="container">
    <h1 class="home-title">Namaste, <?= e($u['full_name']) ?> 👋</h1>
    <p class="home-sub">Apni scheme chunein — dono alag-alag modules hain.</p>

    <div class="scheme-grid">
        <?php foreach ($SCHEMES as $code => $m): $c = scheme_counts($code); ?>
        <a class="scheme-tile" href="<?= BASE_URL ?>/dashboard.php?scheme=<?= $code ?>" style="--tile:<?= e($m['color']) ?>">
            <div class="scheme-tile-icon"><?= e($m['icon']) ?></div>
            <div class="scheme-tile-title"><?= e($m['short']) ?></div>
            <div class="scheme-tile-name"><?= e($m['name']) ?></div>
            <div class="scheme-tile-stats">
                <span><strong><?= $c['patients'] ?></strong> Patients</span>
                <span><strong><?= $c['bills'] ?></strong> Bills</span>
                <span><strong><?= $c['pending'] ?></strong> Pending</span>
            </div>
            <div class="scheme-tile-amount"><?= money($c['amount']) ?> total</div>
            <div class="scheme-tile-open">Open <?= e($m['short']) ?> →</div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="home-links">
        <a href="<?= BASE_URL ?>/settings.php">⚙️ Settings</a>
    </div>
</main>
<footer class="footer">© <?= date('Y') ?> <?= e(APP_NAME) ?> · <?= e(APP_OWNER) ?></footer>
</body>
</html>
