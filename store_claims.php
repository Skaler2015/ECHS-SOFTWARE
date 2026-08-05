<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/icons.php';
store_ensure_table();

$scheme = 'STORE';
$meta   = scheme_meta('STORE');
$active = 'claims';
$page_title = 'Medical Store Invoices';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'strlen'));
    $ret = $_POST['return'] ?? (BASE_URL.'/store_claims.php?scheme=STORE');
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($act === 'assign') {
            $who = trim($_POST['assignee'] ?? '');
            $pdo->prepare("UPDATE store_claims SET assigned_to=?, updated_at=NOW() WHERE invoice_no IN ($in)")->execute(array_merge([$who ?: null], $ids));
            store_log('bulk_assign', count($ids).' -> '.$who); flash(count($ids)." invoices assign ho gaye.");
        } elseif ($act === 'flag')   { $pdo->prepare("UPDATE store_claims SET followup=1 WHERE invoice_no IN ($in)")->execute($ids); flash(count($ids)." flag ho gaye."); }
        elseif ($act === 'unflag') { $pdo->prepare("UPDATE store_claims SET followup=0 WHERE invoice_no IN ($in)")->execute($ids); flash(count($ids)." ka flag hata."); }
    }
    redirect($ret);
}

[$where, $args] = store_build_filter($_GET);
$sortable = ['submit_date'=>'submit_date','claim_amt'=>'claim_amt','cu_amt'=>'cu_amt','patient_name'=>'patient_name','status'=>'status','invoice_no'=>'invoice_no'];
$sort = $_GET['sort'] ?? 'submit_date'; if (!isset($sortable[$sort])) $sort = 'submit_date';
$dir = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC'; $orderCol = $sortable[$sort];
$per = (int)($_GET['per'] ?? 50); if (!in_array($per, [50,100,200])) $per = 50;
$page = max(1, (int)($_GET['page'] ?? 1)); $offset = ($page-1)*$per;

$cst = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu FROM store_claims $where");
$cst->execute($args); $agg = $cst->fetch(); $totCount = (int)$agg['n']; $pages = max(1, (int)ceil($totCount/$per));

$sql = "SELECT invoice_no, tid, patient_name, card_no, status, category, submit_date, claim_amt, cu_amt, sub_year
        FROM store_claims $where ORDER BY `$orderCol` $dir, invoice_no $dir LIMIT $per OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll();

$tabCounts = ['all'=>0,'approved'=>0,'pending'=>0,'rejected'=>0,'deleted'=>0,'query'=>0];
$tabCounts['all'] = (int)$pdo->query("SELECT COUNT(*) n FROM store_claims")->fetch()['n'];
foreach ($pdo->query("SELECT category, COUNT(*) n FROM store_claims GROUP BY category") as $r) { $k=$r['category']?:'pending'; if(isset($tabCounts[$k])) $tabCounts[$k]+=(int)$r['n']; }

$curCat = $_GET['cat'] ?? '';
function qs($over){ $q=array_merge($_GET,$over); $q['scheme']='STORE'; return BASE_URL.'/store_claims.php?'.http_build_query(array_filter($q, function($v){ return $v!==null && $v!==''; })); }
function sortLink($col,$label){ global $sort,$dir; $ndir=($sort===$col && strtolower($dir)==='asc')?'desc':'asc'; $arr=$sort===$col?(strtolower($dir)==='asc'?' ▲':' ▼'):''; return '<a class="link" href="'.qs(['sort'=>$col,'dir'=>$ndir,'page'=>1]).'">'.e($label).$arr.'</a>'; }
function scat_pill($cat){ switch($cat){ case 'approved':return 'settled'; case 'rejected':case 'deleted':return 'rejected'; case 'query':return 'info'; default:return 'warn'; } }
$statusList = store_status_list();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>💊 Medical Store Invoices</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/store_upload.php?scheme=STORE">⬆ Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/api/store_export_csv.php?<?= http_build_query(array_merge($_GET,['scheme'=>'STORE'])) ?>">⬇ CSV</a>
    </div>
</div>

<div class="tabs">
    <?php foreach (['all'=>'All','approved'=>'Approved','pending'=>'Pending','rejected'=>'Rejected','deleted'=>'Deleted'] as $k=>$lbl):
        $on = ($curCat===$k || ($k==='all' && $curCat===''))?'on':''; ?>
        <a class="tab <?= $on ?>" href="<?= qs(['cat'=>$k==='all'?'':$k,'page'=>1]) ?>"><?= $lbl ?> <span class="muted">(<?= number_format($tabCounts[$k]) ?>)</span></a>
    <?php endforeach; ?>
</div>

<?php $advOpen = array_intersect_key($_GET, ['from'=>1,'to'=>1,'amin'=>1,'amax'=>1,'age'=>1,'flag'=>1,'year'=>1]); ?>
<form class="searchbar" method="get" style="margin-bottom:6px">
    <input type="hidden" name="scheme" value="STORE">
    <?php if ($curCat): ?><input type="hidden" name="cat" value="<?= e($curCat) ?>"><?php endif; ?>
    <input type="hidden" name="dir" value="<?= e(strtolower($dir)) ?>">
    <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search — invoice, TID, patient, card, mobile…  ya  card:123  amt&gt;500  age&gt;60" style="flex:1;min-width:260px">
    <select name="status"><option value="">Sab status</option>
        <?php foreach ($statusList as $s): if(!$s['status'])continue; ?><option value="<?= e($s['status']) ?>" <?= ($_GET['status']??'')===$s['status']?'selected':'' ?>><?= e($s['status']) ?> (<?= $s['n'] ?>)</option><?php endforeach; ?>
    </select>
    <select name="assignee"><option value="">Assignee: sab</option><option value="__none" <?= ($_GET['assignee']??'')==='__none'?'selected':'' ?>>Bina assign</option>
        <?php foreach (store_staff_list() as $sf): ?><option value="<?= e($sf) ?>" <?= ($_GET['assignee']??'')===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <?php if (array_diff_key($_GET, ['scheme'=>1,'page'=>1,'sort'=>1,'dir'=>1,'per'=>1])): ?><a class="btn btn-light" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE">Reset</a><?php endif; ?>
    <details class="adv" style="width:100%;margin-top:8px" <?= $advOpen?'open':'' ?>>
        <summary class="link" style="cursor:pointer;font-size:.85rem">⚙️ Advanced filters</summary>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px">
            <label class="fld"><span class="muted small">Submit se</span><input type="date" name="from" value="<?= e($_GET['from']??'') ?>"></label>
            <label class="fld"><span class="muted small">Submit tak</span><input type="date" name="to" value="<?= e($_GET['to']??'') ?>"></label>
            <label class="fld"><span class="muted small">Amount min ₹</span><input type="number" name="amin" value="<?= e($_GET['amin']??'') ?>"></label>
            <label class="fld"><span class="muted small">Amount max ₹</span><input type="number" name="amax" value="<?= e($_GET['amax']??'') ?>"></label>
            <label class="fld"><span class="muted small">Year</span><input type="number" name="year" value="<?= e($_GET['year']??'') ?>" placeholder="2022"></label>
            <label class="fld"><span class="muted small">Age (din se purana)</span><input type="number" name="age" value="<?= e($_GET['age']??'') ?>"></label>
            <label class="fld"><span class="muted small">Sort</span><select name="sort"><?php foreach (['submit_date'=>'Submit date','claim_amt'=>'Claimed','cu_amt'=>'CU Approved','patient_name'=>'Patient','status'=>'Status','invoice_no'=>'Invoice'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $sort===$sk?'selected':'' ?>><?= e($sl) ?></option><?php endforeach; ?></select></label>
        </div>
    </details>
</form>
<p class="muted small" style="margin:0 0 12px">💡 <code>invoice:SIKR</code> <code>tid:2022</code> <code>card:123</code> <code>amt&gt;500</code> <code>age&gt;60</code> · TID par click = us RGHS claim ka panel.</p>

<?php $curUrl = qs([]); ?>
<form method="post" id="bulkForm"><?= csrf_field() ?><input type="hidden" name="act" id="bulkAct" value=""><input type="hidden" name="return" value="<?= e($curUrl) ?>">
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <div class="muted small"><?= number_format($totCount) ?> invoices · Claimed <?= money($agg['claim']) ?> · CU Approved <?= money($agg['cu']) ?></div>
        <div id="bulkBar" class="muted small" style="display:none;align-items:center;gap:8px;flex-wrap:wrap">
            <strong><span id="selCount">0</span> selected:</strong>
            <select id="bulkAssignee" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px"><option value="">— assign to —</option><?php foreach (store_staff_list() as $sf): ?><option value="<?= e($sf) ?>"><?= e($sf) ?></option><?php endforeach; ?></select>
            <button type="button" class="btn btn-light" onclick="bulk('assign')">Assign</button>
            <button type="button" class="btn btn-light" onclick="bulk('flag')">🚩 Flag</button>
            <button type="button" class="btn btn-light" onclick="bulk('unflag')">Unflag</button>
        </div>
    </div>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr>
            <th style="width:28px"><input type="checkbox" id="selAll" onclick="toggleAll(this)"></th>
            <th><?= sortLink('invoice_no','Invoice No') ?></th>
            <th>TID (RGHS)</th>
            <th><?= sortLink('patient_name','Patient') ?></th>
            <th><?= sortLink('status','Status') ?></th>
            <th><?= sortLink('submit_date','Submitted') ?></th>
            <th class="r"><?= sortLink('claim_amt','Claimed') ?></th>
            <th class="r"><?= sortLink('cu_amt','CU Approved') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:24px">Kuch nahi mila.</td></tr>
        <?php else: foreach ($rows as $c): $openUrl = BASE_URL.'/store_claim.php?scheme=STORE&inv='.urlencode($c['invoice_no']); ?>
            <tr class="clk" data-href="<?= e($openUrl) ?>" style="cursor:pointer">
                <td><input type="checkbox" class="rowchk" name="ids[]" value="<?= e($c['invoice_no']) ?>" onclick="updSel()"></td>
                <td><a class="link" style="color:var(--brand);text-decoration:underline" href="<?= e($openUrl) ?>"><?= e($c['invoice_no']) ?></a></td>
                <td class="small"><?php if($c['tid']): ?><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($c['tid']) ?>" title="RGHS claim kholein"><?= e($c['tid']) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
                <td><?= e($c['patient_name'] ?: '-') ?><div class="muted small"><?= e($c['card_no']) ?></div></td>
                <td><span class="pill pill-<?= scat_pill($c['category']) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                <td class="small"><?= $c['submit_date'] ? e(date('d-m-y', strtotime($c['submit_date']))) : '-' ?></td>
                <td class="r"><?= inr($c['claim_amt'],0) ?></td>
                <td class="r"><?= inr($c['cu_amt'],0) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
    <div class="pager" style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($page>1): ?><a class="btn btn-light" href="<?= qs(['page'=>$page-1]) ?>">← Prev</a><?php endif; ?>
        <span class="muted small">Page <?= $page ?> / <?= $pages ?></span>
        <?php if ($page<$pages): ?><a class="btn btn-light" href="<?= qs(['page'=>$page+1]) ?>">Next →</a><?php endif; ?>
        <span style="flex:1"></span>
        <?php foreach ([50,100,200] as $pp): ?><a class="link small <?= $per===$pp?'':'muted' ?>" href="<?= qs(['per'=>$pp,'page'=>1]) ?>"><?= $pp ?></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</form>

<script>
function selected(){ return Array.prototype.slice.call(document.querySelectorAll('.rowchk:checked')); }
function updSel(){ var n=selected().length; document.getElementById('selCount').textContent=n; document.getElementById('bulkBar').style.display=n>0?'flex':'none'; }
function toggleAll(cb){ document.querySelectorAll('.rowchk').forEach(function(c){ c.checked=cb.checked; }); updSel(); }
function bulk(act){ if(!selected().length){ alert('Pehle kuch invoices select karein.'); return; }
    if(act==='assign'){ addHidden('assignee', document.getElementById('bulkAssignee').value); }
    if(act==='unflag' && !confirm('Flag hataayein?')) return;
    document.getElementById('bulkAct').value=act; document.getElementById('bulkForm').submit(); }
function addHidden(n,v){ var i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; document.getElementById('bulkForm').appendChild(i); }
document.querySelectorAll('tr.clk').forEach(function(tr){ tr.addEventListener('click', function(e){
    if (e.target.closest('input,a,button,label,select')) return;
    var url=tr.getAttribute('data-href'); if(window.openClaimDrawer) window.openClaimDrawer(url); else window.location.href=url; }); });
(function(){ var f=document.querySelector('form.searchbar'); if(!f) return;
    f.querySelectorAll('select').forEach(function(el){ el.addEventListener('change', function(){ f.submit(); }); });
    f.querySelectorAll('input[type=date], input[type=number]').forEach(function(el){ el.addEventListener('change', function(){ f.submit(); }); });
    var qbox=f.querySelector('input[name=q]'); if(qbox){ var t; qbox.addEventListener('input', function(){ clearTimeout(t); if(qbox.value.trim().length===1) return; t=setTimeout(function(){ f.submit(); },600); }); qbox.addEventListener('change', function(){ clearTimeout(t); f.submit(); }); }
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
