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

// ---- bulk actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['act'] ?? '';
    $tids = array_values(array_filter((array)($_POST['tids'] ?? []), 'strlen'));
    $ret  = $_POST['return'] ?? (BASE_URL.'/rghs_claims.php?scheme=RGHS');
    if ($tids) {
        $in = implode(',', array_fill(0, count($tids), '?'));
        if ($act === 'setdoctor') {
            $doc = trim($_POST['doctor_name'] ?? '');
            $stmt = $pdo->prepare("UPDATE rghs_claims SET doctor_name=?, doctor_manual=1, updated_at=NOW() WHERE tid IN ($in)");
            $stmt->execute(array_merge([$doc ?: null], $tids));
            if ($doc !== '') { try { $pdo->prepare("INSERT IGNORE INTO rghs_doctors (name) VALUES (?)")->execute([$doc]); } catch (Exception $e) {} }
            rghs_log('bulk_setdoctor', count($tids)." claims -> ".$doc);
            flash(count($tids)." claims me doctor set ho gaya.");
        } elseif ($act === 'assign') {
            $who = trim($_POST['assignee'] ?? '');
            $stmt = $pdo->prepare("UPDATE rghs_claims SET assigned_to=?, updated_at=NOW() WHERE tid IN ($in)");
            $stmt->execute(array_merge([$who ?: null], $tids));
            rghs_log('bulk_assign', count($tids)." -> ".$who);
            flash(count($tids)." claims assign ho gaye.");
        } elseif ($act === 'flag') {
            $pdo->prepare("UPDATE rghs_claims SET followup=1 WHERE tid IN ($in)")->execute($tids);
            rghs_log('bulk_flag', count($tids).' claims');
            flash(count($tids)." claims flag ho gaye.");
        } elseif ($act === 'unflag') {
            $pdo->prepare("UPDATE rghs_claims SET followup=0 WHERE tid IN ($in)")->execute($tids);
            flash(count($tids)." claims ka flag hata.");
        } elseif ($act === 'addtask') {
            $title = trim($_POST['title'] ?? ''); $due = trim($_POST['due_date'] ?? '') ?: null;
            if ($title !== '') {
                $ins = $pdo->prepare("INSERT INTO rghs_tasks (tid,title,due_date) VALUES (?,?,?)");
                foreach ($tids as $t) $ins->execute([$t, $title, $due]);
                rghs_log('bulk_task', count($tids)." claims: ".$title);
                flash(count($tids)." claims ke liye task ban gaya.");
            }
        }
    }
    redirect($ret);
}

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
        submit_date, claim_amt, cu_amt, sub_year, sub_month, paid_amount, payment_status
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
        <a class="btn" href="<?= BASE_URL ?>/api/rghs_export_xls.php?<?= http_build_query(array_merge($_GET,['scheme'=>'RGHS'])) ?>">⬇ Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/api/rghs_export_csv.php?<?= http_build_query(array_merge($_GET,['scheme'=>'RGHS'])) ?>">⬇ CSV</a>
    </div>
</div>

<?php $Y = date('Y'); ?>
<div class="chips" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <a class="chip" href="<?= qs(['cat'=>'approved','pay'=>'unpaid','age'=>null,'flag'=>null,'page'=>1]) ?>">💰 Approved par unpaid</a>
    <a class="chip" href="<?= qs(['cat'=>'approved','pay'=>'unpaid','age'=>90,'page'=>1]) ?>">⏰ 90+ din unpaid</a>
    <a class="chip" href="<?= qs(['cat'=>'query','pay'=>null,'age'=>null,'flag'=>null,'page'=>1]) ?>">❓ Query/stuck</a>
    <a class="chip" href="<?= qs(['flag'=>'nodoctor','cat'=>null,'pay'=>null,'age'=>null,'page'=>1]) ?>">🩺 Bina doctor</a>
    <a class="chip" href="<?= qs(['flag'=>'followup','cat'=>null,'pay'=>null,'age'=>null,'page'=>1]) ?>">🚩 Follow-up</a>
    <a class="chip" href="<?= qs(['year'=>$Y,'cat'=>null,'pay'=>null,'age'=>null,'flag'=>null,'page'=>1]) ?>">📅 <?= $Y ?></a>
</div>

<div class="tabs">
    <?php foreach (['all'=>'All','approved'=>'Approved','pending'=>'Pending','query'=>'Query','rejected'=>'Rejected'] as $k=>$lbl):
        $on = ($curCat===$k || ($k==='all' && $curCat===''))?'on':''; ?>
        <a class="tab <?= $on ?>" href="<?= qs(['cat'=>$k==='all'?'':$k,'page'=>1]) ?>"><?= $lbl ?> <span class="muted">(<?= number_format($tabCounts[$k]) ?>)</span></a>
    <?php endforeach; ?>
</div>

<?php $advOpen = array_intersect_key($_GET, ['from'=>1,'to'=>1,'amin'=>1,'amax'=>1,'age'=>1,'hosp'=>1,'flag'=>1]); ?>
<form class="searchbar" method="get" style="margin-bottom:6px">
    <input type="hidden" name="scheme" value="RGHS">
    <?php if ($curCat): ?><input type="hidden" name="cat" value="<?= e($curCat) ?>"><?php endif; ?>
    <input type="hidden" name="dir" value="<?= e(strtolower($dir)) ?>">
    <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search — TID, patient, card, doctor, mobile…  ya  card:12345  doctor:sharma  amt&gt;5000  age&gt;60" style="min-width:260px;flex:1">
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
    <select name="pay">
        <option value="">Payment: sab</option>
        <option value="paid" <?= ($_GET['pay']??'')==='paid'?'selected':'' ?>>Paid ✓</option>
        <option value="process" <?= ($_GET['pay']??'')==='process'?'selected':'' ?>>In process</option>
        <option value="unpaid" <?= ($_GET['pay']??'')==='unpaid'?'selected':'' ?>>Unpaid</option>
    </select>
    <select name="assignee">
        <option value="">Assignee: sab</option>
        <option value="__none" <?= ($_GET['assignee']??'')==='__none'?'selected':'' ?>>Bina assign</option>
        <?php foreach (rghs_staff_list() as $sf): ?><option value="<?= e($sf) ?>" <?= ($_GET['assignee']??'')===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <?php if (array_diff_key($_GET, ['scheme'=>1,'page'=>1,'sort'=>1,'dir'=>1,'per'=>1])): ?>
        <a class="btn btn-light" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">Reset</a>
    <?php endif; ?>

    <details class="adv" style="width:100%;margin-top:8px" <?= $advOpen?'open':'' ?>>
        <summary class="link" style="cursor:pointer;font-size:.85rem">⚙️ Advanced filters</summary>
        <div class="adv-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px">
            <label class="fld"><span class="muted small">Submit date se</span><input type="date" name="from" value="<?= e($_GET['from']??'') ?>"></label>
            <label class="fld"><span class="muted small">Submit date tak</span><input type="date" name="to" value="<?= e($_GET['to']??'') ?>"></label>
            <label class="fld"><span class="muted small">Amount min ₹</span><input type="number" name="amin" value="<?= e($_GET['amin']??'') ?>" placeholder="0"></label>
            <label class="fld"><span class="muted small">Amount max ₹</span><input type="number" name="amax" value="<?= e($_GET['amax']??'') ?>" placeholder="—"></label>
            <label class="fld"><span class="muted small">Age (din se purana)</span><input type="number" name="age" value="<?= e($_GET['age']??'') ?>" placeholder="e.g. 90"></label>
            <label class="fld"><span class="muted small">Hospital</span><input type="text" name="hosp" value="<?= e($_GET['hosp']??'') ?>" placeholder="hospital naam"></label>
            <label class="fld"><span class="muted small">Flag</span>
                <select name="flag">
                    <option value="">— koi nahi —</option>
                    <?php foreach (['nodoctor'=>'Bina doctor','followup'=>'Follow-up','hasdoc'=>'Document laga hai','hasquery'=>'Query khuli hai','noteflag'=>'Note likha hai','dupe'=>'Duplicate mark'] as $fk=>$fl): ?>
                        <option value="<?= $fk ?>" <?= ($_GET['flag']??'')===$fk?'selected':'' ?>><?= e($fl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="fld"><span class="muted small">Sort</span>
                <select name="sort">
                    <?php foreach (['submit_date'=>'Submit date','claim_amt'=>'Claimed','cu_amt'=>'Approved','patient_name'=>'Patient','status'=>'Status','tid'=>'TID'] as $sk=>$sl): ?>
                        <option value="<?= $sk ?>" <?= $sort===$sk?'selected':'' ?>><?= e($sl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </details>
</form>
<p class="muted small" style="margin:0 0 12px">💡 <strong>Tip:</strong> kai shabd likho (sab match honge) · <code>card:12345</code> <code>doctor:sharma</code> <code>status:approved</code> <code>dept:cardio</code> <code>amt&gt;5000</code> <code>age&gt;90</code> · <code>"exact phrase"</code></p>

<?php if (!empty($_GET['doctor'])): ?>
    <p class="muted small">Doctor filter: <strong><?= e($_GET['doctor']) ?></strong> · <a class="link" href="<?= qs(['doctor'=>null]) ?>">hataayein</a></p>
<?php endif; ?>

<?php $curUrl = qs([]); $docList = rghs_doctor_list(); ?>
<datalist id="docs"><?php foreach ($docList as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>

<form method="post" id="bulkForm">
<?= csrf_field() ?>
<input type="hidden" name="act" id="bulkAct" value="">
<input type="hidden" name="return" value="<?= e($curUrl) ?>">

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <div class="muted small">
            <?= number_format($totCount) ?> claims · Claimed <?= money($agg['claim']) ?> · Approved(CU) <?= money($agg['cu']) ?>
        </div>
        <div id="bulkBar" class="muted small" style="display:none;align-items:center;gap:8px;flex-wrap:wrap">
            <strong><span id="selCount">0</span> selected:</strong>
            <input list="docs" id="bulkDoc" placeholder="Doctor naam" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px">
            <button type="button" class="btn btn-light" onclick="bulk('setdoctor')">Set doctor</button>
            <select id="bulkAssignee" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px">
                <option value="">— assign to —</option>
                <?php foreach (rghs_staff_list() as $sf): ?><option value="<?= e($sf) ?>"><?= e($sf) ?></option><?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-light" onclick="bulk('assign')">Assign</button>
            <button type="button" class="btn btn-light" onclick="bulk('flag')">🚩 Flag</button>
            <button type="button" class="btn btn-light" onclick="bulk('unflag')">Unflag</button>
            <input id="bulkTitle" placeholder="Task title" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px">
            <input id="bulkDue" type="date" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px">
            <button type="button" class="btn btn-light" onclick="bulk('addtask')">+ Task</button>
        </div>
    </div>
    <div class="tbl-scroll">
    <table class="tbl">
        <thead><tr>
            <th style="width:28px"><input type="checkbox" id="selAll" onclick="toggleAll(this)"></th>
            <th><?= sortLink('tid','TID') ?></th>
            <th><?= sortLink('patient_name','Patient') ?></th>
            <th>Type</th>
            <th><?= sortLink('status','Status') ?></th>
            <th>Doctor</th>
            <th><?= sortLink('submit_date','Submitted') ?></th>
            <th class="r"><?= sortLink('claim_amt','Claimed') ?></th>
            <th class="r"><?= sortLink('cu_amt','Approved') ?></th>
            <th class="r"><?= sortLink('paid_amount','Paid') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="10" class="muted" style="text-align:center;padding:24px">Kuch nahi mila.</td></tr>
        <?php else: foreach ($rows as $c): $openUrl = BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($c['tid']); ?>
            <tr class="clk" data-href="<?= e($openUrl) ?>" style="cursor:pointer">
                <td><input type="checkbox" class="rowchk" name="tids[]" value="<?= e($c['tid']) ?>" onclick="updSel()"></td>
                <td><a class="link" style="color:var(--brand);text-decoration:underline" href="<?= e($openUrl) ?>"><?= e($c['tid']) ?></a></td>
                <td><?= e($c['patient_name'] ?: '-') ?><div class="muted small"><?= e($c['card_no']) ?></div></td>
                <td class="small"><?= e($c['claim_type'] ?: '-') ?></td>
                <td><span class="pill pill-<?= rghs_category($c['status']) === 'approved' ? 'settled' : (rghs_category($c['status'])==='rejected'?'rejected':(rghs_category($c['status'])==='query'?'info':'process')) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                <td class="small"><?= e($c['doctor_name'] ?: '-') ?></td>
                <td class="small"><?= $c['submit_date'] ? e(date('d-m-y', strtotime($c['submit_date']))) : '-' ?></td>
                <td class="r"><?= inr($c['claim_amt'],0) ?></td>
                <td class="r"><?= inr($c['cu_amt'],0) ?></td>
                <td class="r"><?php if ($c['paid_amount']>0): ?><span title="<?= e($c['payment_status']) ?>"><?= inr($c['paid_amount'],0) ?></span><?php elseif (stripos((string)$c['payment_status'],'process')!==false): ?><span class="muted small">process</span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
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
</form>

<script>
function selected(){ return Array.prototype.slice.call(document.querySelectorAll('.rowchk:checked')); }
function updSel(){
    var n = selected().length;
    document.getElementById('selCount').textContent = n;
    document.getElementById('bulkBar').style.display = n>0 ? 'flex' : 'none';
}
function toggleAll(cb){ document.querySelectorAll('.rowchk').forEach(function(c){ c.checked = cb.checked; }); updSel(); }
function bulk(act){
    if (!selected().length){ alert('Pehle kuch claims select karein.'); return; }
    if (act==='setdoctor'){ var d=document.getElementById('bulkDoc').value.trim(); if(!d){alert('Doctor naam daalein.');return;}
        addHidden('doctor_name', d); }
    if (act==='addtask'){ var t=document.getElementById('bulkTitle').value.trim(); if(!t){alert('Task title daalein.');return;}
        addHidden('title', t); addHidden('due_date', document.getElementById('bulkDue').value); }
    if (act==='assign'){ addHidden('assignee', document.getElementById('bulkAssignee').value); }
    if (act==='unflag' && !confirm('Selected claims ka follow-up flag hataayein?')) return;
    document.getElementById('bulkAct').value = act;
    document.getElementById('bulkForm').submit();
}
function addHidden(name,val){ var i=document.createElement('input'); i.type='hidden'; i.name=name; i.value=val; document.getElementById('bulkForm').appendChild(i); }
// whole-row click opens the claim (ignore clicks on checkbox / links / buttons)
document.querySelectorAll('tr.clk').forEach(function(tr){
    tr.addEventListener('click', function(e){
        if (e.target.closest('input,a,button,label,select')) return;
        window.location.href = tr.getAttribute('data-href');
    });
});
// auto-apply filters: koi bhi filter badlo, bina "Filter" dabaye result aa jaye
(function(){
    var f = document.querySelector('form.searchbar');
    if (!f) return;
    f.querySelectorAll('select').forEach(function(el){ el.addEventListener('change', function(){ f.submit(); }); });
    f.querySelectorAll('input[type=date], input[type=number]').forEach(function(el){ el.addEventListener('change', function(){ f.submit(); }); });
    // other text boxes (year, hospital) auto-submit on blur/Enter — but NOT the live q box
    f.querySelectorAll('input[type=text]').forEach(function(el){ if (el.name==='q') return; el.addEventListener('change', function(){ f.submit(); }); });
    // live search: type karte hi (thoda rukte hi) result — min 2 chars
    var qbox = f.querySelector('input[name=q]');
    if (qbox){
        var t;
        qbox.addEventListener('input', function(){
            clearTimeout(t);
            var v = qbox.value.trim();
            if (v.length === 1) return;
            t = setTimeout(function(){ f.submit(); }, 600);
        });
        qbox.addEventListener('change', function(){ clearTimeout(t); f.submit(); });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
