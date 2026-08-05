<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'reports';
$page_title = 'ECHS Reports';
$pdo = db();

$total = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'];

function rbars(array $rows, $cls='bar'){
    if(!$rows){ echo '<p class="muted">Data nahi.</p>'; return; }
    $max=1; foreach($rows as $r) $max=max($max,(float)$r[1]);
    echo '<div class="barchart">';
    foreach($rows as $r){ $h=max(3,round((float)$r[1]/$max*150));
        $v=$r[1]>=100000?number_format($r[1]/100000,1).'L':($r[1]>=1000?number_format($r[1]/1000,0).'k':number_format($r[1]));
        echo '<div class="bar-col" title="'.e($r[0]).': '.e($v).'"><div class="'.$cls.'" style="height:'.$h.'px"></div><div class="bar-lbl">'.e($r[0]).'</div><div class="bar-val">'.e($v).'</div></div>'; }
    echo '</div>';
}
function rml($ym){ $ts=strtotime($ym.'-01'); return $ts?date('M y',$ts):$ym; }

/** SVG multi-series line chart. $series = [['label'=>..,'color'=>..,'pts'=>[y,y,..]], ..], $labels = x-axis. */
function rline(array $labels, array $series){
    if(!$labels){ echo '<p class="muted">Data nahi.</p>'; return; }
    $W=760; $H=240; $pl=48; $pr=16; $pt=16; $pb=34; $iw=$W-$pl-$pr; $ih=$H-$pt-$pb;
    $max=1; foreach($series as $s) foreach($s['pts'] as $v) $max=max($max,(float)$v);
    $n=count($labels); $stepX = $n>1 ? $iw/($n-1) : 0;
    $x=function($i)use($pl,$stepX){ return round($pl+$i*$stepX,1); };
    $y=function($v)use($pt,$ih,$max){ return round($pt+$ih-($v/$max)*$ih,1); };
    echo '<div class="tbl-scroll"><svg viewBox="0 0 '.$W.' '.$H.'" style="width:100%;min-width:520px;height:auto;font-family:inherit">';
    // horizontal gridlines + y labels (4 steps)
    for($g=0;$g<=4;$g++){ $gv=$max*$g/4; $gy=$y($gv);
        echo '<line x1="'.$pl.'" y1="'.$gy.'" x2="'.($W-$pr).'" y2="'.$gy.'" stroke="var(--line)" stroke-width="1"/>';
        $lbl=$gv>=100000?number_format($gv/100000,1).'L':($gv>=1000?round($gv/1000).'k':round($gv));
        echo '<text x="'.($pl-6).'" y="'.($gy+4).'" text-anchor="end" font-size="10" fill="var(--muted)">'.e($lbl).'</text>';
    }
    // x labels (thinned to ~8)
    $every=max(1,(int)ceil($n/8));
    for($i=0;$i<$n;$i++){ if($i%$every) continue; echo '<text x="'.$x($i).'" y="'.($H-12).'" text-anchor="middle" font-size="10" fill="var(--muted)">'.e($labels[$i]).'</text>'; }
    foreach($series as $s){ $d=''; for($i=0;$i<$n;$i++){ $d.=($i?' L':'M').$x($i).' '.$y((float)($s['pts'][$i]??0)); }
        echo '<path d="'.$d.'" fill="none" stroke="'.e($s['color']).'" stroke-width="2.5" stroke-linejoin="round"/>';
        for($i=0;$i<$n;$i++){ echo '<circle cx="'.$x($i).'" cy="'.$y((float)($s['pts'][$i]??0)).'" r="2.5" fill="'.e($s['color']).'"><title>'.e($labels[$i].' · '.$s['label'].': '.number_format((float)($s['pts'][$i]??0))).'</title></circle>'; }
    }
    echo '</svg></div><div class="legend">';
    foreach($series as $s) echo '<span class="lg"><i style="background:'.e($s['color']).'"></i>'.e($s['label']).'</span>';
    echo '</div>';
}

// ---- KPI category counts + amounts ----
$cat = ['settled'=>0,'inprocess'=>0,'query'=>0,'pending'=>0,'rejected'=>0,'cancelled'=>0];
foreach ($pdo->query("SELECT category, COUNT(*) n FROM echs_claims GROUP BY category") as $r) {
    $c = $r['category'] ?: 'pending'; if (isset($cat[$c])) $cat[$c]+=(int)$r['n'];
}
$sumAll = $pdo->query("SELECT COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(approved_amt),0) appr FROM echs_claims")->fetch();
$settledAmt = (float)$pdo->query("SELECT COALESCE(SUM(approved_amt),0) s FROM echs_claims WHERE category='settled'")->fetch()['s'];
$pend = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims WHERE ".echs_pending_condition())->fetch();
$rejCancel = (int)$cat['rejected'] + (int)$cat['cancelled'];

// ---- yearly summary (by accept_date) ----
$yearly = $pdo->query("SELECT YEAR(accept_date) yr, COUNT(*) n,
        SUM(category='settled') settn, SUM(category IN ('rejected','cancelled')) rejn,
        COALESCE(SUM(claim_amt),0) claim,
        COALESCE(SUM(CASE WHEN category='settled' THEN approved_amt ELSE 0 END),0) settamt
    FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY yr ORDER BY yr DESC")->fetchAll();

// ---- Financial-Year (Apr–Mar) statement ----
$fyExpr = "(YEAR(accept_date) - (MONTH(accept_date)<4))";
$fyRows = $pdo->query("SELECT $fyExpr fy, COUNT(*) n,
        SUM(category='settled') settn, SUM(category IN ('rejected','cancelled')) rejn,
        COALESCE(SUM(claim_amt),0) claim
    FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY fy ORDER BY fy DESC")->fetchAll();
// settled amount grouped by processed_on FY
$fySett = [];
foreach ($pdo->query("SELECT (YEAR(processed_on) - (MONTH(processed_on)<4)) fy, COALESCE(SUM(approved_amt),0) settamt
    FROM echs_claims WHERE category='settled' AND processed_on IS NOT NULL GROUP BY fy")->fetchAll() as $r) $fySett[(int)$r['fy']]=(float)$r['settamt'];

// ---- Settlement TAT: accept_date -> processed_on (settled) ----
$tat = $pdo->query("SELECT AVG(DATEDIFF(processed_on,accept_date)) a, COUNT(*) n
    FROM echs_claims WHERE category='settled' AND processed_on IS NOT NULL AND accept_date IS NOT NULL AND processed_on>=accept_date")->fetch();
$tatB = [];
foreach ([['0-15',0,15],['16-30',16,30],['31-60',31,60],['61-90',61,90],['90+',91,99999]] as $b){
    $n=$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE category='settled' AND processed_on IS NOT NULL AND accept_date IS NOT NULL AND DATEDIFF(processed_on,accept_date) BETWEEN {$b[1]} AND {$b[2]}")->fetch()['n'];
    $tatB[] = [$b[0].'d', (int)$n];
}

// ---- stage funnel (status ordered by lifecycle) ----
$funnel = $pdo->query("SELECT status, COUNT(*) n, MAX(stage_order) so FROM echs_claims WHERE status IS NOT NULL AND status<>'' GROUP BY status ORDER BY so ASC, n DESC")->fetchAll();

// ---- pending aging (by accept_date) ----
$agingRows=[];
foreach ([['0-30','BETWEEN 0 AND 30'],['31-60','BETWEEN 31 AND 60'],['61-90','BETWEEN 61 AND 90'],['90+','> 90']] as $b){
    $x=$pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NOT NULL AND DATEDIFF(CURDATE(),accept_date) {$b[1]}")->fetch();
    $agingRows[]=[$b[0].' din',(int)$x['n'],(float)$x['amt']];
}
// top oldest pending claims
$pendTop = $pdo->query("SELECT claim_id, patient_name, status, claim_amt, accept_date,
        DATEDIFF(CURDATE(),accept_date) age
    FROM echs_claims WHERE ".echs_pending_condition()." AND accept_date IS NOT NULL
    ORDER BY accept_date ASC LIMIT 50")->fetchAll();

// ---- query / need-info list ----
$queryTop = $pdo->query("SELECT claim_id, patient_name, status, claim_amt, accept_date, DATEDIFF(CURDATE(),accept_date) age
    FROM echs_claims WHERE category='query' AND accept_date IS NOT NULL ORDER BY accept_date ASC LIMIT 50")->fetchAll();

// ---- region-wise ----
$regions = $pdo->query("SELECT COALESCE(NULLIF(region,''),'—') region, COUNT(*) n,
        COALESCE(SUM(claim_amt),0) claim,
        COALESCE(SUM(CASE WHEN category='settled' THEN approved_amt ELSE 0 END),0) settamt
    FROM echs_claims GROUP BY region ORDER BY n DESC LIMIT 25")->fetchAll();

// ---- OPD vs IPD split ----
$types = $pdo->query("SELECT patient_type t, COUNT(*) n,
        COALESCE(SUM(claim_amt),0) claim,
        COALESCE(SUM(CASE WHEN category='settled' THEN approved_amt ELSE 0 END),0) settamt
    FROM echs_claims GROUP BY patient_type ORDER BY n DESC")->fetchAll();

// ---- rejection / cancel reasons proxy (by status) ----
$rejReasons = $pdo->query("SELECT COALESCE(NULLIF(status,''),'(koi status nahi)') status, category, COUNT(*) n
    FROM echs_claims WHERE category IN ('rejected','cancelled') GROUP BY status, category ORDER BY n DESC LIMIT 15")->fetchAll();

// ---- 12-month trend ----
$trendAccept = $pdo->query("SELECT DATE_FORMAT(accept_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim
    FROM echs_claims WHERE accept_date IS NOT NULL GROUP BY ym")->fetchAll();
$trendSett = $pdo->query("SELECT DATE_FORMAT(processed_on,'%Y-%m') ym, COALESCE(SUM(approved_amt),0) settamt
    FROM echs_claims WHERE category='settled' AND processed_on IS NOT NULL GROUP BY ym")->fetchAll();
$ta=[]; foreach($trendAccept as $r) $ta[$r['ym']]=$r; $ts=[]; foreach($trendSett as $r) $ts[$r['ym']]=(float)$r['settamt'];
$trLabels=[]; $trN=[]; $trClaim=[]; $trSett=[];
for($i=11;$i>=0;$i--){ $ym=date('Y-m', strtotime("first day of -$i month")); $trLabels[]=rml($ym);
    $trN[]=(int)($ta[$ym]['n']??0); $trClaim[]=(float)($ta[$ym]['claim']??0); $trSett[]=(float)($ts[$ym]??0); }

// ---- data quality checks ----
$totForDq = $total ?: 1;
$dq = [
    ['Settled par amount 0', "category='settled' AND approved_amt=0"],
    ['Bina card ID', "card_id IS NULL OR card_id=''"],
    ['Bina doctor', "doctor_name IS NULL OR doctor_name=''"],
    ['Bina accept date', "accept_date IS NULL"],
    ['Claim amount 0', "claim_amt=0"],
];
$dqRows = [];
foreach ($dq as $d) { $n=(int)$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE {$d[1]}")->fetch()['n']; $dqRows[]=[$d[0],$n,round($n/$totForDq*100,1)]; }
$dupN = (int)$pdo->query("SELECT COUNT(*) n FROM (SELECT card_id,claim_amt,accept_date FROM echs_claims WHERE card_id IS NOT NULL AND card_id<>'' AND claim_amt>0 AND accept_date IS NOT NULL GROUP BY card_id,claim_amt,accept_date HAVING COUNT(*)>1) t")->fetch()['n'];

// ---- repeat patients ----
$repeat = $pdo->query("SELECT card_id, MAX(patient_name) nm, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim,
        COALESCE(SUM(CASE WHEN category='settled' THEN approved_amt ELSE 0 END),0) settamt
    FROM echs_claims WHERE card_id IS NOT NULL AND card_id<>'' GROUP BY card_id HAVING n>1 ORDER BY n DESC LIMIT 20")->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>📊 ECHS Reports</h1>
    <div class="page-actions">
        <a class="btn" target="_blank" href="<?= BASE_URL ?>/echs_month_report.php?scheme=ECHS">🗓️ Monthly Report</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_csv.php?scheme=ECHS">⬇ CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($total) ?></div><div class="stat-lbl">Total claims</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= number_format($cat['settled']) ?></div><div class="stat-lbl">Settled (<?= money($settledAmt) ?>)</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($pend['n']) ?></div><div class="stat-lbl">Pending / in-process</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format($cat['query']) ?></div><div class="stat-lbl">Query / Need info</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($rejCancel) ?></div><div class="stat-lbl">Rejected / Cancelled</div></div>
</div>

<div class="card">
    <h2>Yearly summary</h2>
    <table class="tbl">
        <thead><tr><th>Year</th><th class="r">Claims</th><th class="r">Settled</th><th class="r">Rejected</th><th class="r">Claimed</th><th class="r">Settled amount</th></tr></thead>
        <tbody>
        <?php foreach ($yearly as $y): ?>
            <tr><td><strong><?= e($y['yr']) ?></strong></td><td class="r"><?= number_format($y['n']) ?></td>
                <td class="r"><?= number_format($y['settn']) ?></td><td class="r"><?= number_format($y['rejn']) ?></td>
                <td class="r"><?= money($y['claim']) ?></td><td class="r ok"><?= money($y['settamt']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$yearly): ?><tr><td colspan="6" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>🧾 Financial Year statement (Apr–Mar)</h2>
    <table class="tbl">
        <thead><tr><th>Financial Year</th><th class="r">Claims</th><th class="r">Settled</th><th class="r">Rejected</th><th class="r">Claimed</th><th class="r">Settled amount</th></tr></thead>
        <tbody>
        <?php foreach ($fyRows as $y): $fs=(int)$y['fy']; $sa=$fySett[$fs]??0; ?>
            <tr><td><strong><?= $fs ?>–<?= substr((string)($fs+1),-2) ?></strong></td>
                <td class="r"><?= number_format($y['n']) ?></td><td class="r"><?= number_format($y['settn']) ?></td>
                <td class="r"><?= number_format($y['rejn']) ?></td><td class="r"><?= money($y['claim']) ?></td>
                <td class="r ok"><?= money($sa) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$fyRows): ?><tr><td colspan="6" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="muted small">Financial year = 1 April se 31 March. "Settled amount" us FY me processed/settle hue claims ke aadhar par.</p>
</div>

<div class="card">
    <h2>📈 12-mahine trend — claimed vs settled amount</h2>
    <?php rline($trLabels, [
        ['label'=>'Claimed','color'=>'#1B2F5E','pts'=>$trClaim],
        ['label'=>'Settled amount','color'=>'#16A34A','pts'=>$trSett],
    ]); ?>
</div>

<div class="card">
    <h2>📈 12-mahine trend — claim count</h2>
    <?php rline($trLabels, [['label'=>'Claims accepted','color'=>'#1B2F5E','pts'=>$trN]]); ?>
</div>

<div class="detail-grid">
    <div class="card"><h2>Settlement speed (accept → settle)</h2><?php rbars($tatB,'bar bar-ok'); ?>
        <p class="muted small">Avg: <?= $tat['a']!==null?round($tat['a']).' din':'-' ?> (<?= number_format((int)$tat['n']) ?> settled)</p></div>
    <div class="card"><h2>Pending aging (amount)</h2>
        <table class="tbl"><thead><tr><th>Bucket</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead><tbody>
        <?php foreach ($agingRows as $a): ?><tr><td><?= e($a[0]) ?></td><td class="r"><?= number_format($a[1]) ?></td><td class="r"><?= money($a[2]) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card">
    <h2>Stage funnel</h2>
    <table class="tbl"><thead><tr><th>Status</th><th class="r">Claims</th></tr></thead><tbody>
    <?php foreach ($funnel as $f): ?>
        <tr><td><a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&status=<?= urlencode($f['status']) ?>"><?= e($f['status']) ?></a></td><td class="r"><?= number_format($f['n']) ?></td></tr>
    <?php endforeach; ?>
    <?php if(!$funnel): ?><tr><td colspan="2" class="muted">Data nahi.</td></tr><?php endif; ?>
    </tbody></table>
</div>

<?php if ($pendTop): ?>
<div class="card">
    <h2>Sabse purane pending claims</h2>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Status</th><th class="r">Claimed</th><th class="r">Age</th></tr></thead>
        <tbody>
        <?php foreach ($pendTop as $r): $ac=$r['age']>90?'style="color:#dc3545;font-weight:600"':''; ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($r['claim_id']) ?>"><?= e($r['claim_id']) ?></a></td>
                <td><?= e($r['patient_name']) ?></td><td class="small"><?= e($r['status']) ?></td>
                <td class="r"><?= inr($r['claim_amt'],0) ?></td><td class="r" <?= $ac ?>><?= (int)$r['age'] ?>d</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($queryTop): ?>
<div class="card">
    <h2>Query / Need-info — sabse purane</h2>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Status</th><th class="r">Claimed</th><th class="r">Age</th></tr></thead>
        <tbody>
        <?php foreach ($queryTop as $r): $ac=$r['age']>30?'style="color:#dc3545;font-weight:600"':''; ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($r['claim_id']) ?>"><?= e($r['claim_id']) ?></a></td>
                <td><?= e($r['patient_name']) ?></td><td class="small"><?= e($r['status']) ?></td>
                <td class="r"><?= inr($r['claim_amt'],0) ?></td><td class="r" <?= $ac ?>><?= (int)$r['age'] ?>d</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="detail-grid">
    <div class="card">
        <h2>Region-wise</h2>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Region</th><th class="r">Claims</th><th class="r">Claimed</th><th class="r">Settled amount</th></tr></thead><tbody>
        <?php foreach ($regions as $r): ?><tr><td class="small"><?= e($r['region']) ?></td><td class="r"><?= number_format($r['n']) ?></td><td class="r"><?= inr($r['claim'],0) ?></td><td class="r"><?= inr($r['settamt'],0) ?></td></tr><?php endforeach; ?>
        <?php if(!$regions): ?><tr><td colspan="4" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
    <div class="card">
        <h2>OPD / IPD split</h2>
        <table class="tbl"><thead><tr><th>Type</th><th class="r">Claims</th><th class="r">Claimed</th><th class="r">Settled amount</th></tr></thead><tbody>
        <?php foreach ($types as $t): ?><tr><td><strong><?= e(echs_ptype($t['t'])) ?></strong></td><td class="r"><?= number_format($t['n']) ?></td><td class="r"><?= inr($t['claim'],0) ?></td><td class="r"><?= inr($t['settamt'],0) ?></td></tr><?php endforeach; ?>
        <?php if(!$types): ?><tr><td colspan="4" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<div class="card">
    <h2>Rejection / Cancel reasons (status-wise)</h2>
    <?php if (!$rejReasons): ?><p class="muted">Koi rejection/cancel nahi 🎉</p><?php else: ?>
    <table class="tbl"><thead><tr><th>Status</th><th>Category</th><th class="r">Claims</th></tr></thead><tbody>
    <?php foreach ($rejReasons as $r): ?><tr><td class="small"><?= e(mb_substr($r['status'],0,80)) ?></td><td class="small"><?= e($r['category']) ?></td><td class="r"><?= number_format($r['n']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>🔎 Data Quality</h2>
    <table class="tbl"><thead><tr><th>Check</th><th class="r">Claims</th><th class="r">%</th></tr></thead><tbody>
    <?php foreach ($dqRows as $d): $cls=$d[2]>25?'pill-rejected':($d[2]>5?'pill-process':'pill-settled'); ?>
        <tr><td><?= e($d[0]) ?></td><td class="r"><?= number_format($d[1]) ?></td><td class="r"><span class="pill <?= $cls ?>"><?= $d[2] ?>%</span></td></tr>
    <?php endforeach; ?>
        <tr><td>Sambhavit duplicate (card+amount+accept date)</td><td class="r"><?= number_format($dupN) ?></td><td class="r"><?php if($dupN>0): ?><a class="link" href="<?= BASE_URL ?>/echs_dupes.php?scheme=ECHS"><span class="pill pill-process">resolve karein →</span></a><?php else: ?><span class="pill pill-settled">clean</span><?php endif; ?></td></tr>
    </tbody></table>
</div>

<?php if ($repeat): ?>
<div class="card">
    <h2>Repeat patients (baar-baar aane wale)</h2>
    <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>Card ID</th><th class="r">Claims</th><th class="r">Claimed</th><th class="r">Settled amount</th></tr></thead><tbody>
    <?php foreach ($repeat as $r): ?><tr><td><a class="link" href="<?= BASE_URL ?>/echs_patient.php?scheme=ECHS&q=<?= urlencode($r['card_id']) ?>"><?= e($r['nm']) ?></a></td><td class="small"><?= e($r['card_id']) ?></td><td class="r"><?= number_format($r['n']) ?></td><td class="r"><?= inr($r['claim'],0) ?></td><td class="r"><?= inr($r['settamt'],0) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
