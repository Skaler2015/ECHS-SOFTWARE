<?php
/** RGHS claims tracker dashboard. Included from dashboard.php when scheme=RGHS. */
require_once __DIR__ . '/rghs.php';
require_once __DIR__ . '/icons.php';
rghs_ensure_table();

$active = 'dashboard';
$page_title = 'RGHS Dashboard';
$pdo = db();

// set monthly target (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'settarget') {
    csrf_check();
    $ym = preg_match('/^\d{4}-\d{2}$/', $_POST['ym'] ?? '') ? $_POST['ym'] : date('Y-m');
    $pdo->prepare("INSERT INTO rghs_targets (ym,claims_target,amount_target) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE claims_target=VALUES(claims_target), amount_target=VALUES(amount_target)")
        ->execute([$ym, (int)($_POST['claims_target'] ?? 0), (float)preg_replace('/[^0-9.]/','',$_POST['amount_target'] ?? '0')]);
    rghs_log('set_target', "$ym");
    flash('Target set ho gaya.');
    redirect(BASE_URL.'/dashboard.php?scheme=RGHS');
}

$total = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims")->fetch()['n'];

// keep a daily safety snapshot (best-effort, once per day)
if ($total > 0) { @rghs_daily_snapshot(); }

// this-month target vs achieved
$thisYm = date('Y-m');
$tgt = rghs_target($thisYm);
$mAch = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE DATE_FORMAT(submit_date,'%Y-%m')=?");
$mAch->execute([$thisYm]); $ach = $mAch->fetch();
$mRecv = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) s FROM rghs_claims WHERE DATE_FORMAT(payment_date,'%Y-%m')=?");
$mRecv->execute([$thisYm]); $achRecv = (float)$mRecv->fetch()['s'];

// alerts
$alerts = [];
if ($total > 0) {
    $au = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(cu_amt),0) amt FROM rghs_claims
        WHERE (status LIKE '%APPROVED%' OR status LIKE '%Approved%') AND (paid_amount=0 OR paid_amount IS NULL)
        AND (payment_status IS NULL OR payment_status NOT LIKE '%PROCESS%')")->fetch();
    if ($au['n'] > 0) $alerts[] = ['warn', number_format($au['n']).' claims approved par paisa baaki', money($au['amt']), BASE_URL.'/rghs_reports.php?scheme=RGHS'];
    $qold = $pdo->query("SELECT COUNT(*) n FROM rghs_claims WHERE (status LIKE '%QUER%' OR status LIKE '%PENDING WITH%' OR status LIKE '%Pending with%')
        AND submit_date IS NOT NULL AND DATEDIFF(CURDATE(),submit_date) > 15")->fetch()['n'];
    if ($qold > 0) $alerts[] = ['danger', $qold.' query/stuck claims 15+ din se', 'action lena hai', BASE_URL.'/rghs_reports.php?scheme=RGHS'];
    $odtask = 0;
    try { $odtask = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_tasks WHERE done=0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetch()['n']; } catch (Exception $e) {}
    if ($odtask > 0) $alerts[] = ['danger', $odtask.' task overdue', '', BASE_URL.'/rghs_tasks.php?scheme=RGHS'];
}

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

// payments summary (from Payment Tracker uploads)
$payAgg = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN final_status LIKE '%SUCCESS%' THEN paid_amount ELSE 0 END),0) paid,
        COALESCE(SUM(CASE WHEN final_status LIKE '%PROCESS%' THEN paid_amount ELSE 0 END),0) inproc,
        COALESCE(SUM(tds_deducted),0) tds,
        SUM(final_status LIKE '%SUCCESS%') paidn,
        SUM(final_status LIKE '%PROCESS%') procn,
        COUNT(*) total
    FROM rghs_payments")->fetch();
$hasPay = ((int)$payAgg['total']) > 0;

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

<?php if ($alerts): ?>
<div class="card" style="border-left:4px solid var(--warn)">
    <h2>🔔 Dhyan dein</h2>
    <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach ($alerts as $a): ?>
        <a href="<?= $a[3] ?>" class="dd-item" style="display:flex;justify-content:space-between;gap:10px;padding:8px 10px;border-radius:8px;text-decoration:none">
            <span><span class="pill pill-<?= $a[0]==='danger'?'rejected':'process' ?>" style="margin-right:8px"><?= $a[0]==='danger'?'!':'•' ?></span><?= e($a[1]) ?></span>
            <strong><?= e($a[2]) ?></strong>
        </a>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($total) ?></div><div class="stat-lbl">Total claims</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($sum['claim'],0) ?></div><div class="stat-lbl">Claimed amount</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($approvedAmt,0) ?></div><div class="stat-lbl">Approved (CU)</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($pend['n']) ?></div><div class="stat-lbl">Pending / in-process</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($cat['rejected']) ?></div><div class="stat-lbl">Rejected</div></div>
</div>

<?php if ($hasPay): ?>
<div class="stat-grid">
    <div class="stat-card ok"><div class="stat-num"><?= inr($payAgg['paid'],0) ?></div><div class="stat-lbl">Paid (received) · <?= number_format($payAgg['paidn']) ?></div></div>
    <div class="stat-card warn"><div class="stat-num"><?= inr($payAgg['inproc'],0) ?></div><div class="stat-lbl">Payment in-process · <?= number_format($payAgg['procn']) ?></div></div>
    <div class="stat-card info"><div class="stat-num"><?= inr($payAgg['tds'],0) ?></div><div class="stat-lbl">TDS deducted</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($payAgg['total']) ?></div><div class="stat-lbl">Payment records</div></div>
</div>
<?php endif; ?>

<?php
$tgtClaims = (int)$tgt['claims_target']; $tgtAmt = (float)$tgt['amount_target'];
$pc = $tgtClaims>0 ? min(100,round($ach['n']/$tgtClaims*100)) : 0;
$pa = $tgtAmt>0 ? min(100,round($ach['amt']/$tgtAmt*100)) : 0;
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <h2 style="margin:0">🎯 <?= date('F Y') ?> — Target vs Achieved</h2>
        <details><summary class="link" style="cursor:pointer">Target set/edit</summary>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                <?= csrf_field() ?><input type="hidden" name="act" value="settarget"><input type="hidden" name="ym" value="<?= $thisYm ?>">
                <input name="claims_target" type="number" placeholder="Claims target" value="<?= $tgtClaims?:'' ?>" style="padding:8px;border:1px solid var(--line);border-radius:8px;width:130px">
                <input name="amount_target" placeholder="Amount target ₹" value="<?= $tgtAmt?:'' ?>" style="padding:8px;border:1px solid var(--line);border-radius:8px;width:150px">
                <button class="btn btn-primary">Save</button>
            </form>
        </details>
    </div>
    <?php if ($tgtClaims>0 || $tgtAmt>0): ?>
    <div class="detail-grid" style="margin-top:12px">
        <div>
            <div class="muted small">Claims: <strong><?= number_format($ach['n']) ?></strong> / <?= number_format($tgtClaims) ?> (<?= $pc ?>%)</div>
            <div style="background:var(--line);border-radius:999px;height:10px;overflow:hidden;margin-top:6px"><div style="background:var(--brand);height:100%;width:<?= $pc ?>%"></div></div>
        </div>
        <div>
            <div class="muted small">Claimed ₹: <strong><?= inr($ach['amt'],0) ?></strong> / <?= inr($tgtAmt,0) ?> (<?= $pa ?>%)</div>
            <div style="background:var(--line);border-radius:999px;height:10px;overflow:hidden;margin-top:6px"><div style="background:var(--gold);height:100%;width:<?= $pa ?>%"></div></div>
        </div>
    </div>
    <p class="muted small" style="margin-top:10px">Is mahine received: <strong><?= money($achRecv) ?></strong></p>
    <?php else: ?>
    <p class="muted small" style="margin-top:8px">Is mahine ka target set nahi. Upar "Target set/edit" se laga dijiye.</p>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Claim status</h2>
        <div class="donut-wrap">
            <div class="donut" style="background:conic-gradient(#16A34A 0 <?= $g1 ?>%,#f59e0b <?= $g1 ?>% <?= $g2 ?>%,#0ea5e9 <?= $g2 ?>% <?= $g3 ?>%,#dc3545 <?= $g3 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($totCat) ?></strong><span>claims</span></div>
            </div>
            <ul class="legend">
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&cat=approved"><span class="lg" style="background:#16A34A"></span> Approved — <?= number_format($cat['approved']) ?> (<?= $pA ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&cat=pending"><span class="lg" style="background:#f59e0b"></span> Pending — <?= number_format($cat['pending']) ?> (<?= $pP ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&cat=query"><span class="lg" style="background:#0ea5e9"></span> Query — <?= number_format($cat['query']) ?> (<?= $pQ ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&cat=rejected"><span class="lg" style="background:#dc3545"></span> Rejected — <?= number_format($cat['rejected']) ?> (<?= $pR ?>%)</a></li>
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
