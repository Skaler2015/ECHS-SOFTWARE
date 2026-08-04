<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'claims';
$page_title = 'RGHS Claims';
$pdo = db();

[$where, $args] = rghs_build_filter($_GET);

// sorting
$sortable = ['submit_date'=>'submit_date','claim_amt'=>'claim_amt','cu_amt'=>'cu_amt','patient_name'=>'patient_name','status'=>'status','tid'=>'tid'];
$sort = $_GET['sort'] ?? 'submit_date';
if (!isset($sortable[$sort])) $sort = 'submit_date';
$dir = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$orderCol = $sortable[$sort];

// paging
$per = (int)($_GET['per'] ?? 50); if (!in_array($per, [50,100,200])) $per = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*$per;

$cst = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu FROM rghs_claims $where");
$cst->execute($args); $agg = $cst->fetch();
$totCount = (int)$agg['n'];
$pages = max(1, (int)ceil($totCount / $per));

$sql = "SELECT tid, patient_name, card_no, claim_type, status, hospital_name, doctor_name,
        submit_date, claim_amt, cu_amt, sub_year, sub_month
        FROM rghs_claims $where ORDER BY `$orderCol` $dir, tid $dir LIMIT $per OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

// category tab counts (unfiltered totals)
$tabCounts = ['all'=>0,'approved'=>0,'pending'=>0,'query'=>0,'rejected'=>0];
$tabCounts['all'] = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims")->fetch()['n'];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM rghs_claims GROUP BY status") as $r) {
    $tabCounts[rghs_category($r['status'])] += (int)$r['n'];
}

$curCat = $_GET['cat'] ?? '';
function qs($over){ $q = array_merge($_GET, $over); $q['scheme']='RGHS'; return BASE_URL.'/rghs_claims.php?'.http_build_query($q); }
function sortLink($col,$label){ global $sort,$dir; $ndir = ($sort===$col && strtolower($dir)==='asc')?'desc':'asc';
    $arr = $sort===$col ? (strtolower($dir)==='asc'?' ▲':' ▼') : '';
    return '<a class="link" href="'.qs(['sort'=>$col,'dir'=>$ndir,'page'=>1]).'">'.e($label).$arr.'</a>'; }

$statusList = rghs_status_list();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>RGHS Claims</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_upload.php?scheme=RGHS">⬆ Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/api/rghs_export_csv.php?<?= http_build_query(array_merge($_GET,['scheme'=>'RGHS'])) ?>">⬇ CSV</a>
    </div>
</div>

<div class="tabs">
    <?php foreach (['all'=>'All','approved'=>'Approved','pending'=>'Pending','query'=>'Query','rejected'=>'Rejected'] as $k=>$lbl):
        $on = ($curCat===$k || ($k==='all' && $curCat===''))?'on':''; ?>
        <a class="tab <?= $on ?>" href="<?= qs(['cat'=>$k==='all'?'':$k,'page'=>1]) ?>"><?= $lbl ?> <span class="muted">(<?= number_format($tabCounts[$k]) ?>)</span></a>
    <?php endforeach; ?>
</div>

<form class="searchbar" method="get" style="margin-bottom:12px">
    <input type="hidden" name="scheme" value="RGHS">
    <?php if ($curCat): ?><input type="hidden" name="cat" value="<?= e($curCat) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="TID, patient, card, doctor, mobile…">
    <select name="type">
        <option value="">Sab type</option>
        <?php foreach (['IPD','DAYCARE','Hospital - OPD'] as $t): ?>
            <option value="<?= e($t) ?>" <?= ($_GET['type']??'')===$t?'selected':'' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status">
        <option value="">Sab status</option>
        <?php foreach ($statusList as $s): if(!$s['status'])continue; ?>
            <option value="<?= e($s['status']) ?>" <?= ($_GET['status']??'')===$s['status']?'selected':'' ?>><?= e($s['status']) ?> (<?= $s['n'] ?>)</option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="year" value="<?= e($_GET['year'] ?? '') ?>" placeholder="Year" style="width:80px">
    <button class="btn btn-primary">Filter</button>
    <?php if (array_diff_key($_GET, ['scheme'=>1,'page'=>1,'sort'=>1,'dir'=>1,'per'=>1])): ?>
        <a class="btn btn-light" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">Reset</a>
    <?php endif; ?>
</form>

<?php if (!empty($_GET['doctor'])): ?>
    <p class="muted small">Doctor filter: <strong><?= e($_GET['doctor']) ?></strong> · <a class="link" href="<?= qs(['doctor'=>null]) ?>">hataayein</a></p>
<?php endif; ?>

<div class="card">
    <div class="muted small" style="margin-bottom:8px">
        <?= number_format($totCount) ?> claims · Claimed <?= money($agg['claim']) ?> · Approved(CU) <?= money($agg['cu']) ?>
    </div>
    <div class="tbl-scroll">
    <table class="tbl">
        <thead><tr>
            <th><?= sortLink('tid','TID') ?></th>
            <th><?= sortLink('patient_name','Patient') ?></th>
            <th>Type</th>
            <th><?= sortLink('status','Status') ?></th>
            <th>Doctor</th>
            <th><?= sortLink('submit_date','Submitted') ?></th>
            <th class="r"><?= sortLink('claim_amt','Claimed') ?></th>
            <th class="r"><?= sortLink('cu_amt','Approved') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">Kuch nahi mila.</td></tr>
        <?php else: foreach ($rows as $c): ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($c['tid']) ?>"><?= e($c['tid']) ?></a></td>
                <td><?= e($c['patient_name'] ?: '-') ?><div class="muted small"><?= e($c['card_no']) ?></div></td>
                <td class="small"><?= e($c['claim_type'] ?: '-') ?></td>
                <td><span class="pill pill-<?= rghs_category($c['status']) === 'approved' ? 'settled' : (rghs_category($c['status'])==='rejected'?'rejected':(rghs_category($c['status'])==='query'?'info':'process')) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                <td class="small"><?= e($c['doctor_name'] ?: '-') ?></td>
                <td class="small"><?= $c['submit_date'] ? e(date('d-m-y', strtotime($c['submit_date']))) : '-' ?></td>
                <td class="r"><?= inr($c['claim_amt'],0) ?></td>
                <td class="r"><?= inr($c['cu_amt'],0) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pager" style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($page>1): ?><a class="btn btn-light" href="<?= qs(['page'=>$page-1]) ?>">← Prev</a><?php endif; ?>
        <span class="muted small">Page <?= $page ?> / <?= $pages ?></span>
        <?php if ($page<$pages): ?><a class="btn btn-light" href="<?= qs(['page'=>$page+1]) ?>">Next →</a><?php endif; ?>
        <span style="flex:1"></span>
        <?php foreach ([50,100,200] as $pp): ?>
            <a class="link small <?= $per===$pp?'':'muted' ?>" href="<?= qs(['per'=>$pp,'page'=>1]) ?>"><?= $pp ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
