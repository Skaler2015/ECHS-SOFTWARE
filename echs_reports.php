<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'reports';
$page_title = 'Reports Center';

$pdo = db();

/* ---------- report registry (grouped) ---------- */
$REPORTS = [
    'Operations' => [
        'overview'   => 'Overview',
        'aging'      => 'Aging Analysis',
        'tat'        => 'Turnaround Time',
        'rejection'  => 'Rejection Analysis',
        'reconcile'  => 'Reconciliation',
        'duplicate'  => 'Duplicate Claims',
        'quality'    => 'Data Quality',
    ],
    'Financial' => [
        'outstanding'=> 'Outstanding Dues',
        'shortfall'  => 'Shortfall / Deductions',
        'tds'        => 'TDS, BPA & Discount',
        'pnl'        => 'P&L Summary',
        'realization'=> 'Realization Trend',
        'cashflow'   => 'Cash Flow Projection',
        'tax'        => 'Annual Tax Summary',
    ],
    'Trends' => [
        'trends'     => 'Month-wise Trends',
        'quarterly'  => 'Quarterly Summary',
        'distribution'=>'Amount Distribution',
    ],
    'Segments' => [
        'region'     => 'Region Analysis',
        'dept'       => 'OPD / IPD (Dept)',
        'doctor'     => 'Doctor Workload',
        'topesm'     => 'Top ESM / Cards',
    ],
];
$allKeys = [];
foreach ($REPORTS as $g => $items) foreach ($items as $k => $l) $allKeys[$k] = $l;

$r = $_GET['r'] ?? 'overview';
if (!isset($allKeys[$r])) $r = 'overview';
$reportTitle = $allKeys[$r];

/* ---------- shared helpers ---------- */
function mlabel($ym){ $ts = strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }

/** horizontal-free vertical bar chart from rows [label,val] */
function bars(array $rows, $barClass='bar'){
    if (!$rows) { echo '<p class="muted">Data nahi.</p>'; return; }
    $max = 1; foreach ($rows as $x) $max = max($max, (float)$x[1]);
    echo '<div class="barchart">';
    foreach ($rows as $x) {
        $h = max(3, round((float)$x[1]/$max*150));
        $v = $x[1] >= 100000 ? number_format($x[1]/100000,1).'L' : ($x[1]>=1000?number_format($x[1]/1000,0).'k':number_format($x[1]));
        echo '<div class="bar-col" title="'.e($x[0]).': '.e($v).'"><div class="'.$barClass.'" style="height:'.$h.'px"></div>'
           . '<div class="bar-lbl">'.e($x[0]).'</div><div class="bar-val">'.e($v).'</div></div>';
    }
    echo '</div>';
}

/** KPI stat cards from [label,value,cls] */
function kpis(array $cards){
    echo '<div class="stat-grid">';
    foreach ($cards as $c) {
        $cls = $c[2] ?? '';
        echo '<div class="stat-card '.e($cls).'"><div class="stat-num">'.$c[1].'</div><div class="stat-lbl">'.e($c[0]).'</div></div>';
    }
    echo '</div>';
}

// FY start-year SQL for a date expression (Apr-Mar). e.g. accept_date -> 2024 means FY 2024-25
function fyExpr($expr){ return "(YEAR($expr) - (MONTH($expr)<4))"; }
$SETTLE_DATE = "COALESCE(settle_date,processed_on)";

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>📊 Reports Center</h1>
    <div class="page-actions">
        <a class="btn" target="_blank" href="<?= BASE_URL ?>/echs_report_print.php?scheme=ECHS">🖨️ Print</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_xls.php?scheme=ECHS">⬇ Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_csv.php?scheme=ECHS">⬇ CSV</a>
    </div>
</div>

<div class="rpt-layout">
    <aside class="rpt-rail">
        <?php foreach ($REPORTS as $grp => $items): ?>
            <div class="rpt-grp"><?= e($grp) ?></div>
            <?php foreach ($items as $k => $l): ?>
                <a class="rpt-link <?= $r===$k?'on':'' ?>" href="<?= BASE_URL ?>/echs_reports.php?scheme=ECHS&r=<?= $k ?>"><?= e($l) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </aside>

    <div class="rpt-body">
    <h2 class="rpt-h"><?= e($reportTitle) ?></h2>
    <?php
    switch ($r):

    /* ========================= OVERVIEW ========================= */
    case 'overview':
        $cats = ['settled'=>0,'process'=>0,'rejected'=>0]; $catAmt = $cats;
        foreach ($pdo->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims GROUP BY status") as $x) {
            $c = echs_category($x['status']); $cats[$c]+=(int)$x['n']; $catAmt[$c]+=(float)$x['net'];
        }
        $tot = array_sum($cats) ?: 1;
        $sumRow = $pdo->query("SELECT COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app, COALESCE(SUM(amt_credited),0) cr FROM echs_claims")->fetch();
        $tatRow = $pdo->query("SELECT AVG(DATEDIFF(processed_on,accept_date)) d, COUNT(*) n FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL AND accept_date IS NOT NULL AND processed_on>=accept_date")->fetch();
        kpis([
            ['Total claims', number_format($tot)],
            ['Net claimed', money($sumRow['net'])],
            ['Approved', money($sumRow['app']), 'ok'],
            ['Credited (received)', money($sumRow['cr']), 'info'],
            ['Avg settle time', $tatRow['d']!==null?round($tatRow['d']).' din':'-', 'warn'],
        ]);
        $pS=round($cats['settled']/$tot*100,1); $pP=round($cats['process']/$tot*100,1); $pR=round($cats['rejected']/$tot*100,1);
        $g1=$pS; $g2=$pS+$pP;
        ?>
        <div class="detail-grid">
          <div class="card"><h2>Claim Category</h2>
            <div class="donut-wrap">
              <div class="donut" style="background:conic-gradient(#198754 0 <?= $g1 ?>%,#f59e0b <?= $g1 ?>% <?= $g2 ?>%,#dc3545 <?= $g2 ?>% 100%)">
                <div class="donut-hole"><strong><?= number_format($tot) ?></strong><span>claims</span></div></div>
              <ul class="legend">
                <li><span class="lg" style="background:#198754"></span> Settled — <?= number_format($cats['settled']) ?> (<?= $pS ?>%)</li>
                <li><span class="lg" style="background:#f59e0b"></span> In-process — <?= number_format($cats['process']) ?> (<?= $pP ?>%)</li>
                <li><span class="lg" style="background:#dc3545"></span> Rejected — <?= number_format($cats['rejected']) ?> (<?= $pR ?>%)</li>
              </ul></div></div>
          <div class="card"><h2>Amount by Category</h2>
            <table class="kv">
              <tr><td><span class="pill pill-settled">Settled</span></td><th><?= money($catAmt['settled']) ?></th></tr>
              <tr><td><span class="pill pill-process">In-process</span></td><th><?= money($catAmt['process']) ?></th></tr>
              <tr><td><span class="pill pill-rejected">Rejected</span></td><th><?= money($catAmt['rejected']) ?></th></tr>
            </table></div>
        </div>
        <?php
        break;

    /* ========================= AGING ========================= */
    case 'aging':
        $buckets = [
            ['0-30 din','DATEDIFF(CURDATE(),accept_date) BETWEEN 0 AND 30'],
            ['31-60 din','DATEDIFF(CURDATE(),accept_date) BETWEEN 31 AND 60'],
            ['61-90 din','DATEDIFF(CURDATE(),accept_date) BETWEEN 61 AND 90'],
            ['91-180 din','DATEDIFF(CURDATE(),accept_date) BETWEEN 91 AND 180'],
            ['180+ din','DATEDIFF(CURDATE(),accept_date) > 180'],
        ];
        $rows=[]; $barRows=[];
        foreach ($buckets as $b) {
            $x = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NOT NULL AND {$b[1]}")->fetch();
            $rows[] = [$b[0],(int)$x['n'],(float)$x['net']];
            $barRows[] = [$b[0],(float)$x['net']];
        }
        $noDate = $pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NULL")->fetch()['n'];
        kpis([
            ['Pending (91+ din)', number_format($rows[3][1]+$rows[4][1]), 'danger'],
            ['91+ din amount', money($rows[3][2]+$rows[4][2]), 'danger'],
            ['Bina accept-date', number_format($noDate), 'warn'],
        ]);
        echo '<div class="card"><h2>Aging (pending claims, net amount)</h2>'; bars($barRows); echo '</div>';
        echo '<div class="card"><table class="tbl"><thead><tr><th>Bucket</th><th class="r">Claims</th><th class="r">Net Amount</th></tr></thead><tbody>';
        foreach ($rows as $x) echo '<tr><td>'.e($x[0]).'</td><td class="r">'.number_format($x[1]).'</td><td class="r">'.money($x[2]).'</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= TAT ========================= */
    case 'tat':
        $tatRow = $pdo->query("SELECT AVG(DATEDIFF(processed_on,accept_date)) a, MIN(DATEDIFF(processed_on,accept_date)) mn, MAX(DATEDIFF(processed_on,accept_date)) mx, COUNT(*) n FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL AND accept_date IS NOT NULL AND processed_on>=accept_date")->fetch();
        kpis([
            ['Avg turnaround', $tatRow['a']!==null?round($tatRow['a']).' din':'-'],
            ['Fastest', $tatRow['mn']!==null?(int)$tatRow['mn'].' din':'-','ok'],
            ['Slowest', $tatRow['mx']!==null?(int)$tatRow['mx'].' din':'-','danger'],
            ['Settled (dated)', number_format((int)$tatRow['n'])],
        ]);
        $tbuckets = [['0-15',0,15],['16-30',16,30],['31-45',31,45],['46-60',46,60],['61-90',61,90],['90+',91,99999]];
        $barRows=[];
        foreach ($tbuckets as $b) {
            $n = $pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL AND accept_date IS NOT NULL AND DATEDIFF(processed_on,accept_date) BETWEEN {$b[1]} AND {$b[2]}")->fetch()['n'];
            $barRows[] = [$b[0].' din', (int)$n];
        }
        echo '<div class="card"><h2>Settlement Speed (kitne claims)</h2>'; bars($barRows,'bar bar-ok'); echo '</div>';
        // monthly avg TAT
        $mt = $pdo->query("SELECT DATE_FORMAT(processed_on,'%Y-%m') ym, AVG(DATEDIFF(processed_on,accept_date)) a FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL AND accept_date IS NOT NULL AND processed_on>=accept_date GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
        $mt = array_reverse($mt); $br=[];
        foreach ($mt as $m) $br[] = [mlabel($m['ym']), round($m['a'])];
        echo '<div class="card"><h2>Monthly Avg TAT (din)</h2>'; bars($br); echo '</div>';
        break;

    /* ========================= REJECTION ========================= */
    case 'rejection':
        $rej = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE status LIKE '%Reject%' OR status LIKE '%Cancel%'")->fetch();
        $tot = $pdo->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'] ?: 1;
        kpis([
            ['Rejected/Cancelled', number_format($rej['n']), 'danger'],
            ['Rejected amount', money($rej['net']), 'danger'],
            ['Rejection rate', round($rej['n']/$tot*100,1).'%', 'warn'],
        ]);
        $bym = $pdo->query("SELECT DATE_FORMAT(accept_date,'%Y-%m') ym, COUNT(*) n FROM echs_claims WHERE (status LIKE '%Reject%' OR status LIKE '%Cancel%') AND accept_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
        $bym=array_reverse($bym); $br=[]; foreach($bym as $m) $br[]=[mlabel($m['ym']),(int)$m['n']];
        echo '<div class="card"><h2>Rejections by Month</h2>'; bars($br,'bar bar-bad'); echo '</div>';
        $byr = $pdo->query("SELECT COALESCE(NULLIF(region,''),'—') region, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE status LIKE '%Reject%' OR status LIKE '%Cancel%' GROUP BY region ORDER BY n DESC LIMIT 20")->fetchAll();
        echo '<div class="card"><h2>Rejections by Region</h2><table class="tbl"><thead><tr><th>Region</th><th class="r">Claims</th><th class="r">Amount</th></tr></thead><tbody>';
        foreach($byr as $x) echo '<tr><td>'.e($x['region']).'</td><td class="r">'.number_format($x['n']).'</td><td class="r">'.money($x['net']).'</td></tr>';
        if(!$byr) echo '<tr><td colspan="3" class="muted">Koi rejection nahi.</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= RECONCILE ========================= */
    case 'reconcile':
        // settled claims where credited != approved
        $sum = $pdo->query("SELECT COALESCE(SUM(approved_amt),0) app, COALESCE(SUM(amt_credited),0) cr, COALESCE(SUM(tds_amt),0) tds, COALESCE(SUM(bpa_fees),0) bpa, COALESCE(SUM(recovery_amt),0) rec, COALESCE(SUM(echs_disc),0) disc FROM echs_claims WHERE status LIKE '%Settled%'")->fetch();
        $gap = $sum['app'] - $sum['cr'];
        kpis([
            ['Approved (settled)', money($sum['app'])],
            ['Credited', money($sum['cr']), 'ok'],
            ['Gap (approved-credited)', money($gap), $gap>0?'danger':'ok'],
            ['TDS + BPA + Recovery', money($sum['tds']+$sum['bpa']+$sum['rec']), 'warn'],
        ]);
        echo '<p class="muted small">Gap ≈ TDS + BPA fees + Recovery + ECHS discount ke barabar hona chahiye. Bada antar ho to jaanchein.</p>';
        // per-claim mismatches (credited < approved by > 1 after known deductions)
        $rows = $pdo->query("SELECT * FROM (
              SELECT claim_id,card_id,esm_name,approved_amt,amt_credited,tds_amt,bpa_fees,recovery_amt,echs_disc,
                (approved_amt-amt_credited-tds_amt-bpa_fees-recovery_amt-echs_disc) diff
              FROM echs_claims WHERE status LIKE '%Settled%' AND amt_credited>0
            ) t WHERE ABS(diff) > 1 ORDER BY ABS(diff) DESC LIMIT 100")->fetchAll();
        echo '<div class="card"><h2>Unexplained differences ('.count($rows).')</h2><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Claim</th><th>ESM</th><th class="r">Approved</th><th class="r">Credited</th><th class="r">Deductions</th><th class="r">Unexplained</th></tr></thead><tbody>';
        foreach($rows as $x){ $ded=$x['tds_amt']+$x['bpa_fees']+$x['recovery_amt']+$x['echs_disc'];
            echo '<tr><td><a class="link" href="'.BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($x['claim_id']).'">'.e($x['claim_id']).'</a></td><td>'.e($x['esm_name']).'</td><td class="r">'.inr($x['approved_amt'],0).'</td><td class="r">'.inr($x['amt_credited'],0).'</td><td class="r">'.inr($ded,0).'</td><td class="r"><strong>'.inr($x['diff'],0).'</strong></td></tr>'; }
        if(!$rows) echo '<tr><td colspan="6" class="muted">Sab reconciled ✅</td></tr>';
        echo '</tbody></table></div></div>';
        break;

    /* ========================= DUPLICATE ========================= */
    case 'duplicate':
        $rows = $pdo->query("SELECT card_id, net_claim_amt, accept_date, COUNT(*) n, GROUP_CONCAT(claim_id SEPARATOR ', ') ids, MAX(esm_name) esm
            FROM echs_claims WHERE card_id IS NOT NULL AND card_id<>'' AND net_claim_amt>0 AND accept_date IS NOT NULL
            GROUP BY card_id, net_claim_amt, accept_date HAVING n>1 ORDER BY n DESC, net_claim_amt DESC LIMIT 200")->fetchAll();
        kpis([['Suspected duplicate groups', number_format(count($rows)), count($rows)?'danger':'ok']]);
        echo '<p class="muted small">Same card + same amount + same accept-date. Jaanchein — asli duplicate ya legit repeat.</p>';
        echo '<div class="card"><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Card</th><th>ESM</th><th class="r">Amount</th><th>Date</th><th class="r">Count</th><th>Claim IDs</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td>'.e($x['card_id']).'</td><td>'.e($x['esm']).'</td><td class="r">'.inr($x['net_claim_amt'],0).'</td><td>'.e(date('d-m-Y',strtotime($x['accept_date']))).'</td><td class="r"><strong>'.$x['n'].'</strong></td><td class="small">'.e($x['ids']).'</td></tr>';
        if(!$rows) echo '<tr><td colspan="6" class="muted">Koi duplicate nahi mila ✅</td></tr>';
        echo '</tbody></table></div></div>';
        break;

    /* ========================= DATA QUALITY ========================= */
    case 'quality':
        $tot = $pdo->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'] ?: 1;
        $checks = [
            ['Bina Card ID', "card_id IS NULL OR card_id=''"],
            ['Bina ESM naam', "esm_name IS NULL OR esm_name=''"],
            ['Bina Patient naam', "patient_name IS NULL OR patient_name=''"],
            ['Bina Accept date', "accept_date IS NULL"],
            ['Bina Patient type (OPD/IPD)', "patient_type IS NULL OR patient_type=''"],
            ['Net amount = 0', "net_claim_amt=0"],
            ['Bina Doctor', "doctor_name IS NULL OR doctor_name=''"],
            ['Settled par credited=0', "status LIKE '%Settled%' AND amt_credited=0"],
        ];
        echo '<div class="card"><table class="tbl"><thead><tr><th>Check</th><th class="r">Claims</th><th class="r">%</th><th></th></tr></thead><tbody>';
        foreach($checks as $c){ $n=$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE {$c[1]}")->fetch()['n']; $pct=round($n/$tot*100,1);
            $cls=$pct>25?'pill-rejected':($pct>5?'pill-process':'pill-settled');
            echo '<tr><td>'.e($c[0]).'</td><td class="r">'.number_format($n).'</td><td class="r">'.$pct.'%</td><td><span class="pill '.$cls.'">'.($pct>25?'High':($pct>5?'Medium':'Low')).'</span></td></tr>'; }
        echo '</tbody></table></div><p class="muted small">Total claims: '.number_format($tot).'. Missing data zyada ho to reports adhoore rahenge.</p>';
        break;

    /* ========================= OUTSTANDING ========================= */
    case 'outstanding':
        $out = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE ".echs_pending_condition())->fetch();
        $old90 = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NOT NULL AND DATEDIFF(CURDATE(),accept_date)>90")->fetch();
        kpis([
            ['Outstanding claims', number_format($out['n']), 'warn'],
            ['Outstanding (net)', money($out['net']), 'danger'],
            ['90+ din stuck', money($old90['net']).' ('.number_format($old90['n']).')', 'danger'],
        ]);
        $rows = $pdo->query("SELECT claim_id,card_id,esm_name,net_claim_amt,accept_date,status, DATEDIFF(CURDATE(),accept_date) age
            FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NOT NULL ORDER BY net_claim_amt DESC LIMIT 100")->fetchAll();
        echo '<div class="card"><h2>Top outstanding claims</h2><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Claim</th><th>ESM</th><th class="r">Net</th><th>Status</th><th class="r">Age</th></tr></thead><tbody>';
        foreach($rows as $x){ $ac=$x['age']>90?'style="color:#dc3545;font-weight:600"':'';
            echo '<tr><td><a class="link" href="'.BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($x['claim_id']).'">'.e($x['claim_id']).'</a></td><td>'.e($x['esm_name']).'</td><td class="r">'.inr($x['net_claim_amt'],0).'</td><td class="small">'.e($x['status']).'</td><td class="r" '.$ac.'>'.(int)$x['age'].'d</td></tr>'; }
        echo '</tbody></table></div></div>';
        break;

    /* ========================= SHORTFALL ========================= */
    case 'shortfall':
        $sum = $pdo->query("SELECT COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE status LIKE '%Settled%' AND approved_amt>0")->fetch();
        $short = $sum['net']-$sum['app'];
        kpis([
            ['Claimed (settled)', money($sum['net'])],
            ['Approved', money($sum['app']),'ok'],
            ['Total shortfall', money($short>0?$short:0),'danger'],
            ['Shortfall %', $sum['net']>0?round(max(0,$short)/$sum['net']*100,1).'%':'-','warn'],
        ]);
        $rows = $pdo->query("SELECT claim_id,card_id,esm_name,net_claim_amt,approved_amt,(net_claim_amt-approved_amt) sf
            FROM echs_claims WHERE status LIKE '%Settled%' AND approved_amt>0 AND (net_claim_amt-approved_amt)>1 ORDER BY sf DESC LIMIT 100")->fetchAll();
        echo '<div class="card"><h2>Biggest shortfalls (claimed − approved)</h2><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Claim</th><th>ESM</th><th class="r">Claimed</th><th class="r">Approved</th><th class="r">Shortfall</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td><a class="link" href="'.BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($x['claim_id']).'">'.e($x['claim_id']).'</a></td><td>'.e($x['esm_name']).'</td><td class="r">'.inr($x['net_claim_amt'],0).'</td><td class="r">'.inr($x['approved_amt'],0).'</td><td class="r"><strong>'.inr($x['sf'],0).'</strong></td></tr>';
        if(!$rows) echo '<tr><td colspan="5" class="muted">Koi shortfall nahi ✅</td></tr>';
        echo '</tbody></table></div></div>';
        break;

    /* ========================= TDS / BPA / DISC ========================= */
    case 'tds':
        $sum = $pdo->query("SELECT COALESCE(SUM(tds_amt),0) tds, COALESCE(SUM(bpa_fees),0) bpa, COALESCE(SUM(echs_disc),0) disc, COALESCE(SUM(recovery_amt),0) rec FROM echs_claims")->fetch();
        kpis([
            ['Total TDS', money($sum['tds'])],
            ['BPA fees', money($sum['bpa']),'warn'],
            ['ECHS discount', money($sum['disc']),'info'],
            ['Recovery', money($sum['rec']),'danger'],
        ]);
        $rows = $pdo->query("SELECT ".fyExpr($SETTLE_DATE)." fy, COALESCE(SUM(tds_amt),0) tds, COALESCE(SUM(bpa_fees),0) bpa, COALESCE(SUM(echs_disc),0) disc FROM echs_claims WHERE tds_amt>0 OR bpa_fees>0 OR echs_disc>0 GROUP BY fy ORDER BY fy DESC")->fetchAll();
        echo '<div class="card"><h2>By Financial Year</h2><table class="tbl"><thead><tr><th>FY</th><th class="r">TDS</th><th class="r">BPA</th><th class="r">Discount</th></tr></thead><tbody>';
        foreach($rows as $x){ $fy=$x['fy']; $lbl=$fy?($fy.'-'.substr($fy+1,-2)):'—';
            echo '<tr><td>FY '.e($lbl).'</td><td class="r">'.inr($x['tds'],0).'</td><td class="r">'.inr($x['bpa'],0).'</td><td class="r">'.inr($x['disc'],0).'</td></tr>'; }
        if(!$rows) echo '<tr><td colspan="4" class="muted">Settlement report upload karein.</td></tr>';
        echo '</tbody></table><p class="muted small">TDS certificate milan ke liye — settlement PDF upload karne par bharta hai.</p></div>';
        break;

    /* ========================= P&L ========================= */
    case 'pnl':
        $s = $pdo->query("SELECT COALESCE(SUM(net_claim_amt),0) claimed, COALESCE(SUM(approved_amt),0) approved, COALESCE(SUM(amt_credited),0) credited, COALESCE(SUM(tds_amt),0) tds, COALESCE(SUM(bpa_fees),0) bpa, COALESCE(SUM(recovery_amt),0) rec, COALESCE(SUM(echs_disc),0) disc FROM echs_claims")->fetch();
        echo '<div class="card"><h2>Profit & Loss (lifetime)</h2><table class="kv">';
        $rowsPL = [
            ['Gross claimed', $s['claimed'], ''],
            ['Approved by ECHS', $s['approved'], 'ok'],
            ['(−) ECHS discount', -$s['disc'], 'muted'],
            ['(−) TDS deducted', -$s['tds'], 'muted'],
            ['(−) BPA fees', -$s['bpa'], 'muted'],
            ['(−) Recovery', -$s['rec'], 'muted'],
            ['Net credited (received)', $s['credited'], 'ok'],
        ];
        foreach($rowsPL as $p) echo '<tr><td>'.e($p[0]).'</td><th class="'.$p[2].'">'.money($p[1]).'</th></tr>';
        $disallowed = $s['claimed']-$s['approved'];
        echo '</table></div>';
        kpis([
            ['Disallowed (claimed−approved)', money($disallowed>0?$disallowed:0),'danger'],
            ['Approval rate', $s['claimed']>0?round($s['approved']/$s['claimed']*100,1).'%':'-','ok'],
            ['Realisation rate', $s['approved']>0?round($s['credited']/$s['approved']*100,1).'%':'-','info'],
        ]);
        break;

    /* ========================= REALIZATION ========================= */
    case 'realization':
        $m = $pdo->query("SELECT DATE_FORMAT(COALESCE(settle_date,processed_on),'%Y-%m') ym, COALESCE(SUM(amt_credited),0) cr FROM echs_claims WHERE amt_credited>0 AND COALESCE(settle_date,processed_on) IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 18")->fetchAll();
        $m=array_reverse($m); $br=[]; $running=0;
        foreach($m as $x){ $br[]=[mlabel($x['ym']),(float)$x['cr']]; $running+=$x['cr']; }
        kpis([
            ['Total realized', money($running),'ok'],
            ['Months with credit', number_format(count($m))],
            ['Avg / month', count($m)?money($running/count($m)):money(0),'info'],
        ]);
        echo '<div class="card"><h2>Amount Credited by Month</h2>'; bars($br,'bar bar-ok'); echo '</div>';
        echo '<div class="card"><table class="tbl"><thead><tr><th>Month</th><th class="r">Credited</th></tr></thead><tbody>';
        foreach(array_reverse($m) as $x) echo '<tr><td>'.mlabel($x['ym']).'</td><td class="r">'.money($x['cr']).'</td></tr>';
        if(!$m) echo '<tr><td colspan="2" class="muted">Settlement data nahi.</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= CASHFLOW ========================= */
    case 'cashflow':
        // realisation rate from history
        $s = $pdo->query("SELECT COALESCE(SUM(approved_amt),0) app, COALESCE(SUM(amt_credited),0) cr FROM echs_claims WHERE status LIKE '%Settled%'")->fetch();
        $rate = $s['app']>0 ? $s['cr']/$s['app'] : 0.9;
        // approval rate
        $a = $pdo->query("SELECT COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE status LIKE '%Settled%'")->fetch();
        $appRate = $a['net']>0 ? $a['app']/$a['net'] : 0.9;
        $pend = $pdo->query("SELECT COALESCE(SUM(net_claim_amt),0) net, COUNT(*) n FROM echs_claims WHERE ".echs_pending_condition())->fetch();
        $expected = $pend['net']*$appRate*$rate;
        kpis([
            ['Pending (net)', money($pend['net']),'warn'],
            ['Approval rate', round($appRate*100,1).'%'],
            ['Realisation rate', round($rate*100,1).'%'],
            ['Expected inflow', money($expected),'ok'],
        ]);
        echo '<div class="card"><h2>Projection</h2><table class="kv">';
        echo '<tr><td>Pending claim amount</td><th>'.money($pend['net']).'</th></tr>';
        echo '<tr><td>× Approval rate ('.round($appRate*100,1).'%)</td><th>'.money($pend['net']*$appRate).'</th></tr>';
        echo '<tr><td>× Realisation rate ('.round($rate*100,1).'%)</td><th class="ok">'.money($expected).'</th></tr>';
        echo '</table><p class="muted small">Historical approval & realisation rate ke aadhar par estimate — sirf guidance ke liye.</p></div>';
        break;

    /* ========================= ANNUAL TAX ========================= */
    case 'tax':
        $rows = $pdo->query("SELECT ".fyExpr($SETTLE_DATE)." fy, COUNT(*) n, COALESCE(SUM(amt_credited),0) cr, COALESCE(SUM(tds_amt),0) tds, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE status LIKE '%Settled%' AND $SETTLE_DATE IS NOT NULL GROUP BY fy ORDER BY fy DESC")->fetchAll();
        echo '<div class="card"><h2>Financial Year Summary</h2><table class="tbl"><thead><tr><th>FY</th><th class="r">Settled</th><th class="r">Approved</th><th class="r">Credited</th><th class="r">TDS</th></tr></thead><tbody>';
        foreach($rows as $x){ $fy=$x['fy']; $lbl=$fy?($fy.'-'.substr($fy+1,-2)):'—';
            echo '<tr><td><strong>FY '.e($lbl).'</strong></td><td class="r">'.number_format($x['n']).'</td><td class="r">'.inr($x['app'],0).'</td><td class="r">'.inr($x['cr'],0).'</td><td class="r">'.inr($x['tds'],0).'</td></tr>'; }
        if(!$rows) echo '<tr><td colspan="5" class="muted">Settlement data nahi.</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= TRENDS ========================= */
    case 'trends':
        $acc = $pdo->query("SELECT DATE_FORMAT(accept_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 18")->fetchAll();
        $acc=array_reverse($acc); $b1=[]; foreach($acc as $m) $b1[]=[mlabel($m['ym']),(float)$m['net']];
        $set = $pdo->query("SELECT DATE_FORMAT(processed_on,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE status LIKE '%Settled%' AND processed_on IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 18")->fetchAll();
        $set=array_reverse($set); $b2=[]; foreach($set as $m) $b2[]=[mlabel($m['ym']),(float)$m['app']];
        echo '<div class="card"><h2>Claims Accepted — Net Amount</h2>'; bars($b1); echo '</div>';
        echo '<div class="card"><h2>Claims Settled — Approved Amount</h2>'; bars($b2,'bar bar-ok'); echo '</div>';
        echo '<div class="card"><h2>Monthly Table</h2><table class="tbl"><thead><tr><th>Month</th><th class="r">Accepted</th><th class="r">Net</th></tr></thead><tbody>';
        foreach(array_reverse($acc) as $m) echo '<tr><td>'.mlabel($m['ym']).'</td><td class="r">'.number_format($m['n']).'</td><td class="r">'.money($m['net']).'</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= QUARTERLY ========================= */
    case 'quarterly':
        $rows = $pdo->query("SELECT ".fyExpr('accept_date')." fy, QUARTER(DATE_SUB(accept_date,INTERVAL 3 MONTH)) q, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY fy,q ORDER BY fy DESC, q DESC LIMIT 16")->fetchAll();
        echo '<div class="card"><h2>Quarterly (FY, Apr–Mar)</h2><table class="tbl"><thead><tr><th>Quarter</th><th class="r">Claims</th><th class="r">Net</th><th class="r">Approved</th></tr></thead><tbody>';
        foreach($rows as $x){ $fy=$x['fy']; $lbl=$fy?('Q'.$x['q'].' FY'.$fy.'-'.substr($fy+1,-2)):'—';
            echo '<tr><td>'.e($lbl).'</td><td class="r">'.number_format($x['n']).'</td><td class="r">'.money($x['net']).'</td><td class="r">'.money($x['app']).'</td></tr>'; }
        if(!$rows) echo '<tr><td colspan="4" class="muted">Data nahi.</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= DISTRIBUTION ========================= */
    case 'distribution':
        $rngs = [['< ₹10k',0,10000],['₹10k–25k',10000,25000],['₹25k–50k',25000,50000],['₹50k–1L',50000,100000],['₹1L–2L',100000,200000],['₹2L–5L',200000,500000],['₹5L+',500000,1e12]];
        $br=[]; $rows=[];
        foreach($rngs as $g){ $x=$pdo->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE net_claim_amt>={$g[1]} AND net_claim_amt<{$g[2]}")->fetch();
            $br[]=[$g[0],(int)$x['n']]; $rows[]=[$g[0],(int)$x['n'],(float)$x['net']]; }
        echo '<div class="card"><h2>Claim Amount Distribution (count)</h2>'; bars($br); echo '</div>';
        echo '<div class="card"><table class="tbl"><thead><tr><th>Range</th><th class="r">Claims</th><th class="r">Total Amount</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td>'.e($x[0]).'</td><td class="r">'.number_format($x[1]).'</td><td class="r">'.money($x[2]).'</td></tr>';
        echo '</tbody></table></div>';
        break;

    /* ========================= REGION ========================= */
    case 'region':
        $rows = $pdo->query("SELECT COALESCE(NULLIF(region,''),'—') region, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app, SUM(status LIKE '%Settled%') settled FROM echs_claims GROUP BY region ORDER BY net DESC LIMIT 40")->fetchAll();
        $br=[]; foreach(array_slice($rows,0,12) as $x) $br[]=[$x['region'],(float)$x['net']];
        echo '<div class="card"><h2>Net Amount by Region</h2>'; bars($br); echo '</div>';
        echo '<div class="card"><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Region</th><th class="r">Claims</th><th class="r">Settled</th><th class="r">Net</th><th class="r">Approved</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td>'.e($x['region']).'</td><td class="r">'.number_format($x['n']).'</td><td class="r">'.number_format($x['settled']).'</td><td class="r">'.money($x['net']).'</td><td class="r">'.money($x['app']).'</td></tr>';
        echo '</tbody></table></div></div>';
        break;

    /* ========================= DEPT (OPD/IPD) ========================= */
    case 'dept':
        $rows = $pdo->query("SELECT COALESCE(NULLIF(patient_type,''),'—') pt, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims GROUP BY pt ORDER BY n DESC")->fetchAll();
        $cards=[]; foreach($rows as $x) $cards[]=[strtoupper($x['pt']).' claims', number_format($x['n'])];
        kpis($cards);
        echo '<div class="card"><table class="tbl"><thead><tr><th>Type</th><th class="r">Claims</th><th class="r">Net</th><th class="r">Approved</th><th class="r">Avg</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td><strong>'.e(strtoupper($x['pt'])).'</strong></td><td class="r">'.number_format($x['n']).'</td><td class="r">'.money($x['net']).'</td><td class="r">'.money($x['app']).'</td><td class="r">'.money($x['n']?$x['net']/$x['n']:0).'</td></tr>';
        echo '</tbody></table><p class="muted small">P = OPD, I = IPD (portal ke hisaab se).</p></div>';
        break;

    /* ========================= DOCTOR ========================= */
    case 'doctor':
        $rows = $pdo->query("SELECT doctor_name, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app, SUM(status LIKE '%Settled%') settled FROM echs_claims WHERE doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name ORDER BY n DESC LIMIT 50")->fetchAll();
        $unassigned = $pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE doctor_name IS NULL OR doctor_name=''")->fetch()['n'];
        kpis([['Doctors', number_format(count($rows))],['Bina doctor', number_format($unassigned),'warn']]);
        $br=[]; foreach(array_slice($rows,0,12) as $x) $br[]=[$x['doctor_name'],(int)$x['n']];
        echo '<div class="card"><h2>Claims by Doctor</h2>'; bars($br); echo '</div>';
        echo '<div class="card"><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Doctor</th><th class="r">Claims</th><th class="r">Settled</th><th class="r">Net</th><th class="r">Approved</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td><a class="link" href="'.BASE_URL.'/echs_claims.php?scheme=ECHS&doctor='.urlencode($x['doctor_name']).'">'.e($x['doctor_name']).'</a></td><td class="r">'.number_format($x['n']).'</td><td class="r">'.number_format($x['settled']).'</td><td class="r">'.money($x['net']).'</td><td class="r">'.money($x['app']).'</td></tr>';
        if(!$rows) echo '<tr><td colspan="5" class="muted">Claims me doctor set karein.</td></tr>';
        echo '</tbody></table></div></div>';
        break;

    /* ========================= TOP ESM ========================= */
    case 'topesm':
        $rows = $pdo->query("SELECT card_id, MAX(esm_name) esm, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims WHERE card_id IS NOT NULL AND card_id<>'' GROUP BY card_id ORDER BY net DESC LIMIT 40")->fetchAll();
        echo '<div class="card"><div class="tbl-scroll"><table class="tbl"><thead><tr><th>Card</th><th>ESM</th><th class="r">Claims</th><th class="r">Net</th><th class="r">Approved</th></tr></thead><tbody>';
        foreach($rows as $x) echo '<tr><td><a class="link" href="'.BASE_URL.'/echs_card.php?scheme=ECHS&card='.urlencode($x['card_id']).'">'.e($x['card_id']).'</a></td><td>'.e($x['esm']).'</td><td class="r">'.number_format($x['n']).'</td><td class="r">'.money($x['net']).'</td><td class="r">'.money($x['app']).'</td></tr>';
        echo '</tbody></table></div></div>';
        break;

    endswitch;
    ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
