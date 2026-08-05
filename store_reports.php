<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/icons.php';
store_ensure_table();

$scheme = 'STORE';
$meta   = scheme_meta('STORE');
$active = 'reports';
$page_title = 'Medical Store Reports';
$pdo = db();

$total = (int)$pdo->query("SELECT COUNT(*) n FROM store_claims")->fetch()['n'];

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

// ---- KPI aggregates ----
$agg = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu,
    SUM(status LIKE '%APPROV%' OR status LIKE '%Approv%') appn,
    SUM(status LIKE '%REJECT%' OR status LIKE '%Reject%') rejn
    FROM store_claims")->fetch();
$approvalRate = $agg['n'] > 0 ? round($agg['appn']/$agg['n']*100,1) : 0;

// yearly summary
$yearly = $pdo->query("SELECT sub_year, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu,
    SUM(status LIKE '%APPROV%' OR status LIKE '%Approv%') appn,
    SUM(status LIKE '%REJECT%' OR status LIKE '%Reject%') rejn
    FROM store_claims WHERE sub_year IS NOT NULL GROUP BY sub_year ORDER BY sub_year DESC")->fetchAll();

// ---- Financial-Year (Apr–Mar) statement ----
$fyStart = "(YEAR(submit_date) - (MONTH(submit_date)<4))";
$fyRows = $pdo->query("SELECT $fyStart fy, COUNT(*) n,
        COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu,
        SUM(status LIKE '%APPROV%' OR status LIKE '%Approv%') appn,
        SUM(status LIKE '%REJECT%' OR status LIKE '%Reject%') rejn
    FROM store_claims WHERE submit_date IS NOT NULL GROUP BY fy ORDER BY fy DESC")->fetchAll();

// ---- deduction (claimed - cu approved, only approved) ----
$ded = $pdo->query("SELECT COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu
    FROM store_claims WHERE (status LIKE '%APPROV%' OR status LIKE '%Approv%') AND cu_amt>0")->fetch();
$deduction = (float)$ded['claim'] - (float)$ded['cu'];

// ---- pending aging (by submit_date) ----
$agingRows=[];
foreach ([['0-30','BETWEEN 0 AND 30'],['31-60','BETWEEN 31 AND 60'],['61-90','BETWEEN 61 AND 90'],['90+','> 90']] as $b){
    $x=$pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM store_claims WHERE ".store_pending_condition()." AND submit_date IS NOT NULL AND DATEDIFF(CURDATE(),submit_date) {$b[1]}")->fetch();
    $agingRows[]=[$b[0].' din',(int)$x['n'],(float)$x['amt']];
}
$pendTop = $pdo->query("SELECT invoice_no, patient_name, status, claim_amt, submit_date, DATEDIFF(CURDATE(),submit_date) age
    FROM store_claims WHERE ".store_pending_condition()." AND submit_date IS NOT NULL ORDER BY submit_date ASC LIMIT 50")->fetchAll();

// ---- status breakdown ----
$statusRows = $pdo->query("SELECT COALESCE(NULLIF(status,''),'(bina status)') status, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt
    FROM store_claims GROUP BY status ORDER BY n DESC")->fetchAll();

// ---- 12-month trend (by submit_date): claimed vs CU-approved, and count ----
$trendClaim = $pdo->query("SELECT DATE_FORMAT(submit_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu
    FROM store_claims WHERE submit_date IS NOT NULL GROUP BY ym")->fetchAll();
$tc=[]; foreach($trendClaim as $r) $tc[$r['ym']]=$r;
$trLabels=[]; $trN=[]; $trClaim=[]; $trCu=[];
for($i=11;$i>=0;$i--){ $ym=date('Y-m', strtotime("first day of -$i month")); $trLabels[]=rml($ym);
    $trN[]=(int)($tc[$ym]['n']??0); $trClaim[]=(float)($tc[$ym]['claim']??0); $trCu[]=(float)($tc[$ym]['cu']??0); }

// ---- monthly submitted (bars) ----
$monthly = $pdo->query("SELECT DATE_FORMAT(submit_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM store_claims WHERE submit_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 15")->fetchAll();
$monthly=array_reverse($monthly); $mBars=[]; foreach($monthly as $m) $mBars[]=[rml($m['ym']),(float)$m['amt']];

// ---- data quality checks ----
$totForDq = (int)$pdo->query("SELECT COUNT(*) n FROM store_claims")->fetch()['n'] ?: 1;
$dq = [
    ['Approved par CU amount 0', "(status LIKE '%APPROV%' OR status LIKE '%Approv%') AND cu_amt=0"],
    ['Bina card no.', "card_no IS NULL OR card_no=''"],
    ['Bina submit date', "submit_date IS NULL"],
    ['Claim amount 0', "claim_amt=0"],
    ['Bina TID', "tid IS NULL OR tid=''"],
];
$dqRows = [];
foreach ($dq as $d) { $n=(int)$pdo->query("SELECT COUNT(*) n FROM store_claims WHERE {$d[1]}")->fetch()['n']; $dqRows[]=[$d[0],$n,round($n/$totForDq*100,1)]; }
$dupN = (int)$pdo->query("SELECT COUNT(*) n FROM (SELECT card_no,claim_amt,submit_date FROM store_claims WHERE card_no IS NOT NULL AND card_no<>'' AND claim_amt>0 AND submit_date IS NOT NULL GROUP BY card_no,claim_amt,submit_date HAVING COUNT(*)>1) t")->fetch()['n'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>📊 Medical Store Reports</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/api/store_export_csv.php?scheme=STORE">⬇ Excel/CSV</a>
        <a class="btn" href="javascript:window.print()">🖨️ Print</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($agg['n']) ?></div><div class="stat-lbl">Total invoices</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($agg['claim'],0) ?></div><div class="stat-lbl">Claimed amount</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($agg['cu'],0) ?></div><div class="stat-lbl">CU Approved</div></div>
    <div class="stat-card info"><div class="stat-num"><?= $approvalRate ?>%</div><div class="stat-lbl">Approval rate</div></div>
</div>

<div class="card">
    <h2>Yearly summary</h2>
    <table class="tbl">
        <thead><tr><th>Year</th><th class="r">Invoices</th><th class="r">Approved</th><th class="r">Rejected</th><th class="r">Claimed</th><th class="r">CU Approved</th></tr></thead>
        <tbody>
        <?php foreach ($yearly as $y): ?>
            <tr><td><strong><?= e($y['sub_year']) ?></strong></td><td class="r"><?= number_format($y['n']) ?></td>
                <td class="r"><?= number_format($y['appn']) ?></td><td class="r"><?= number_format($y['rejn']) ?></td>
                <td class="r"><?= money($y['claim']) ?></td><td class="r"><?= money($y['cu']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$yearly): ?><tr><td colspan="6" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>🧾 Financial Year statement (Apr–Mar)</h2>
    <table class="tbl">
        <thead><tr><th>Financial Year</th><th class="r">Invoices</th><th class="r">Approved</th><th class="r">Rejected</th><th class="r">Claimed</th><th class="r">CU Approved</th></tr></thead>
        <tbody>
        <?php foreach ($fyRows as $y): $fs=(int)$y['fy']; ?>
            <tr><td><strong><?= $fs ?>–<?= substr((string)($fs+1),-2) ?></strong></td>
                <td class="r"><?= number_format($y['n']) ?></td><td class="r"><?= number_format($y['appn']) ?></td>
                <td class="r"><?= number_format($y['rejn']) ?></td><td class="r"><?= money($y['claim']) ?></td>
                <td class="r"><?= money($y['cu']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$fyRows): ?><tr><td colspan="6" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="muted small">Financial year = 1 April se 31 March.</p>
</div>

<div class="card">
    <h2>💸 Deduction (approved invoices)</h2>
    <table class="kv">
        <tr><td>Claimed (approved invoices)</td><th><?= money($ded['claim']) ?></th></tr>
        <tr><td>CU Approved</td><th class="ok"><?= money($ded['cu']) ?></th></tr>
        <tr><td><strong>Deduction (claimed − approved)</strong></td><th class="danger" style="font-size:1.1rem"><?= money($deduction>0?$deduction:0) ?></th></tr>
    </table>
    <p class="muted small">Sirf approved invoices par — claimed aur CU approved ke beech ka antar.</p>
</div>

<div class="card">
    <h2>📈 12-mahine trend — claimed vs CU approved</h2>
    <?php rline($trLabels, [
        ['label'=>'Claimed','color'=>'#1B2F5E','pts'=>$trClaim],
        ['label'=>'CU Approved','color'=>'#16A34A','pts'=>$trCu],
    ]); ?>
</div>

<div class="card">
    <h2>📈 12-mahine trend — invoice count</h2>
    <?php rline($trLabels, [['label'=>'Invoices submitted','color'=>'#1B2F5E','pts'=>$trN]]); ?>
</div>

<div class="card"><h2>Monthly — submitted claim amount</h2><?php rbars($mBars); ?></div>

<div class="detail-grid">
    <div class="card">
        <h2>Pending aging (amount)</h2>
        <table class="tbl"><thead><tr><th>Bucket</th><th class="r">Invoices</th><th class="r">Amount</th></tr></thead><tbody>
        <?php foreach ($agingRows as $a): ?><tr><td><?= e($a[0]) ?></td><td class="r"><?= number_format($a[1]) ?></td><td class="r"><?= money($a[2]) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <p class="muted small">Pending invoices — jitne purane utne zaroori.</p>
    </div>
    <div class="card">
        <h2>Status breakdown</h2>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Status</th><th class="r">Invoices</th><th class="r">Claimed</th></tr></thead><tbody>
        <?php foreach ($statusRows as $s): ?><tr><td class="small"><?= e($s['status']) ?></td><td class="r"><?= number_format($s['n']) ?></td><td class="r"><?= inr($s['amt'],0) ?></td></tr><?php endforeach; ?>
        <?php if(!$statusRows): ?><tr><td colspan="3" class="muted">Data nahi.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</div>

<?php if ($pendTop): ?>
<div class="card">
    <h2>Pending invoices — sabse purane</h2>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Invoice</th><th>Patient</th><th>Status</th><th class="r">Claimed</th><th class="r">Age</th></tr></thead>
        <tbody>
        <?php foreach ($pendTop as $r): $ac=$r['age']>90?'style="color:#dc3545;font-weight:600"':''; ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/store_claim.php?scheme=STORE&inv=<?= urlencode($r['invoice_no']) ?>"><?= e($r['invoice_no']) ?></a></td>
                <td><?= e($r['patient_name']) ?></td><td class="small"><?= e($r['status']) ?></td>
                <td class="r"><?= inr($r['claim_amt'],0) ?></td><td class="r" <?= $ac ?>><?= (int)$r['age'] ?>d</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>🔎 Data Quality</h2>
    <table class="tbl"><thead><tr><th>Check</th><th class="r">Invoices</th><th class="r">%</th></tr></thead><tbody>
    <?php foreach ($dqRows as $d): $cls=$d[2]>25?'pill-rejected':($d[2]>5?'pill-process':'pill-settled'); ?>
        <tr><td><?= e($d[0]) ?></td><td class="r"><?= number_format($d[1]) ?></td><td class="r"><span class="pill <?= $cls ?>"><?= $d[2] ?>%</span></td></tr>
    <?php endforeach; ?>
        <tr><td>Sambhavit duplicate (card+amount+date)</td><td class="r"><?= number_format($dupN) ?></td><td class="r"><?php if($dupN>0): ?><a class="link" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE&flag=dupe"><span class="pill pill-process">dekhein →</span></a><?php else: ?><span class="pill pill-settled">clean</span><?php endif; ?></td></tr>
    </tbody></table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
