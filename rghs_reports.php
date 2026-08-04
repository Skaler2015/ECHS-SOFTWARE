<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'reports';
$page_title = 'RGHS Reports';
$pdo = db();

$total = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims")->fetch()['n'];

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

// yearly summary
$yearly = $pdo->query("SELECT sub_year, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu,
    SUM(status LIKE '%APPROVED%' OR status LIKE '%Approved%') appn,
    SUM(status LIKE '%REJECT%' OR status LIKE '%Reject%') rejn
    FROM rghs_claims WHERE sub_year IS NOT NULL GROUP BY sub_year ORDER BY sub_year DESC")->fetchAll();

// TAT: submit_date -> cu_action_date
$tat = $pdo->query("SELECT AVG(DATEDIFF(cu_action_date,submit_date)) a, COUNT(*) n
    FROM rghs_claims WHERE cu_action_date IS NOT NULL AND submit_date IS NOT NULL AND cu_action_date>=submit_date")->fetch();
$tatB = [];
foreach ([['0-15',0,15],['16-30',16,30],['31-60',31,60],['61-90',61,90],['90+',91,99999]] as $b){
    $n=$pdo->query("SELECT COUNT(*) n FROM rghs_claims WHERE cu_action_date IS NOT NULL AND submit_date IS NOT NULL AND DATEDIFF(cu_action_date,submit_date) BETWEEN {$b[1]} AND {$b[2]}")->fetch()['n'];
    $tatB[] = [$b[0].'d', (int)$n];
}

// aging of pending (by submit_date)
$agingRows=[];
foreach ([['0-30','BETWEEN 0 AND 30'],['31-60','BETWEEN 31 AND 60'],['61-90','BETWEEN 61 AND 90'],['90+','> 90']] as $b){
    $x=$pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE ".rghs_pending_condition()." AND submit_date IS NOT NULL AND DATEDIFF(CURDATE(),submit_date) {$b[1]}")->fetch();
    $agingRows[]=[$b[0].' din',(int)$x['n'],(float)$x['amt']];
}

// rejection/query
$rej = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE status LIKE '%REJECT%' OR status LIKE '%Reject%'")->fetch();
$qry = $pdo->query("SELECT COUNT(*) n FROM rghs_claims WHERE status LIKE '%QUER%' OR status LIKE '%Quer%'")->fetch();

// shortfall claimed vs cu approved (approved claims)
$sf = $pdo->query("SELECT COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu FROM rghs_claims WHERE (status LIKE '%APPROVED%' OR status LIKE '%Approved%') AND cu_amt>0")->fetch();
$shortfall = $sf['claim'] - $sf['cu'];

// monthly submitted
$monthly = $pdo->query("SELECT DATE_FORMAT(submit_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE submit_date IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 15")->fetchAll();
$monthly=array_reverse($monthly); $mBars=[]; foreach($monthly as $m) $mBars[]=[rml($m['ym']),(float)$m['amt']];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>📊 RGHS Reports</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/api/rghs_export_csv.php?scheme=RGHS">⬇ CSV</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= $tat['a']!==null?round($tat['a']).' din':'-' ?></div><div class="stat-lbl">Avg settle time (submit→CU)</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($rej['n']) ?></div><div class="stat-lbl">Rejected (<?= money($rej['amt']) ?>)</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format($qry['n']) ?></div><div class="stat-lbl">Query me</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= inr($shortfall>0?$shortfall:0,0) ?></div><div class="stat-lbl">Shortfall (claimed−approved)</div></div>
</div>

<div class="card">
    <h2>Yearly summary</h2>
    <table class="tbl">
        <thead><tr><th>Year</th><th class="r">Claims</th><th class="r">Approved</th><th class="r">Rejected</th><th class="r">Claimed</th><th class="r">CU Approved</th></tr></thead>
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

<div class="card"><h2>Monthly — submitted claim amount</h2><?php rbars($mBars); ?></div>

<div class="detail-grid">
    <div class="card"><h2>Settlement speed (submit → CU)</h2><?php rbars($tatB,'bar bar-ok'); ?>
        <p class="muted small">Avg: <?= $tat['a']!==null?round($tat['a']).' din':'-' ?> (<?= number_format((int)$tat['n']) ?> claims)</p></div>
    <div class="card"><h2>Pending aging (amount)</h2>
        <table class="tbl"><thead><tr><th>Bucket</th><th class="r">Claims</th><th class="r">Amount</th></tr></thead><tbody>
        <?php foreach ($agingRows as $a): ?><tr><td><?= e($a[0]) ?></td><td class="r"><?= number_format($a[1]) ?></td><td class="r"><?= money($a[2]) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
