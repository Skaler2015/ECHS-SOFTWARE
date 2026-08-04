<?php
/**
 * ECHS premium analytics dashboard (included from dashboard.php when scheme=ECHS).
 * Read-only: builds everything from existing tables. No DB/logic changes.
 */
require_once __DIR__ . '/echs.php';
require_once __DIR__ . '/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$active = 'dashboard';
$page_title = 'ECHS Dashboard';

/* ---------- helpers ---------- */
function spark($vals, $w = 108, $h = 30, $color = '#2563EB') {
    $vals = array_values(array_map('floatval', $vals));
    if (count($vals) < 2) return '';
    $min = min($vals); $max = max($vals); $rng = ($max - $min) ?: 1;
    $n = count($vals); $step = $w / ($n - 1); $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - 2 - (($v - $min) / $rng) * ($h - 5), 1);
        $pts[] = "$x,$y";
    }
    $last = explode(',', end($pts));
    return '<svg class="spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
        . '<polyline fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>'
        . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="' . $color . '"/></svg>';
}
function pctChange($vals) {
    $vals = array_values(array_map('floatval', $vals));
    $n = count($vals); if ($n < 2) return null;
    $cur = $vals[$n-1]; $prev = $vals[$n-2];
    if ($prev == 0) return null;
    return ($cur - $prev) / $prev * 100;
}
function delta_badge($p) {
    if ($p === null) return '';
    $up = $p >= 0; $cls = $up ? 'up' : 'down'; $ar = $up ? '↑' : '↓';
    return '<span class="delta ' . $cls . '">' . $ar . ' ' . number_format(abs($p), 1) . '%</span>';
}
function mlabel($ym){ $ts = strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }
$pend = echs_pending_condition();

/* ---------- core totals ---------- */
$tot = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app,
    SUM(patient_type='I') ipd, SUM(patient_type='O') opd FROM echs_claims")->fetch();
$hasData = (int)$tot['n'] > 0;

if (!$hasData) {
    require __DIR__ . '/header.php'; ?>
    <div class="page-head"><h1>🎖️ ECHS Dashboard</h1></div>
    <div class="card" style="text-align:center;padding:48px">
        <div style="font-size:3rem">📥</div>
        <h2>Abhi tak koi claim data nahi</h2>
        <p class="muted">Portal se CLAIMLIST Excel (ya ZIP) upload karें — dashboard apne aap bhar jayega.</p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS"><?= icon('upload') ?> Pehli Excel upload karें</a>
    </div>
    <?php require __DIR__ . '/footer.php'; return;
}

$pendT   = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE $pend")->fetch();
$recv    = (float)db()->query("SELECT COALESCE(SUM(amount),0) s FROM echs_payments")->fetch()['s'];
$settled = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE status LIKE '%Settled%'")->fetch();
$deduction = (float)$settled['net'] - (float)$settled['app'];
$actionN = (int)db()->query("SELECT COUNT(*) n FROM echs_claims WHERE followup=1 OR status LIKE '%Need More Information%'")->fetch()['n'];

/* ---------- monthly series (last 12) ---------- */
$ma = db()->query("SELECT DATE_FORMAT(accept_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net
    FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
$ma = array_reverse($ma);
$ms = db()->query("SELECT DATE_FORMAT(processed_on,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(approved_amt),0) app
    FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
$ms = array_reverse($ms);
$countSeries = array_column($ma, 'n');
$netSeries   = array_column($ma, 'net');
$appSeries   = array_column($ms, 'app');

/* ---------- category split ---------- */
$cats = ['settled'=>0,'process'=>0,'rejected'=>0]; $catAmt = $cats;
foreach (db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims GROUP BY status") as $r) {
    $c = echs_category($r['status']); $cats[$c]+=(int)$r['n']; $catAmt[$c]+=(float)$r['net'];
}
$catTotal = array_sum($cats) ?: 1;
$pS = round($cats['settled']/$catTotal*100,1); $pP = round($cats['process']/$catTotal*100,1); $pR = round($cats['rejected']/$catTotal*100,1);
$g1=$pS; $g2=$pS+$pP;

/* ---------- aging (pending) ---------- */
$aging = db()->query("SELECT
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) BETWEEN 0 AND 30 THEN 1 ELSE 0 END) b30,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) b60,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) b90,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) > 90 THEN 1 ELSE 0 END) b90p,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) > 90 THEN net_claim_amt ELSE 0 END),0) amt90p
    FROM echs_claims WHERE $pend AND accept_date IS NOT NULL")->fetch();
$agAll = [$aging['b30'],$aging['b60'],$aging['b90'],$aging['b90p']];
$agMax = max(1, max($agAll));

/* ---------- widgets data ---------- */
$recentClaims = db()->query("SELECT claim_id,patient_name,status,net_claim_amt FROM echs_claims ORDER BY updated_at DESC, claim_id DESC LIMIT 7")->fetchAll();
$recentChg = db()->query("SELECT h.claim_id,h.from_status,h.to_status,h.changed_at,c.patient_name
    FROM echs_claim_history h LEFT JOIN echs_claims c ON c.claim_id = h.claim_id COLLATE utf8mb4_unicode_ci
    WHERE h.from_status IS NOT NULL ORDER BY h.id DESC LIMIT 7")->fetchAll();
$recentPay = db()->query("SELECT * FROM echs_payments ORDER BY id DESC LIMIT 6")->fetchAll();
$tasksToday = db()->query("SELECT * FROM echs_tasks WHERE done=0 ORDER BY (due_date IS NULL), due_date ASC LIMIT 7")->fetchAll();
$topPending = db()->query("SELECT claim_id,patient_name,net_claim_amt,status FROM echs_claims WHERE $pend ORDER BY net_claim_amt DESC LIMIT 7")->fetchAll();
$needInfo = db()->query("SELECT claim_id,patient_name,net_claim_amt FROM echs_claims WHERE status LIKE '%Need More Information%' ORDER BY net_claim_amt DESC LIMIT 7")->fetchAll();
$today = date('Y-m-d');

/* ---------- alerts ---------- */
$alerts = [];
$overdue = (int)db()->query("SELECT COUNT(*) n FROM echs_tasks WHERE done=0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetch()['n'];
if ($overdue>0) $alerts[] = ['t'=>"$overdue task overdue hain", 'u'=>BASE_URL.'/echs_tasks.php?scheme=ECHS', 'c'=>'danger'];
if ((int)$aging['b90p']>0) $alerts[] = ['t'=>number_format($aging['b90p'])." claim 90+ din se pending (".money($aging['amt90p']).")", 'u'=>BASE_URL.'/echs_pending.php?scheme=ECHS', 'c'=>'warn'];
if ($actionN>0) $alerts[] = ['t'=>"$actionN claim par action chahiye (Need More Info)", 'u'=>BASE_URL.'/echs_claims.php?scheme=ECHS', 'c'=>'warn'];

/* last upload */
$lastUp=null;$daysSince=null;
$lu=db()->query("SELECT MAX(uploaded_at) m FROM echs_uploads")->fetch();
if(!empty($lu['m'])){ $lastUp=$lu['m']; $daysSince=(int)floor((time()-strtotime($lu['m']))/86400); if($daysSince>=5) $alerts[]=['t'=>"$daysSince din se nayi Excel upload nahi hui",'u'=>BASE_URL.'/echs_upload.php?scheme=ECHS','c'=>'warn']; }

require __DIR__ . '/header.php';
?>
<div class="page-head">
    <div>
        <h1>🎖️ ECHS Dashboard</h1>
        <?php if ($lastUp): ?><div class="muted small">Aakhri upload: <?= fdate($lastUp) ?> (<?= $daysSince ?> din pehle)</div><?php endif; ?>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS"><?= icon('upload',16) ?> Upload Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS"><?= icon('file',16) ?> View Claims</a>
    </div>
</div>

<?php if ($alerts): ?>
<div class="alert-panel">
    <?php foreach ($alerts as $a): ?>
        <a class="alert-item a-<?= $a['c'] ?>" href="<?= $a['u'] ?>"><?= icon('bell',16) ?> <span><?= e($a['t']) ?></span> <?= icon('chevron',15) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KPI cards -->
<div class="kpi-grid">
    <a class="kpi" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">
        <div class="kpi-top"><span class="kpi-ic b"><?= icon('file') ?></span><?= delta_badge(pctChange($countSeries)) ?></div>
        <div class="kpi-num"><?= number_format($tot['n']) ?></div><div class="kpi-lbl">Total Claims</div>
        <?= spark($countSeries) ?>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&sort=net">
        <div class="kpi-top"><span class="kpi-ic v"><?= icon('chart') ?></span><?= delta_badge(pctChange($netSeries)) ?></div>
        <div class="kpi-num"><?= money($tot['net']) ?></div><div class="kpi-lbl">Net Claim Amt</div>
        <?= spark($netSeries, 108,30,'#7c3aed') ?>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&status=Claim%20Settled">
        <div class="kpi-top"><span class="kpi-ic g"><?= icon('card') ?></span><?= delta_badge(pctChange($appSeries)) ?></div>
        <div class="kpi-num"><?= money($tot['app']) ?></div><div class="kpi-lbl">Approved Amt</div>
        <?= spark($appSeries, 108,30,'#16A34A') ?>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/echs_pending.php?scheme=ECHS">
        <div class="kpi-top"><span class="kpi-ic o"><?= icon('clock') ?></span></div>
        <div class="kpi-num"><?= money($pendT['net']) ?></div><div class="kpi-lbl">Outstanding (<?= number_format($pendT['n']) ?>)</div>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&ptype=I">
        <div class="kpi-top"><span class="kpi-ic b"><?= icon('patients') ?></span></div>
        <div class="kpi-num"><?= number_format($tot['ipd']) ?></div><div class="kpi-lbl">IPD Claims</div>
    </a>
    <a class="kpi" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&ptype=O">
        <div class="kpi-top"><span class="kpi-ic b"><?= icon('patients') ?></span></div>
        <div class="kpi-num"><?= number_format($tot['opd']) ?></div><div class="kpi-lbl">OPD Claims</div>
    </a>
</div>

<!-- Financial snapshot -->
<div class="card">
    <div class="card-head"><h2>💰 Financial Snapshot</h2><a href="<?= BASE_URL ?>/echs_payments.php?scheme=ECHS">Payments →</a></div>
    <div class="fin-row">
        <div class="fin"><div class="fin-lbl">Approved (Settled)</div><div class="fin-num ok"><?= money($settled['app']) ?></div></div>
        <div class="fin-op">−</div>
        <div class="fin"><div class="fin-lbl">Received</div><div class="fin-num"><?= money($recv) ?></div></div>
        <div class="fin-op">=</div>
        <div class="fin"><div class="fin-lbl">Balance baaki</div><div class="fin-num <?= ($settled['app']-$recv)>0?'warn':'ok' ?>"><?= money($settled['app']-$recv) ?></div></div>
        <div class="fin-op">|</div>
        <div class="fin"><div class="fin-lbl">Total Deduction</div><div class="fin-num danger"><?= money($deduction) ?></div></div>
    </div>
</div>

<!-- Charts -->
<div class="card">
    <div class="card-head"><h2>📈 Monthly Claims (Net Amount)</h2><a href="<?= BASE_URL ?>/echs_reports.php?scheme=ECHS">Full reports →</a></div>
    <?php $mx = max(1, max($netSeries ?: [1])); ?>
    <div class="barchart">
        <?php foreach ($ma as $m): $hh = round((float)$m['net']/$mx*150); ?>
            <div class="bar-col" title="<?= mlabel($m['ym']) ?>: <?= money($m['net']) ?> (<?= $m['n'] ?> claims)">
                <div class="bar-val"><?= number_format($m['net']/100000,1) ?>L</div>
                <div class="bar" style="height:<?= max(3,$hh) ?>px"></div>
                <div class="bar-lbl"><?= mlabel($m['ym']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="dash-2">
    <div class="card">
        <h2>Approval vs Rejection</h2>
        <div class="donut-wrap">
            <div class="donut" style="background:conic-gradient(#16A34A 0 <?= $g1 ?>%, #F59E0B <?= $g1 ?>% <?= $g2 ?>%, #DC2626 <?= $g2 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($catTotal) ?></strong><span>claims</span></div>
            </div>
            <ul class="legend">
                <li><span class="lg" style="background:#16A34A"></span> Settled — <?= number_format($cats['settled']) ?> (<?= $pS ?>%)</li>
                <li><span class="lg" style="background:#F59E0B"></span> In-process — <?= number_format($cats['process']) ?> (<?= $pP ?>%)</li>
                <li><span class="lg" style="background:#DC2626"></span> Rejected/Cancel — <?= number_format($cats['rejected']) ?> (<?= $pR ?>%)</li>
            </ul>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Aging (Pending)</h2><a href="<?= BASE_URL ?>/echs_pending.php?scheme=ECHS">Detail →</a></div>
        <div class="barchart" style="height:170px">
            <?php foreach ([['0–30',$aging['b30'],'#16A34A'],['31–60',$aging['b60'],'#2563EB'],['61–90',$aging['b90'],'#F59E0B'],['90+',$aging['b90p'],'#DC2626']] as $b): $hh=round($b[1]/$agMax*130); ?>
                <div class="bar-col" title="<?= $b[0] ?> din: <?= number_format($b[1]) ?>">
                    <div class="bar-val"><?= number_format($b[1]) ?></div>
                    <div class="bar" style="height:<?= max(3,$hh) ?>px;background:<?= $b[2] ?>"></div>
                    <div class="bar-lbl"><?= $b[0] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Widgets -->
<div class="dash-2">
    <div class="card">
        <div class="card-head"><h2>🕑 Recent Claims</h2><a href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All →</a></div>
        <div class="mini-list">
        <?php foreach ($recentClaims as $c): ?>
            <a class="mini-row" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>">
                <span class="mr-main"><strong><?= e($c['claim_id']) ?></strong> · <?= e($c['patient_name']) ?></span>
                <span class="pill pill-<?= echs_category($c['status']) ?>"><?= e($c['status']) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>🔄 Recent Status Changes</h2><a href="<?= BASE_URL ?>/echs_compare.php?scheme=ECHS">All →</a></div>
        <div class="mini-list">
        <?php if(!$recentChg): ?><div class="muted small">Abhi koi badlaav nahi.</div><?php endif; ?>
        <?php foreach ($recentChg as $x): ?>
            <a class="mini-row" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($x['claim_id']) ?>">
                <span class="mr-main"><strong><?= e($x['claim_id']) ?></strong> <span class="muted small"><?= fdate($x['changed_at']) ?></span></span>
                <span class="pill pill-<?= echs_category($x['to_status']) ?>"><?= e($x['to_status']) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="dash-2">
    <div class="card">
        <div class="card-head"><h2>✅ Tasks</h2><a href="<?= BASE_URL ?>/echs_tasks.php?scheme=ECHS">All →</a></div>
        <div class="mini-list">
        <?php if(!$tasksToday): ?><div class="muted small">Koi pending task nahi 🎉</div><?php endif; ?>
        <?php foreach ($tasksToday as $t): $ov=$t['due_date']&&$t['due_date']<$today; ?>
            <div class="mini-row"><span class="mr-main"><?= e($t['title']) ?></span>
            <span class="<?= $ov?'pill pill-rejected':'muted small' ?>"><?= $t['due_date']?fdate($t['due_date']):'-' ?></span></div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>💵 Recent Payments</h2><a href="<?= BASE_URL ?>/echs_payments.php?scheme=ECHS">All →</a></div>
        <div class="mini-list">
        <?php if(!$recentPay): ?><div class="muted small">Abhi koi payment nahi.</div><?php endif; ?>
        <?php foreach ($recentPay as $p): ?>
            <div class="mini-row"><span class="mr-main"><?= fdate($p['pay_date']) ?> <span class="muted small"><?= e($p['claim_id']?:'') ?></span></span>
            <strong><?= money($p['amount']) ?></strong></div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="dash-2">
    <div class="card">
        <div class="card-head"><h2>💸 Top Pending (rakam)</h2><a href="<?= BASE_URL ?>/echs_pending.php?scheme=ECHS">All →</a></div>
        <div class="mini-list">
        <?php foreach ($topPending as $c): ?>
            <a class="mini-row" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>">
                <span class="mr-main"><strong><?= e($c['claim_id']) ?></strong> · <?= e($c['patient_name']) ?></span>
                <strong><?= money($c['net_claim_amt']) ?></strong>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>⚠️ Action Needed</h2><span class="pill pill-process"><?= number_format($actionN) ?></span></div>
        <div class="mini-list">
        <?php if(!$needInfo): ?><div class="muted small">Kuch pending nahi ✅</div><?php endif; ?>
        <?php foreach ($needInfo as $c): ?>
            <a class="mini-row" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>">
                <span class="mr-main"><strong><?= e($c['claim_id']) ?></strong> · <?= e($c['patient_name']) ?></span>
                <span><?= money($c['net_claim_amt']) ?></span>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Status-wise breakdown -->
<div class="card">
    <div class="card-head"><h2>Status-wise Breakdown</h2><a href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All claims →</a></div>
    <div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr><th>Status</th><th class="r">Claims</th><th class="r">Net Amt</th><th class="r">Approved</th><th></th></tr></thead>
        <tbody>
        <?php foreach (db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims GROUP BY status ORDER BY n DESC") as $s): ?>
            <tr>
                <td><span class="pill pill-<?= echs_category($s['status']) ?>"><?= e($s['status']) ?></span></td>
                <td class="r"><?= number_format($s['n']) ?></td>
                <td class="r"><?= money($s['net']) ?></td>
                <td class="r"><?= money($s['app']) ?></td>
                <td class="r"><a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&status=<?= urlencode($s['status']) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
