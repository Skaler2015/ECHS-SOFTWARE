<?php
/** ECHS claims tracker dashboard. Included from dashboard.php when scheme=ECHS. */
require_once __DIR__ . '/echs.php';
require_once __DIR__ . '/icons.php';
echs_ensure_table();

$active = 'dashboard';
$page_title = 'ECHS Dashboard';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'settarget') {
    csrf_check();
    $ym = preg_match('/^\d{4}-\d{2}$/', $_POST['ym'] ?? '') ? $_POST['ym'] : date('Y-m');
    $pdo->prepare("INSERT INTO echs_targets (ym,claims_target,amount_target) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE claims_target=VALUES(claims_target), amount_target=VALUES(amount_target)")
        ->execute([$ym, (int)($_POST['claims_target'] ?? 0), (float)preg_replace('/[^0-9.]/','',$_POST['amount_target'] ?? '0')]);
    echs_log('set_target', "$ym");
    flash('Target set ho gaya.');
    redirect(BASE_URL.'/dashboard.php?scheme=ECHS');
}

$total = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'];
if ($total > 0) { @echs_daily_snapshot(); }

// this-month target vs achieved (by accept_date)
$thisYm = date('Y-m');
$tgt = echs_target($thisYm);
$mAch = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims WHERE (YEAR(accept_date)*100+MONTH(accept_date))=?");
$mAch->execute([(int)str_replace('-','',$thisYm)]); $ach = $mAch->fetch();
$mRecv = $pdo->prepare("SELECT COALESCE(SUM(approved_amt),0) s FROM echs_claims WHERE category='settled' AND (YEAR(processed_on)*100+MONTH(processed_on))=?");
$mRecv->execute([(int)str_replace('-','',$thisYm)]); $achRecv = (float)$mRecv->fetch()['s'];

// alerts
$alerts = [];
if ($total > 0) {
    $q = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE category='query'")->fetch()['n'];
    if ($q > 0) $alerts[] = ['danger', number_format($q).' claims Need More Info / Query me', 'reply do', BASE_URL.'/echs_claims.php?scheme=ECHS&cat=query'];
    $oldPend = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NOT NULL AND DATEDIFF(CURDATE(),accept_date) > 60")->fetch()['n'];
    if ($oldPend > 0) $alerts[] = ['warn', number_format($oldPend).' claims 60+ din se pending/in-process', 'follow-up', BASE_URL.'/echs_claims.php?scheme=ECHS&cat=pending&age=60'];
    $noDoc = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE doctor_name IS NULL OR doctor_name=''")->fetch()['n'];
    if ($noDoc > 0) $alerts[] = ['warn', number_format($noDoc).' claims bina doctor assign', 'assign karo', BASE_URL.'/echs_doctors.php?scheme=ECHS'];
    $odtask = 0;
    try { $odtask = (int)$pdo->query("SELECT COUNT(*) n FROM echs_tasks WHERE done=0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetch()['n']; } catch (Exception $e) {}
    if ($odtask > 0) $alerts[] = ['danger', $odtask.' task overdue', '', BASE_URL.'/echs_tasks.php?scheme=ECHS'];
}

if ($total === 0) {
    require __DIR__ . '/header.php'; ?>
    <div class="page-head"><h1>🎖️ ECHS Dashboard</h1></div>
    <div class="card" style="text-align:center;padding:48px 20px">
        <h2>Abhi koi ECHS claim nahi hai</h2>
        <p class="muted">ECHS portal se CLAIMLIST files (Excel) download karke upload karein — saari ek saath daal dijiye, dashboard apne aap ban jaayega.</p>
        <p style="margin-top:16px"><a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">⬆ Excel Upload karein</a></p>
    </div>
    <?php require __DIR__ . '/footer.php'; return;
}

// category counts + amounts
$cat = ['settled'=>0,'inprocess'=>0,'query'=>0,'pending'=>0,'rejected'=>0,'cancelled'=>0];
$catAmt = $cat;
foreach ($pdo->query("SELECT category, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims GROUP BY category") as $r) {
    $c = $r['category'] ?: 'pending'; if (isset($cat[$c])) { $cat[$c]+=(int)$r['n']; $catAmt[$c]+=(float)$r['amt']; }
}
$sum = $pdo->query("SELECT COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(approved_amt),0) appr FROM echs_claims")->fetch();
$settledAmt = $pdo->query("SELECT COALESCE(SUM(approved_amt),0) s FROM echs_claims WHERE category='settled'")->fetch()['s'];
$pend = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims WHERE ".echs_pending_condition())->fetch();

// OPD/IPD split
$types = [];
foreach ($pdo->query("SELECT patient_type t, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims GROUP BY patient_type ORDER BY n DESC") as $r) $types[] = $r;

// monthly (by accept_date)
$monthly = $pdo->query("SELECT DATE_FORMAT(accept_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt
    FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 15")->fetchAll();
$monthly = array_reverse($monthly);
$maxM = 1; foreach ($monthly as $m) $maxM = max($maxM, (float)$m['amt']);

// stage funnel (top stages by count)
$stages = $pdo->query("SELECT status, COUNT(*) n FROM echs_claims WHERE status IS NOT NULL GROUP BY status ORDER BY n DESC LIMIT 8")->fetchAll();

// top doctors
$docs = $pdo->query("SELECT doctor_name, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims
    WHERE doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name ORDER BY n DESC LIMIT 10")->fetchAll();

// recent status changes
$changes = $pdo->query("SELECT h.claim_id, h.from_status, h.to_status, h.changed_at, c.patient_name
    FROM echs_claim_history h LEFT JOIN echs_claims c ON c.claim_id = h.claim_id
    WHERE h.from_status IS NOT NULL ORDER BY h.changed_at DESC LIMIT 10")->fetchAll();

$totCat = array_sum($cat) ?: 1;
$pS = round($cat['settled']/$totCat*100,1);
$pI = round($cat['inprocess']/$totCat*100,1);
$pQ = round($cat['query']/$totCat*100,1);
$pP = round($cat['pending']/$totCat*100,1);
$pR = round(($cat['rejected']+$cat['cancelled'])/$totCat*100,1);
$g1=$pS; $g2=$pS+$pI; $g3=$pS+$pI+$pQ; $g4=$pS+$pI+$pQ+$pP;

function emlabel($ym){ $ts=strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }

require __DIR__ . '/header.php';
?>
<div class="page-head">
    <h1>🎖️ ECHS Dashboard</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">⬆ Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All Claims</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_reports.php?scheme=ECHS">📊 Reports</a>
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
    <div class="stat-card ok"><div class="stat-num"><?= inr($settledAmt,0) ?></div><div class="stat-lbl">Settled (received)</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($pend['n']) ?></div><div class="stat-lbl">Pending / in-process</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format($cat['query']) ?></div><div class="stat-lbl">Query / Need info</div></div>
</div>

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
    <p class="muted small" style="margin-top:10px">Is mahine settled/received: <strong><?= money($achRecv) ?></strong></p>
    <?php else: ?>
    <p class="muted small" style="margin-top:8px">Is mahine ka target set nahi. Upar "Target set/edit" se laga dijiye.</p>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Claim status</h2>
        <div class="donut-wrap">
            <div class="donut" style="background:conic-gradient(#16A34A 0 <?= $g1 ?>%,#0ea5e9 <?= $g1 ?>% <?= $g2 ?>%,#8b5cf6 <?= $g2 ?>% <?= $g3 ?>%,#f59e0b <?= $g3 ?>% <?= $g4 ?>%,#dc3545 <?= $g4 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($totCat) ?></strong><span>claims</span></div>
            </div>
            <ul class="legend">
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&cat=settled"><span class="lg" style="background:#16A34A"></span> Settled — <?= number_format($cat['settled']) ?> (<?= $pS ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&cat=inprocess"><span class="lg" style="background:#0ea5e9"></span> In-process — <?= number_format($cat['inprocess']) ?> (<?= $pI ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&cat=query"><span class="lg" style="background:#8b5cf6"></span> Query — <?= number_format($cat['query']) ?> (<?= $pQ ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&cat=pending"><span class="lg" style="background:#f59e0b"></span> Pending — <?= number_format($cat['pending']) ?> (<?= $pP ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&cat=rejected"><span class="lg" style="background:#dc3545"></span> Rejected/Cancel — <?= number_format($cat['rejected']+$cat['cancelled']) ?> (<?= $pR ?>%)</a></li>
            </ul>
        </div>
    </div>
    <div class="card">
        <h2>OPD / IPD & amounts</h2>
        <table class="tbl">
            <thead><tr><th>Type</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead>
            <tbody>
            <?php foreach ($types as $t): ?>
                <tr><td><strong><?= e(echs_ptype($t['t'])) ?></strong></td><td class="r"><?= number_format($t['n']) ?></td><td class="r"><?= inr($t['amt'],0) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <table class="kv" style="margin-top:8px">
            <tr><td>Settled amount</td><th class="ok"><?= money($catAmt['settled']) ?></th></tr>
            <tr><td>Pending (claimed)</td><th><?= money($pend['amt']) ?></th></tr>
        </table>
    </div>
</div>

<div class="card">
    <h2>Monthly — Claims accepted (amount)</h2>
    <?php if (!$monthly): ?><p class="muted">Data nahi.</p><?php else: ?>
    <div class="barchart">
        <?php foreach ($monthly as $m): $h = round((float)$m['amt']/$maxM*150); ?>
            <div class="bar-col" title="<?= emlabel($m['ym']) ?>: <?= money($m['amt']) ?> (<?= $m['n'] ?>)">
                <div class="bar" style="height:<?= max(3,$h) ?>px"></div>
                <div class="bar-lbl"><?= emlabel($m['ym']) ?></div>
                <div class="bar-val"><?= $m['amt']>=100000?number_format($m['amt']/100000,1).'L':number_format($m['amt']/1000,0).'k' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Stage funnel</h2>
        <?php if (!$stages): ?><p class="muted">Data nahi.</p><?php else: $mx=$stages[0]['n']?:1; ?>
        <?php foreach ($stages as $s): $w=round($s['n']/$mx*100); ?>
            <a class="link" style="display:block;margin:0 0 8px" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&status=<?= urlencode($s['status']) ?>">
                <div style="display:flex;justify-content:space-between;font-size:.82rem"><span><?= e($s['status']) ?></span><strong><?= number_format($s['n']) ?></strong></div>
                <div style="background:var(--line);border-radius:999px;height:8px;overflow:hidden;margin-top:3px"><div style="background:var(--brand);height:100%;width:<?= $w ?>%"></div></div>
            </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Recent status changes</h2>
        <?php if (!$changes): ?><p class="muted">Abhi koi change nahi.</p><?php else: ?>
        <ul class="timeline">
            <?php foreach ($changes as $ch): ?>
                <li><span class="tl-date"><?= e(date('d-m-y', strtotime($ch['changed_at']))) ?></span>
                    <span class="small"><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($ch['claim_id']) ?>"><?= e($ch['patient_name'] ?: $ch['claim_id']) ?></a>:
                    <?= e($ch['from_status']) ?> → <strong><?= e($ch['to_status']) ?></strong></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($docs): ?>
<div class="card">
    <h2>Top doctors</h2>
    <table class="tbl">
        <thead><tr><th>Doctor</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $d): ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&doctor=<?= urlencode($d['doctor_name']) ?>"><?= e($d['doctor_name']) ?></a></td>
                <td class="r"><?= number_format($d['n']) ?></td><td class="r"><?= inr($d['amt'],0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
