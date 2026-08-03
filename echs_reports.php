<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'reports';
$page_title = 'ECHS Reports';

// category split
$cats = ['settled'=>0,'process'=>0,'rejected'=>0];
$catAmt = ['settled'=>0,'process'=>0,'rejected'=>0];
foreach (db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims GROUP BY status") as $r) {
    $c = echs_category($r['status']);
    $cats[$c] += (int)$r['n'];
    $catAmt[$c] += (float)$r['net'];
}
$catTotal = array_sum($cats) ?: 1;

// monthly: claims accepted per month
$monthlyAccept = db()->query("SELECT DATE_FORMAT(accept_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net
    FROM echs_claims WHERE accept_date IS NOT NULL
    GROUP BY ym ORDER BY ym DESC LIMIT 18")->fetchAll();
$monthlyAccept = array_reverse($monthlyAccept);

// monthly: claims settled per month (by processed_on)
$monthlySettled = db()->query("SELECT DATE_FORMAT(processed_on,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(approved_amt),0) app
    FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL
    GROUP BY ym ORDER BY ym DESC LIMIT 18")->fetchAll();
$monthlySettled = array_reverse($monthlySettled);

$maxAccept = 1; foreach ($monthlyAccept as $m) $maxAccept = max($maxAccept, (float)$m['net']);
$maxSettled = 1; foreach ($monthlySettled as $m) $maxSettled = max($maxSettled, (float)$m['app']);

// average turnaround (accept -> processed) for settled claims
$tat = db()->query("SELECT AVG(DATEDIFF(processed_on, accept_date)) d, COUNT(*) n
    FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL AND accept_date IS NOT NULL
    AND processed_on >= accept_date")->fetch();

// top cards by total net
$topCards = db()->query("SELECT card_id, MAX(esm_name) esm, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app
    FROM echs_claims WHERE card_id IS NOT NULL AND card_id<>'' GROUP BY card_id ORDER BY net DESC LIMIT 15")->fetchAll();

function mlabel($ym){ $ts = strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }

// conic gradient for category donut
$pS = round($cats['settled']/$catTotal*100,1);
$pP = round($cats['process']/$catTotal*100,1);
$pR = round($cats['rejected']/$catTotal*100,1);
$g1 = $pS; $g2 = $pS + $pP;

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>📈 ECHS Reports</h1>
    <div class="page-actions">
        <a class="btn" target="_blank" href="<?= BASE_URL ?>/echs_report_print.php?scheme=ECHS">🖨️ Print / PDF</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_xls.php?scheme=ECHS">⬇ Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_csv.php?scheme=ECHS">⬇ CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card info"><div class="stat-num"><?= $tat['d']!==null?round($tat['d']).' din':'-' ?></div><div class="stat-lbl">Avg. settle time (accept→processed)</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format((int)$tat['n']) ?></div><div class="stat-lbl">Settled (with dates)</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Claim Category</h2>
        <div class="donut-wrap">
            <div class="donut" style="background:conic-gradient(#198754 0 <?= $g1 ?>%, #f59e0b <?= $g1 ?>% <?= $g2 ?>%, #dc3545 <?= $g2 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($catTotal) ?></strong><span>claims</span></div>
            </div>
            <ul class="legend">
                <li><span class="lg" style="background:#198754"></span> Settled — <?= number_format($cats['settled']) ?> (<?= $pS ?>%)</li>
                <li><span class="lg" style="background:#f59e0b"></span> In-process — <?= number_format($cats['process']) ?> (<?= $pP ?>%)</li>
                <li><span class="lg" style="background:#dc3545"></span> Rejected/Cancel — <?= number_format($cats['rejected']) ?> (<?= $pR ?>%)</li>
            </ul>
        </div>
    </div>
    <div class="card">
        <h2>Amount by Category</h2>
        <table class="kv">
            <tr><td><span class="pill pill-settled">Settled</span></td><th><?= money($catAmt['settled']) ?></th></tr>
            <tr><td><span class="pill pill-process">In-process</span></td><th><?= money($catAmt['process']) ?></th></tr>
            <tr><td><span class="pill pill-rejected">Rejected/Cancel</span></td><th><?= money($catAmt['rejected']) ?></th></tr>
        </table>
        <p class="muted small">Net claim amount ke hisaab se.</p>
    </div>
</div>

<div class="card">
    <h2>Monthly — Claims Accepted (Net Amount)</h2>
    <?php if (!$monthlyAccept): ?><p class="muted">Data nahi.</p><?php else: ?>
    <div class="barchart">
        <?php foreach ($monthlyAccept as $m): $h = round((float)$m['net']/$maxAccept*140); ?>
            <div class="bar-col" title="<?= mlabel($m['ym']) ?>: <?= money($m['net']) ?> (<?= $m['n'] ?> claims)">
                <div class="bar" style="height:<?= max(3,$h) ?>px"></div>
                <div class="bar-lbl"><?= mlabel($m['ym']) ?></div>
                <div class="bar-val"><?= number_format($m['net']/1000,0) ?>k</div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Monthly — Claims Settled (Approved Amount)</h2>
    <?php if (!$monthlySettled): ?><p class="muted">Abhi koi settled data nahi.</p><?php else: ?>
    <div class="barchart">
        <?php foreach ($monthlySettled as $m): $h = round((float)$m['app']/$maxSettled*140); ?>
            <div class="bar-col" title="<?= mlabel($m['ym']) ?>: <?= money($m['app']) ?> (<?= $m['n'] ?> settled)">
                <div class="bar bar-ok" style="height:<?= max(3,$h) ?>px"></div>
                <div class="bar-lbl"><?= mlabel($m['ym']) ?></div>
                <div class="bar-val"><?= number_format($m['app']/1000,0) ?>k</div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Top Cards (rakam ke hisaab se)</h2>
    <table class="tbl">
        <thead><tr><th>Card ID</th><th>ESM</th><th class="r">Claims</th><th class="r">Net</th><th class="r">Approved</th></tr></thead>
        <tbody>
        <?php foreach ($topCards as $t): ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&q=<?= urlencode($t['card_id']) ?>"><?= e($t['card_id']) ?></a></td>
                <td><?= e($t['esm']) ?></td>
                <td class="r"><?= number_format($t['n']) ?></td>
                <td class="r"><?= money($t['net']) ?></td>
                <td class="r"><?= money($t['app']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Monthly Table (Accepted)</h2>
    <table class="tbl">
        <thead><tr><th>Month</th><th class="r">Claims</th><th class="r">Net Amount</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($monthlyAccept) as $m): ?>
            <tr><td><?= mlabel($m['ym']) ?></td><td class="r"><?= number_format($m['n']) ?></td><td class="r"><?= money($m['net']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
