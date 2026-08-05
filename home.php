<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$u = current_user();

// quick counts per scheme
function scheme_counts($scheme) {
    if ($scheme === 'ECHS') {
        // ECHS is now a native upload-based claims tracker (echs_claims).
        $c = ['labels'=>['Claims','Settled','Pending'], 'patients'=>0,'bills'=>0,'pending'=>0,'amount'=>0,'echs'=>true];
        try {
            $r = db()->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) s FROM echs_claims")->fetch();
            $c['patients'] = (int)($r['n'] ?? 0);
            $c['amount']   = (float)($r['s'] ?? 0);
            $st = db()->query("SELECT COUNT(*) n FROM echs_claims WHERE category='settled'")->fetch();
            $c['bills']    = (int)($st['n'] ?? 0);
            $c['pending']  = max(0, $c['patients'] - $c['bills']);
        } catch (Exception $e) {}
        return $c;
    }
    // RGHS is now an upload-based claims tracker (rghs_claims).
    $c = ['labels'=>['Claims','Approved','Pending'], 'patients'=>0,'bills'=>0,'pending'=>0,'amount'=>0,'echs'=>false];
    try {
        $r = db()->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) s FROM rghs_claims")->fetch();
        $c['patients'] = (int)($r['n'] ?? 0);
        $c['amount']   = (float)($r['s'] ?? 0);
        $ap = db()->query("SELECT COUNT(*) n FROM rghs_claims WHERE status LIKE '%APPROVED%' OR status LIKE '%Approved%'")->fetch();
        $c['bills'] = (int)($ap['n'] ?? 0);
        $c['pending'] = max(0, $c['patients'] - $c['bills']);
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__.'/assets/css/style.css') ?: APP_VERSION ?>">
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

    <?php
    // precompute per-scheme counts (used by summary + tiles)
    $countsByCode = [];
    foreach ($SCHEMES as $code => $m) $countsByCode[$code] = scheme_counts($code);
    $grandClaims = array_sum(array_column($countsByCode, 'patients'));
    $grandAmount = array_sum(array_column($countsByCode, 'amount'));
    // RGHS money picture (received / outstanding)
    $rReceived = 0; $rOutstanding = 0;
    try {
        $rReceived = (float)db()->query("SELECT COALESCE(SUM(paid_amount),0) s FROM rghs_claims")->fetch()['s'];
        $rOutstanding = (float)db()->query("SELECT COALESCE(SUM(cu_amt),0) s FROM rghs_claims WHERE (status LIKE '%APPROVED%' OR status LIKE '%Approved%') AND (paid_amount=0 OR paid_amount IS NULL) AND (payment_status IS NULL OR payment_status NOT LIKE '%PROCESS%')")->fetch()['s'];
    } catch (Exception $e) {}
    ?>
    <div class="scheme-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:18px">
        <div class="scheme-tile" style="--tile:#1B2F5E;cursor:default">
            <div class="scheme-tile-title" style="font-size:1.4rem"><?= number_format($grandClaims) ?></div>
            <div class="scheme-tile-name">Total claims (dono)</div>
        </div>
        <div class="scheme-tile" style="--tile:#C9A227;cursor:default">
            <div class="scheme-tile-title" style="font-size:1.2rem"><?= money($grandAmount) ?></div>
            <div class="scheme-tile-name">Total claimed</div>
        </div>
        <div class="scheme-tile" style="--tile:#2E7D4F;cursor:default">
            <div class="scheme-tile-title" style="font-size:1.2rem"><?= money($rReceived) ?></div>
            <div class="scheme-tile-name">RGHS received</div>
        </div>
        <div class="scheme-tile" style="--tile:#B52525;cursor:default">
            <div class="scheme-tile-title" style="font-size:1.2rem"><?= money($rOutstanding) ?></div>
            <div class="scheme-tile-name">RGHS outstanding</div>
        </div>
    </div>

    <div class="scheme-grid">
        <?php foreach ($SCHEMES as $code => $m): $c = $countsByCode[$code];
              $tileUrl = BASE_URL.'/dashboard.php?scheme='.$code; ?>
        <a class="scheme-tile" href="<?= $tileUrl ?>" style="--tile:<?= e($m['color']) ?>">
            <div class="scheme-tile-icon"><?= e($m['icon']) ?></div>
            <div class="scheme-tile-title"><?= e($m['short']) ?></div>
            <div class="scheme-tile-name"><?= e($m['name']) ?></div>
            <div class="scheme-tile-stats">
                <span><strong><?= number_format($c['patients']) ?></strong> <?= e($c['labels'][0]) ?></span>
                <span><strong><?= number_format($c['bills']) ?></strong> <?= e($c['labels'][1]) ?></span>
                <span><strong><?= number_format($c['pending']) ?></strong> <?= e($c['labels'][2]) ?></span>
            </div>
            <div class="scheme-tile-amount"><?= money($c['amount']) ?> total</div>
            <div class="scheme-tile-open">Open <?= e($m['short']) ?> →</div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="home-links">
        <a href="<?= BASE_URL ?>/settings.php">⚙️ Settings</a>
        <a href="<?= BASE_URL ?>/echs_backup.php?scheme=ECHS">🛡️ ECHS Data Safety</a>
        <a href="<?= BASE_URL ?>/rghs_backup.php?scheme=RGHS">🛡️ RGHS Data Safety</a>
    </div>
</main>
<footer class="footer">© <?= date('Y') ?> <?= e(APP_NAME) ?> · <?= e(APP_OWNER) ?></footer>
</body>
</html>
