<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'claims';
$page_title = 'ECHS Claims';

// ---- bulk actions (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $ret = $_POST['return'] ?? (BASE_URL . '/echs_claims.php?scheme=ECHS');
    if ($act === 'toggleflag') {
        db()->prepare("UPDATE echs_claims SET followup=1-followup WHERE claim_id=?")->execute([trim($_POST['id'] ?? '')]);
    } elseif (in_array($act, ['flag','unflag']) && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_values(array_filter(array_map('strval', $_POST['ids'])));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $val = $act === 'flag' ? 1 : 0;
            db()->prepare("UPDATE echs_claims SET followup=$val WHERE claim_id IN ($in)")->execute($ids);
            flash(count($ids) . " claims " . ($val?'flag':'unflag') . " ho gaye.");
        }
    } elseif ($act === 'setdoctor' && !empty($_POST['ids']) && is_array($_POST['ids'])) {
        $ids = array_values(array_filter(array_map('strval', $_POST['ids'])));
        $doc = trim($_POST['doctor_name'] ?? '');
        if ($ids && $doc !== '') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE echs_claims SET doctor_name=? WHERE claim_id IN ($in)")->execute(array_merge([$doc], $ids));
            echs_log('bulk_doctor', $doc . ' x' . count($ids));
            flash(count($ids) . " claims me Dr. \"" . $doc . "\" set ho gaya.");
        } else {
            flash('Doctor ka naam likhein aur kam se kam ek claim select karein.', 'error');
        }
    }
    redirect($ret);
}

// ---- filters ----
$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$ptype  = trim($_GET['ptype'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$amin   = trim($_GET['amin'] ?? '');
$amax   = trim($_GET['amax'] ?? '');
$cat    = trim($_GET['cat'] ?? '');
$age    = trim($_GET['age'] ?? '');
$flag   = trim($_GET['flag'] ?? '');
$sort   = $_GET['sort'] ?? 'accept';
$dir    = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = (int)($_GET['per'] ?? 50); if (!in_array($per, [50,100,200])) $per = 50;

list($wsql, $args) = echs_build_filter($_GET);

$sortCols = [
    'claim'=>'claim_id', 'accept'=>'accept_date', 'net'=>'net_claim_amt',
    'app'=>'approved_amt', 'ded'=>'(net_claim_amt-approved_amt)', 'credited'=>'amt_credited',
];
if (!isset($sortCols[$sort])) $sort = 'accept';
$orderBy = $sortCols[$sort] . " $dir, claim_id DESC";

// totals for current filter
$tot = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app,
    COALESCE(SUM(net_claim_amt-approved_amt),0) ded, COALESCE(SUM(amt_credited),0) cred, COALESCE(SUM(tds_amt),0) tds
    FROM echs_claims $wsql");
$tot->execute($args);
$T = $tot->fetch();
$totalRows = (int)$T['n'];
$totalPages = max(1, (int)ceil($totalRows / $per));
$page = min($page, $totalPages);
$offset = ($page - 1) * $per;

$st = db()->prepare("SELECT *, DATEDIFF(CURDATE(),accept_date) AS age_days FROM echs_claims $wsql ORDER BY $orderBy LIMIT $per OFFSET $offset");
$st->execute($args);
$rows = $st->fetchAll();

$qsAll = array_filter(['scheme'=>'ECHS','q'=>$q,'status'=>$status,'ptype'=>$ptype,'from'=>$from,'to'=>$to,'amin'=>$amin,'amax'=>$amax,'cat'=>$cat,'age'=>$age,'flag'=>$flag,'sort'=>$sort,'dir'=>strtolower($dir),'per'=>$per], fn($v)=>$v!=='' && $v!==null);
$qs = http_build_query($qsAll);
$curUrl = BASE_URL . '/echs_claims.php?' . $qs;

// helper: build a URL with some params overridden
function chip_url($over) {
    global $qsAll;
    $p = array_merge($qsAll, $over);
    $p = array_filter($p, fn($v)=>$v!=='' && $v!==null);
    unset($p['page']);
    return '?' . http_build_query($p);
}
function sort_link($key, $label) {
    global $sort, $dir, $qsAll;
    $ndir = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
    $p = array_merge($qsAll, ['sort'=>$key,'dir'=>$ndir]); unset($p['page']);
    return '<a class="th-sort" href="?' . http_build_query(array_filter($p, fn($v)=>$v!==''&&$v!==null)) . '">' . $label . $arrow . '</a>';
}
function age_badge($days) {
    if ($days === null || $days === '') return '<span class="muted">-</span>';
    $d = (int)$days;
    $cls = $d>90?'pill-rejected':($d>60?'pill-process':($d>30?'pill-info':'pill-settled'));
    return '<span class="pill '.$cls.'">'.$d.'d</span>';
}

$active_cat = $cat; $active_flag = $flag;
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>ECHS Claims</h1>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS"><?= icon('upload',16) ?> Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claim_edit.php?scheme=ECHS"><?= icon('plus',16) ?> New</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_xls.php?<?= e($qs) ?>">⬇ Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_csv.php?<?= e($qs) ?>">⬇ CSV</a>
    </div>
</div>

<!-- category tabs -->
<div class="tabs">
    <a class="tab <?= $cat===''?'on':'' ?>" href="<?= e(chip_url(['cat'=>''])) ?>">All</a>
    <a class="tab <?= $cat==='process'?'on':'' ?>" href="<?= e(chip_url(['cat'=>'process'])) ?>">In-process</a>
    <a class="tab <?= $cat==='settled'?'on':'' ?>" href="<?= e(chip_url(['cat'=>'settled'])) ?>">Settled</a>
    <a class="tab <?= $cat==='rejected'?'on':'' ?>" href="<?= e(chip_url(['cat'=>'rejected'])) ?>">Rejected/Cancel</a>
</div>

<!-- quick chips -->
<div class="chips">
    <a class="chip <?= $age==='90'?'on':'' ?>" href="<?= e(chip_url(['age'=>$age==='90'?'':'90','cat'=>'process'])) ?>">⏰ 90+ din pending</a>
    <a class="chip <?= $flag==='nmi'?'on':'' ?>" href="<?= e(chip_url(['flag'=>$flag==='nmi'?'':'nmi'])) ?>">📝 Need More Info</a>
    <a class="chip <?= $flag==='fu'?'on':'' ?>" href="<?= e(chip_url(['flag'=>$flag==='fu'?'':'fu'])) ?>">🚩 Followup</a>
    <a class="chip <?= $flag==='cred'?'on':'' ?>" href="<?= e(chip_url(['flag'=>$flag==='cred'?'':'cred'])) ?>">💳 Credited</a>
    <a class="chip <?= $ptype==='I'?'on':'' ?>" href="<?= e(chip_url(['ptype'=>$ptype==='I'?'':'I'])) ?>">IPD</a>
    <a class="chip <?= $ptype==='O'?'on':'' ?>" href="<?= e(chip_url(['ptype'=>$ptype==='O'?'':'O'])) ?>">OPD</a>
    <a class="chip <?= ($from===date('Y-m-01'))?'on':'' ?>" href="<?= e(chip_url(['from'=>date('Y-m-01'),'to'=>date('Y-m-d')])) ?>">📅 Is mahine</a>
    <?php if ($q||$status||$ptype||$from||$to||$amin||$amax||$cat||$age||$flag): ?>
        <a class="chip clear" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">✕ Clear all</a>
    <?php endif; ?>
</div>

<form class="searchbar" method="get">
    <input type="hidden" name="scheme" value="ECHS">
    <?php foreach (['cat'=>$cat,'age'=>$age,'flag'=>$flag,'sort'=>$sort,'dir'=>strtolower($dir),'per'=>$per] as $k=>$v): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Claim ID, Card, ESM, Patient, Settlement ID, remark...">
    <select name="status">
        <option value="">All status</option>
        <?php foreach (echs_status_list() as $s): ?>
            <option value="<?= e($s['status']) ?>" <?= $status===$s['status']?'selected':'' ?>><?= e($s['status']) ?> (<?= $s['n'] ?>)</option>
        <?php endforeach; ?>
    </select>
    <label class="inline">From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="inline">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="inline">₹ <input type="number" name="amin" value="<?= e($amin) ?>" placeholder="min" style="width:88px"></label>
    <label class="inline">– <input type="number" name="amax" value="<?= e($amax) ?>" placeholder="max" style="width:88px"></label>
    <select name="per"><?php foreach ([50,100,200] as $pp): ?><option value="<?= $pp ?>" <?= $per==$pp?'selected':'' ?>><?= $pp ?>/page</option><?php endforeach; ?></select>
    <button class="btn btn-primary">Filter</button>
</form>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($totalRows) ?></div><div class="stat-lbl">Claims (filter)</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($T['net']) ?></div><div class="stat-lbl">Net Claim Amt</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= money($T['app']) ?></div><div class="stat-lbl">Approved Amt</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= money($T['ded']) ?></div><div class="stat-lbl">Deduction</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= money($T['cred']) ?></div><div class="stat-lbl">Amt Credited</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= money($T['tds']) ?></div><div class="stat-lbl">TDS</div></div>
</div>

<div class="card">
<?php if (!$rows): ?>
    <p class="muted">Koi claim nahi mila. <a href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">Excel upload karein →</a></p>
<?php else: ?>
    <form method="post" id="bulkForm">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($curUrl) ?>">
        <div class="bulkbar">
            <label class="chk"><input type="checkbox" id="selAll" onclick="selAll(this)"> Select all</label>
            <button class="btn btn-light" name="act" value="flag">🚩 Flag</button>
            <button class="btn btn-light" name="act" value="unflag">Unflag</button>
            <span class="bulk-doc">
                🩺 <input name="doctor_name" list="doclist" placeholder="Doctor ka naam" autocomplete="off" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px">
                <button class="btn btn-light" name="act" value="setdoctor">Set doctor (selected)</button>
            </span>
            <span class="muted small" id="selCount"></span>
        </div>
        <datalist id="doclist"><?php foreach (echs_doctor_list() as $dn): ?><option value="<?= e($dn) ?>"></option><?php endforeach; ?></datalist>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr>
                <th style="width:28px"></th>
                <th><?= sort_link('claim','Claim ID') ?></th>
                <th>Card ID</th><th>ESM / Patient</th><th>Type</th>
                <th><?= sort_link('accept','Accept') ?></th>
                <th>Age</th>
                <th class="r"><?= sort_link('net','Net') ?></th>
                <th class="r"><?= sort_link('app','Appr.') ?></th>
                <th class="r"><?= sort_link('ded','Deduct') ?></th>
                <th class="r"><?= sort_link('credited','Credited') ?></th>
                <th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $c): $ded = (float)$c['net_claim_amt'] - (float)$c['approved_amt']; ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= e($c['claim_id']) ?>" class="rowchk" onclick="updCount()"></td>
                    <td class="nowrap">
                        <a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>"><?= e($c['claim_id']) ?></a>
                        <?php if (!empty($c['nmi_remarks'])): ?> <span title="<?= e($c['nmi_remarks']) ?>">📝</span><?php endif; ?>
                        <?php if (!empty($c['followup'])): ?> <span title="Follow-up">🚩</span><?php endif; ?>
                    </td>
                    <td><?= e($c['card_id']) ?></td>
                    <td><strong><?= e($c['esm_name']) ?></strong><?php if($c['patient_name']!==$c['esm_name']): ?><br><span class="muted small"><?= e($c['patient_name']) ?></span><?php endif; ?><?php if(!empty($c['doctor_name'])): ?><br><span class="muted small">🩺 <?= e($c['doctor_name']) ?></span><?php endif; ?></td>
                    <td><?= e($c['patient_type']) ?>/<?= e($c['admit_type']) ?></td>
                    <td class="nowrap"><?= e($c['accept_date_raw'] ?: '-') ?></td>
                    <td><?= age_badge($c['age_days']) ?></td>
                    <td class="r"><?= inr($c['net_claim_amt'],0) ?></td>
                    <td class="r"><?= inr($c['approved_amt'],0) ?></td>
                    <td class="r"><?= $ded>0?inr($ded,0):'-' ?></td>
                    <td class="r"><?= (float)$c['amt_credited']>0?inr($c['amt_credited'],0):'-' ?></td>
                    <td><span class="pill pill-<?= echs_category($c['status']) ?>"><?= e($c['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </form>

    <div class="pager">
        <span class="muted small">Page <?= $page ?> / <?= $totalPages ?> · <?= number_format($totalRows) ?> claims</span>
        <span class="pager-links">
            <?php if ($page > 1): ?><a class="btn btn-light" href="?<?= e($qs) ?>&page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-light" href="?<?= e($qs) ?>&page=<?= $page+1 ?>">Next →</a><?php endif; ?>
        </span>
    </div>
<?php endif; ?>
</div>

<script>
function selAll(cb){ document.querySelectorAll('.rowchk').forEach(function(c){c.checked=cb.checked;}); updCount(); }
function updCount(){ var n=document.querySelectorAll('.rowchk:checked').length; document.getElementById('selCount').textContent = n?(n+' selected'):''; }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
