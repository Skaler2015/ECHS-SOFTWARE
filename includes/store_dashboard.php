<?php
/** RGHS Medical Store (Pharmacy) dashboard. Included from dashboard.php when scheme=STORE. */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/icons.php';
store_ensure_table();

$active = 'dashboard';
$page_title = 'Medical Store Dashboard';
$pdo = db();

// set monthly target (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'settarget') {
    csrf_check();
    $ym = preg_match('/^\d{4}-\d{2}$/', $_POST['ym'] ?? '') ? $_POST['ym'] : date('Y-m');
    $pdo->prepare("INSERT INTO store_targets (ym,claims_target,amount_target) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE claims_target=VALUES(claims_target), amount_target=VALUES(amount_target)")
        ->execute([$ym, (int)($_POST['claims_target'] ?? 0), (float)preg_replace('/[^0-9.]/','',$_POST['amount_target'] ?? '0')]);
    store_log('set_target', "$ym");
    flash('Target set ho gaya.');
    redirect(BASE_URL.'/dashboard.php?scheme=STORE');
}

$total = (int)$pdo->query("SELECT COUNT(*) n FROM store_claims")->fetch()['n'];

// keep a daily safety snapshot (best-effort, once per day)
if ($total > 0) { @store_daily_snapshot(); }

// empty state
if ($total === 0) {
    require __DIR__ . '/header.php'; ?>
    <div class="page-head"><h1>💊 Medical Store Dashboard</h1></div>
    <div class="card" style="text-align:center;padding:48px 20px">
        <h2>Abhi koi medical store invoice nahi hai</h2>
        <p class="muted">RGHS portal se Pharmacy Invoice Tracker (Excel) download karke upload karein — dashboard apne aap ban jaayega.</p>
        <p style="margin-top:16px"><a class="btn btn-primary" href="<?= BASE_URL ?>/store_upload.php?scheme=STORE">⬆ Excel Upload karein</a></p>
    </div>
    <?php require __DIR__ . '/footer.php'; return;
}

// this-month target vs achieved (by submit_date)
$thisYm = date('Y-m');
$tgt = store_target($thisYm);
$mAch = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM store_claims WHERE (YEAR(submit_date)*100+MONTH(submit_date))=?");
$mAch->execute([(int)str_replace('-','',$thisYm)]); $ach = $mAch->fetch();

// category counts + amounts (deleted folds into the red / rejected slice)
$cat = ['approved'=>0,'pending'=>0,'query'=>0,'rejected'=>0];
$catAmt = $cat;
foreach ($pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM store_claims GROUP BY status") as $r) {
    $c = store_category($r['status']); if ($c === 'deleted') $c = 'rejected';
    $cat[$c]+=(int)$r['n']; $catAmt[$c]+=(float)$r['amt'];
}

$sum = $pdo->query("SELECT COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu, COALESCE(SUM(tpa_amt),0) tpa FROM store_claims")->fetch();
$pend = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM store_claims WHERE ".store_pending_condition())->fetch();
$rejDel = (int)$pdo->query("SELECT COUNT(*) n FROM store_claims WHERE category IN ('rejected','deleted')")->fetch()['n'];

// monthly (by submit_date)
$monthly = $pdo->query("SELECT DATE_FORMAT(submit_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt
    FROM store_claims WHERE submit_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 15")->fetchAll();
$monthly = array_reverse($monthly);
$maxM = 1; foreach ($monthly as $m) $maxM = max($maxM, (float)$m['amt']);

// yearly summary
$yearly = $pdo->query("SELECT sub_year, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu
    FROM store_claims WHERE sub_year IS NOT NULL GROUP BY sub_year ORDER BY sub_year DESC")->fetchAll();

// recent status changes
$changes = $pdo->query("SELECT h.invoice_no, h.from_status, h.to_status, h.changed_at, c.patient_name
    FROM store_claim_history h LEFT JOIN store_claims c ON c.invoice_no = h.invoice_no COLLATE utf8mb4_unicode_ci
    WHERE h.from_status IS NOT NULL ORDER BY h.changed_at DESC LIMIT 10")->fetchAll();

$totCat = array_sum($cat) ?: 1;
$pA = round($cat['approved']/$totCat*100,1);
$pP = round($cat['pending']/$totCat*100,1);
$pQ = round($cat['query']/$totCat*100,1);
$pR = round($cat['rejected']/$totCat*100,1);
$g1=$pA; $g2=$pA+$pP; $g3=$pA+$pP+$pQ;

function sdml($ym){ $ts=strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }

require __DIR__ . '/header.php';
?>
<div class="page-head">
    <h1>💊 Medical Store Dashboard</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/store_upload.php?scheme=STORE">⬆ Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE">All Invoices</a>
        <a class="btn" href="<?= BASE_URL ?>/store_reports.php?scheme=STORE">📊 Reports</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($total) ?></div><div class="stat-lbl">Total invoices</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($sum['claim'],0) ?></div><div class="stat-lbl">Claimed amount</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($sum['cu'],0) ?></div><div class="stat-lbl">CU Approved</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($pend['n']) ?></div><div class="stat-lbl">Pending / in-process</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($rejDel) ?></div><div class="stat-lbl">Rejected / Deleted</div></div>
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
                <input name="claims_target" type="number" placeholder="Invoices target" value="<?= $tgtClaims?:'' ?>" style="padding:8px;border:1px solid var(--line);border-radius:8px;width:130px">
                <input name="amount_target" placeholder="Amount target ₹" value="<?= $tgtAmt?:'' ?>" style="padding:8px;border:1px solid var(--line);border-radius:8px;width:150px">
                <button class="btn btn-primary">Save</button>
            </form>
        </details>
    </div>
    <?php if ($tgtClaims>0 || $tgtAmt>0): ?>
    <div class="detail-grid" style="margin-top:12px">
        <div>
            <div class="muted small">Invoices: <strong><?= number_format($ach['n']) ?></strong> / <?= number_format($tgtClaims) ?> (<?= $pc ?>%)</div>
            <div style="background:var(--line);border-radius:999px;height:10px;overflow:hidden;margin-top:6px"><div style="background:var(--brand);height:100%;width:<?= $pc ?>%"></div></div>
        </div>
        <div>
            <div class="muted small">Claimed ₹: <strong><?= inr($ach['amt'],0) ?></strong> / <?= inr($tgtAmt,0) ?> (<?= $pa ?>%)</div>
            <div style="background:var(--line);border-radius:999px;height:10px;overflow:hidden;margin-top:6px"><div style="background:var(--gold);height:100%;width:<?= $pa ?>%"></div></div>
        </div>
    </div>
    <?php else: ?>
    <p class="muted small" style="margin-top:8px">Is mahine ka target set nahi. Upar "Target set/edit" se laga dijiye.</p>
    <?php endif; ?>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Invoice status</h2>
        <div class="donut-wrap">
            <div class="donut" style="background:conic-gradient(#16A34A 0 <?= $g1 ?>%,#f59e0b <?= $g1 ?>% <?= $g2 ?>%,#0ea5e9 <?= $g2 ?>% <?= $g3 ?>%,#dc3545 <?= $g3 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($totCat) ?></strong><span>invoices</span></div>
            </div>
            <ul class="legend">
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE&cat=approved"><span class="lg" style="background:#16A34A"></span> Approved — <?= number_format($cat['approved']) ?> (<?= $pA ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE&cat=pending"><span class="lg" style="background:#f59e0b"></span> Pending — <?= number_format($cat['pending']) ?> (<?= $pP ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE&cat=query"><span class="lg" style="background:#0ea5e9"></span> Query — <?= number_format($cat['query']) ?> (<?= $pQ ?>%)</a></li>
                <li><a class="link" style="margin:0" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE&cat=rejected"><span class="lg" style="background:#dc3545"></span> Rejected/Deleted — <?= number_format($cat['rejected']) ?> (<?= $pR ?>%)</a></li>
            </ul>
        </div>
        <table class="kv" style="margin-top:8px">
            <tr><td>Approved amount</td><th class="ok"><?= money($catAmt['approved']) ?></th></tr>
            <tr><td>Pending amount</td><th><?= money($pend['amt']) ?></th></tr>
        </table>
    </div>
    <div class="card">
        <h2>Yearly summary</h2>
        <table class="tbl">
            <thead><tr><th>Year</th><th class="r">Invoices</th><th class="r">Claimed</th><th class="r">CU Approved</th></tr></thead>
            <tbody>
            <?php foreach ($yearly as $y): ?>
                <tr><td><strong><?= e($y['sub_year']) ?></strong></td><td class="r"><?= number_format($y['n']) ?></td>
                    <td class="r"><?= inr($y['claim'],0) ?></td><td class="r"><?= inr($y['cu'],0) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$yearly): ?><tr><td colspan="4" class="muted">Data nahi.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Monthly — invoices submitted (amount)</h2>
    <?php if (!$monthly): ?><p class="muted">Data nahi.</p><?php else: ?>
    <div class="barchart">
        <?php foreach ($monthly as $m): $h = round((float)$m['amt']/$maxM*150); ?>
            <div class="bar-col" title="<?= sdml($m['ym']) ?>: <?= money($m['amt']) ?> (<?= $m['n'] ?>)">
                <div class="bar" style="height:<?= max(3,$h) ?>px"></div>
                <div class="bar-lbl"><?= sdml($m['ym']) ?></div>
                <div class="bar-val"><?= $m['amt']>=100000?number_format($m['amt']/100000,1).'L':number_format($m['amt']/1000,0).'k' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Recent status changes</h2>
    <?php if (!$changes): ?><p class="muted">Abhi koi change nahi.</p><?php else: ?>
    <ul class="timeline">
        <?php foreach ($changes as $ch): ?>
            <li><span class="tl-date"><?= e(date('d-m-y', strtotime($ch['changed_at']))) ?></span>
                <span class="small"><a class="link" href="<?= BASE_URL ?>/store_claim.php?scheme=STORE&inv=<?= urlencode($ch['invoice_no']) ?>"><?= e($ch['patient_name'] ?: $ch['invoice_no']) ?></a>:
                <?= e($ch['from_status']) ?> → <strong><?= e($ch['to_status']) ?></strong></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
