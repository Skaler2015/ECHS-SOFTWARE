<?php
/** RGHS claims tracker dashboard. Included from dashboard.php when scheme=RGHS. */
require_once __DIR__ . '/rghs.php';
require_once __DIR__ . '/icons.php';
rghs_ensure_table();

$active = 'dashboard';
$page_title = 'RGHS Dashboard';
$pdo = db();

$total = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims")->fetch()['n'];

if ($total === 0) {
    require __DIR__ . '/header.php'; ?>
    <div class="page-head"><h1>🏥 RGHS Dashboard</h1></div>
    <div class="card" style="text-align:center;padding:48px 20px">
        <h2>Abhi koi RGHS claim nahi hai</h2>
        <p class="muted">RGHS portal se claim report (Excel) download karke upload karein — dashboard apne aap ban jaayega.</p>
        <p style="margin-top:16px"><a class="btn btn-primary" href="<?= BASE_URL ?>/rghs_upload.php?scheme=RGHS">⬆ Excel Upload karein</a></p>
    </div>
    <?php require __DIR__ . '/footer.php'; return;
}

// category counts + amounts
$cat = ['approved'=>0,'pending'=>0,'query'=>0,'rejected'=>0];
$catAmt = $cat;
foreach ($pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims GROUP BY status") as $r) {
    $c = rghs_category($r['status']); $cat[$c]+=(int)$r['n']; $catAmt[$c]+=(float)$r['amt'];
}
$sum = $pdo->query("SELECT COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu, COALESCE(SUM(tpa_amt),0) tpa FROM rghs_claims")->fetch();
$approvedAmt = $pdo->query("SELECT COALESCE(SUM(cu_amt),0) s FROM rghs_claims WHERE status LIKE '%APPROVED%' OR status LIKE '%Approved%'")->fetch()['s'];
$pend = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE ".rghs_pending_condition())->fetch();

// type split
$types = [];
foreach ($pdo->query("SELECT COALESCE(NULLIF(claim_type,''),'—') t, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims GROUP BY t ORDER BY n DESC") as $r) $types[] = $r;

// monthly (by submit_date)
$monthly = $pdo->query("SELECT DATE_FORMAT(submit_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt
    FROM rghs_claims WHERE submit_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 15")->fetchAll();
$monthly = array_reverse($monthly);
$maxM = 1; foreach ($monthly as $m) $maxM = max($maxM, (float)$m['amt']);

// top doctors
$docs = $pdo->query("SELECT doctor_name, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims
    WHERE doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name ORDER BY n DESC LIMIT 10")->fetchAll();

// recent status changes
$changes = $pdo->query("SELECT h.tid, h.from_status, h.to_status, h.changed_at, c.patient_name
    FROM rghs_claim_history h LEFT JOIN rghs_claims c ON c.tid = h.tid COLLATE utf8mb4_unicode_ci
    WHERE h.from_status IS NOT NULL ORDER BY h.changed_at DESC LIMIT 10")->fetchAll();

$totCat = array_sum($cat) ?: 1;
$pA = round($cat['approved']/$totCat*100,1);
$pP = round($cat['pending']/$totCat*100,1);
$pQ = round($cat['query']/$totCat*100,1);
$pR = round($cat['rejected']/$totCat*100,1);
$g1=$pA; $g2=$pA+$pP; $g3=$pA+$pP+$pQ;

function rmlabel($ym){ $ts=strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }

require __DIR__ . '/header.php';
?>
<div class="page-head">
    <h1>🏥 RGHS Dashboard</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_upload.php?scheme=RGHS">⬆ Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">All Claims</a>
        <a class="btn" href="<?= BASE_URL ?>/rghs_reports.php?scheme=RGHS">📊 Reports</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($total) ?></div><div class="stat-lbl">Total claims</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($sum['claim'],0) ?></div><div class="stat-lbl">Claimed amount</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($approvedAmt,0) ?></div><div class="stat-lbl">Approved (CU)</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($pend['n']) ?></div><div class="stat-lbl">Pending / in-process</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($cat['rejected']) ?></div><div class="stat-lbl">Rejected</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Claim status</h2>
        <div class="donut-wrap">
            <div class="donut" style="background:conic-gradient(#16A34A 0 <?= $g1 ?>%,#f59e0b <?= $g1 ?>% <?= $g2 ?>%,#0ea5e9 <?= $g2 ?>% <?= $g3 ?>%,#dc3545 <?= $g3 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($totCat) ?></strong><span>claims</span></div>
            </div>
            <ul class="legend">
                <li><span class="lg" style="background:#16A34A"></span> Approved — <?= number_format($cat['approved']) ?> (<?= $pA ?>%)</li>
                <li><span class="lg" style="background:#f59e0b"></span> Pending — <?= number_format($cat['pending']) ?> (<?= $pP ?>%)</li>
                <li><span class="lg" style="background:#0ea5e9"></span> Query — <?= number_format($cat['query']) ?> (<?= $pQ ?>%)</li>
                <li><span class="lg" style="background:#dc3545"></span> Rejected — <?= number_format($cat['rejected']) ?> (<?= $pR ?>%)</li>
            </ul>
        </div>
    </div>
    <div class="card">
        <h2>Type & amounts</h2>
        <table class="tbl">
            <thead><tr><th>Type</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead>
            <tbody>
            <?php foreach ($types as $t): ?>
                <tr><td><strong><?= e($t['t']) ?></strong></td><td class="r"><?= number_format($t['n']) ?></td><td class="r"><?= inr($t['amt'],0) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <table class="kv" style="margin-top:8px">
            <tr><td>Approved amount</td><th class="ok"><?= money($catAmt['approved']) ?></th></tr>
            <tr><td>Pending amount</td><th><?= money($pend['amt']) ?></th></tr>
        </table>
    </div>
</div>

<div class="card">
    <h2>Monthly — Claims submitted (amount)</h2>
    <?php if (!$monthly): ?><p class="muted">Data nahi.</p><?php else: ?>
    <div class="barchart">
        <?php foreach ($monthly as $m): $h = round((float)$m['amt']/$maxM*150); ?>
            <div class="bar-col" title="<?= rmlabel($m['ym']) ?>: <?= money($m['amt']) ?> (<?= $m['n'] ?>)">
                <div class="bar" style="height:<?= max(3,$h) ?>px"></div>
                <div class="bar-lbl"><?= rmlabel($m['ym']) ?></div>
                <div class="bar-val"><?= $m['amt']>=100000?number_format($m['amt']/100000,1).'L':number_format($m['amt']/1000,0).'k' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Top doctors</h2>
        <?php if (!$docs): ?><p class="muted">Doctor data nahi.</p><?php else: ?>
        <table class="tbl">
            <thead><tr><th>Doctor</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead>
            <tbody>
            <?php foreach ($docs as $d): ?>
                <tr><td><a class="link" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&doctor=<?= urlencode($d['doctor_name']) ?>"><?= e($d['doctor_name']) ?></a></td>
                    <td class="r"><?= number_format($d['n']) ?></td><td class="r"><?= inr($d['amt'],0) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Recent status changes</h2>
        <?php if (!$changes): ?><p class="muted">Abhi koi change nahi.</p><?php else: ?>
        <ul class="timeline">
            <?php foreach ($changes as $ch): ?>
                <li><span class="tl-date"><?= e(date('d-m-y', strtotime($ch['changed_at']))) ?></span>
                    <span class="small"><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($ch['tid']) ?>"><?= e($ch['patient_name'] ?: $ch['tid']) ?></a>:
                    <?= e($ch['from_status']) ?> → <strong><?= e($ch['to_status']) ?></strong></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
