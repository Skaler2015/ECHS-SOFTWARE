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
$pdo = db();

// ---- bulk actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['act'] ?? '';
    $ids  = array_values(array_filter((array)($_POST['ids'] ?? []), 'strlen'));
    $ret  = $_POST['return'] ?? (BASE_URL.'/echs_claims.php?scheme=ECHS');
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($act === 'setdoctor') {
            $doc = trim($_POST['doctor_name'] ?? '');
            $pdo->prepare("UPDATE echs_claims SET doctor_name=?, doctor_manual=1, updated_at=NOW() WHERE claim_id IN ($in)")
                ->execute(array_merge([$doc ?: null], $ids));
            if ($doc !== '') { try { $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)")->execute([$doc]); } catch (Exception $e) {} }
            echs_log('bulk_setdoctor', count($ids)." claims -> ".$doc);
            flash(count($ids)." claims me doctor set ho gaya.");
        } elseif ($act === 'assign') {
            $who = trim($_POST['assignee'] ?? '');
            $pdo->prepare("UPDATE echs_claims SET assigned_to=?, updated_at=NOW() WHERE claim_id IN ($in)")
                ->execute(array_merge([$who ?: null], $ids));
            echs_log('bulk_assign', count($ids)." -> ".$who);
            flash(count($ids)." claims assign ho gaye.");
        } elseif ($act === 'flag') {
            $pdo->prepare("UPDATE echs_claims SET followup=1 WHERE claim_id IN ($in)")->execute($ids);
            flash(count($ids)." claims flag ho gaye.");
        } elseif ($act === 'unflag') {
            $pdo->prepare("UPDATE echs_claims SET followup=0 WHERE claim_id IN ($in)")->execute($ids);
            flash(count($ids)." claims ka flag hata.");
        } elseif ($act === 'addtask') {
            $title = trim($_POST['title'] ?? ''); $due = trim($_POST['due_date'] ?? '') ?: null;
            if ($title !== '') {
                $ins = $pdo->prepare("INSERT INTO echs_tasks (claim_id,title,due_date) VALUES (?,?,?)");
                foreach ($ids as $t) $ins->execute([$t, $title, $due]);
                echs_log('bulk_task', count($ids)." claims: ".$title);
                flash(count($ids)." claims ke liye task ban gaya.");
            }
        }
    }
    redirect($ret);
}

[$where, $args] = echs_build_filter($_GET);

$sortable = ['accept_date'=>'accept_date','claim_amt'=>'claim_amt','approved_amt'=>'approved_amt','patient_name'=>'patient_name','status'=>'stage_order','claim_id'=>'claim_id'];
$sort = $_GET['sort'] ?? 'accept_date';
if (!isset($sortable[$sort])) $sort = 'accept_date';
$dir = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$orderCol = $sortable[$sort];

$per = (int)($_GET['per'] ?? 50); if (!in_array($per, [50,100,200])) $per = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page-1)*$per;

$cst = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(approved_amt),0) appr FROM echs_claims $where");
$cst->execute($args); $agg = $cst->fetch();
$totCount = (int)$agg['n'];
$pages = max(1, (int)ceil($totCount / $per));

$sql = "SELECT claim_id, patient_name, esm_name, card_id, patient_type, admit_type, status, category,
        region, doctor_name, accept_date, claim_amt, approved_amt
        FROM echs_claims $where ORDER BY `$orderCol` $dir, claim_id $dir LIMIT $per OFFSET $offset";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll();

// category tab counts (from the category column — fast)
$tabCounts = ['all'=>0,'settled'=>0,'inprocess'=>0,'query'=>0,'pending'=>0,'rejected'=>0,'cancelled'=>0];
$tabCounts['all'] = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'];
foreach ($pdo->query("SELECT category, COUNT(*) n FROM echs_claims GROUP BY category") as $r) {
    $k = $r['category'] ?: 'pending';
    if (isset($tabCounts[$k])) $tabCounts[$k] += (int)$r['n'];
}

$curCat = $_GET['cat'] ?? '';
function qs($over){ $q = array_merge($_GET, $over); $q['scheme']='ECHS'; return BASE_URL.'/echs_claims.php?'.http_build_query(array_filter($q, function($v){ return $v!==null && $v!==''; })); }
function sortLink($col,$label){ global $sort,$dir; $ndir = ($sort===$col && strtolower($dir)==='asc')?'desc':'asc';
    $arr = $sort===$col ? (strtolower($dir)==='asc'?' ▲':' ▼') : '';
    return '<a class="link" href="'.qs(['sort'=>$col,'dir'=>$ndir,'page'=>1]).'">'.e($label).$arr.'</a>'; }

function ecat_pill($cat){
    switch ($cat) {
        case 'settled':   return 'settled';
        case 'rejected':  return 'rejected';
        case 'cancelled': return 'rejected';
        case 'query':     return 'info';
        case 'inprocess': return 'process';
        default:          return 'warn';
    }
}

$statusList = echs_status_list();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>ECHS Claims</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">⬆ Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_csv.php?<?= http_build_query(array_merge($_GET,['scheme'=>'ECHS'])) ?>">⬇ CSV</a>
    </div>
</div>

<div class="chips" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <a class="chip" href="<?= qs(['cat'=>'query','flag'=>null,'age'=>null,'page'=>1]) ?>">❓ Query / Need info</a>
    <a class="chip" href="<?= qs(['cat'=>'pending','flag'=>null,'age'=>null,'page'=>1]) ?>">⏳ Pending</a>
    <a class="chip" href="<?= qs(['cat'=>'inprocess','flag'=>null,'age'=>null,'page'=>1]) ?>">⚙️ In-process</a>
    <a class="chip" href="<?= qs(['cat'=>'pending','age'=>60,'flag'=>null,'page'=>1]) ?>">⏰ 60+ din pending</a>
    <a class="chip" href="<?= qs(['flag'=>'nodoctor','cat'=>null,'age'=>null,'page'=>1]) ?>">🩺 Bina doctor</a>
    <a class="chip" href="<?= qs(['flag'=>'followup','cat'=>null,'age'=>null,'page'=>1]) ?>">🚩 Follow-up</a>
</div>

<div class="tabs">
    <?php foreach (['all'=>'All','settled'=>'Settled','inprocess'=>'In-process','query'=>'Query','pending'=>'Pending','rejected'=>'Rejected'] as $k=>$lbl):
        $on = ($curCat===$k || ($k==='all' && $curCat===''))?'on':''; ?>
        <a class="tab <?= $on ?>" href="<?= qs(['cat'=>$k==='all'?'':$k,'page'=>1]) ?>"><?= $lbl ?> <span class="muted">(<?= number_format($tabCounts[$k]) ?>)</span></a>
    <?php endforeach; ?>
</div>

<?php $advOpen = array_intersect_key($_GET, ['from'=>1,'to'=>1,'amin'=>1,'amax'=>1,'age'=>1,'region'=>1,'flag'=>1]); ?>
<form class="searchbar" method="get" style="margin-bottom:6px">
    <input type="hidden" name="scheme" value="ECHS">
    <?php if ($curCat): ?><input type="hidden" name="cat" value="<?= e($curCat) ?>"><?php endif; ?>
    <input type="hidden" name="dir" value="<?= e(strtolower($dir)) ?>">
    <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search — claim id, patient, ESM, card, doctor…  ya  card:12345  doctor:sharma  amt&gt;5000  age&gt;60" style="min-width:260px;flex:1">
    <select name="type">
        <option value="">OPD/IPD: sab</option>
        <option value="O" <?= ($_GET['type']??'')==='O'?'selected':'' ?>>OPD</option>
        <option value="I" <?= ($_GET['type']??'')==='I'?'selected':'' ?>>IPD</option>
    </select>
    <select name="status">
        <option value="">Sab status</option>
        <?php foreach ($statusList as $s): if(!$s['status'])continue; ?>
            <option value="<?= e($s['status']) ?>" <?= ($_GET['status']??'')===$s['status']?'selected':'' ?>><?= e($s['status']) ?> (<?= $s['n'] ?>)</option>
        <?php endforeach; ?>
    </select>
    <select name="assignee">
        <option value="">Assignee: sab</option>
        <option value="__none" <?= ($_GET['assignee']??'')==='__none'?'selected':'' ?>>Bina assign</option>
        <?php foreach (echs_staff_list() as $sf): ?><option value="<?= e($sf) ?>" <?= ($_GET['assignee']??'')===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Filter</button>
    <?php if (array_diff_key($_GET, ['scheme'=>1,'page'=>1,'sort'=>1,'dir'=>1,'per'=>1])): ?>
        <a class="btn btn-light" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">Reset</a>
    <?php endif; ?>

    <details class="adv" style="width:100%;margin-top:8px" <?= $advOpen?'open':'' ?>>
        <summary class="link" style="cursor:pointer;font-size:.85rem">⚙️ Advanced filters</summary>
        <div class="adv-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px">
            <label class="fld"><span class="muted small">Accept date se</span><input type="date" name="from" value="<?= e($_GET['from']??'') ?>"></label>
            <label class="fld"><span class="muted small">Accept date tak</span><input type="date" name="to" value="<?= e($_GET['to']??'') ?>"></label>
            <label class="fld"><span class="muted small">Amount min ₹</span><input type="number" name="amin" value="<?= e($_GET['amin']??'') ?>" placeholder="0"></label>
            <label class="fld"><span class="muted small">Amount max ₹</span><input type="number" name="amax" value="<?= e($_GET['amax']??'') ?>" placeholder="—"></label>
            <label class="fld"><span class="muted small">Age (din se purana)</span><input type="number" name="age" value="<?= e($_GET['age']??'') ?>" placeholder="e.g. 60"></label>
            <label class="fld"><span class="muted small">Region</span><input type="text" name="region" value="<?= e($_GET['region']??'') ?>" placeholder="e.g. Jaipur"></label>
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
                    <?php foreach (['accept_date'=>'Accept date','claim_amt'=>'Claimed','approved_amt'=>'Approved','patient_name'=>'Patient','status'=>'Stage','claim_id'=>'Claim ID'] as $sk=>$sl): ?>
                        <option value="<?= $sk ?>" <?= $sort===$sk?'selected':'' ?>><?= e($sl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </details>
</form>
<p class="muted small" style="margin:0 0 12px">💡 <strong>Tip:</strong> kai shabd likho (sab match honge) · <code>card:12345</code> <code>doctor:sharma</code> <code>status:settled</code> <code>region:jaipur</code> <code>amt&gt;5000</code> <code>age&gt;60</code> · <code>"exact phrase"</code></p>

<?php if (!empty($_GET['doctor'])): ?>
    <p class="muted small">Doctor filter: <strong><?= e($_GET['doctor']) ?></strong> · <a class="link" href="<?= qs(['doctor'=>null]) ?>">hataayein</a></p>
<?php endif; ?>

<?php $curUrl = qs([]); $docList = echs_doctor_list(); ?>
<datalist id="docs"><?php foreach ($docList as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>

<form method="post" id="bulkForm">
<?= csrf_field() ?>
<input type="hidden" name="act" id="bulkAct" value="">
<input type="hidden" name="return" value="<?= e($curUrl) ?>">

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <div class="muted small">
            <?= number_format($totCount) ?> claims · Claimed <?= money($agg['claim']) ?> · Approved <?= money($agg['appr']) ?>
        </div>
        <div id="bulkBar" class="muted small" style="display:none;align-items:center;gap:8px;flex-wrap:wrap">
            <strong><span id="selCount">0</span> selected:</strong>
            <input list="docs" id="bulkDoc" placeholder="Doctor naam" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px">
            <button type="button" class="btn btn-light" onclick="bulk('setdoctor')">Set doctor</button>
            <select id="bulkAssignee" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px">
                <option value="">— assign to —</option>
                <?php foreach (echs_staff_list() as $sf): ?><option value="<?= e($sf) ?>"><?= e($sf) ?></option><?php endforeach; ?>
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
            <th><?= sortLink('claim_id','Claim ID') ?></th>
            <th><?= sortLink('patient_name','Patient / ESM') ?></th>
            <th>Type</th>
            <th><?= sortLink('status','Status') ?></th>
            <th>Doctor</th>
            <th><?= sortLink('accept_date','Accept') ?></th>
            <th class="r"><?= sortLink('claim_amt','Claimed') ?></th>
            <th class="r"><?= sortLink('approved_amt','Approved') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">Kuch nahi mila.</td></tr>
        <?php else: foreach ($rows as $c): $openUrl = BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($c['claim_id']); ?>
            <tr class="clk" data-href="<?= e($openUrl) ?>" style="cursor:pointer">
                <td><input type="checkbox" class="rowchk" name="ids[]" value="<?= e($c['claim_id']) ?>" onclick="updSel()"></td>
                <td><a class="link" style="color:var(--brand);text-decoration:underline" href="<?= e($openUrl) ?>"><?= e($c['claim_id']) ?></a></td>
                <td><?= e($c['patient_name'] ?: '-') ?><div class="muted small"><?= e($c['esm_name']) ?> · <?= e($c['card_id']) ?></div></td>
                <td class="small"><?= e(echs_ptype($c['patient_type'])) ?><?= strtoupper((string)$c['admit_type'])==='E'?' <span class="pill pill-warn">E</span>':'' ?></td>
                <td><span class="pill pill-<?= ecat_pill($c['category']) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                <td class="small"><?= e($c['doctor_name'] ?: '-') ?></td>
                <td class="small"><?= $c['accept_date'] ? e(date('d-m-y', strtotime($c['accept_date']))) : '-' ?></td>
                <td class="r"><?= inr($c['claim_amt'],0) ?></td>
                <td class="r"><?= $c['approved_amt']>0 ? inr($c['approved_amt'],0) : '<span class="muted">-</span>' ?></td>
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
    // other text boxes (region) auto-submit on blur/Enter — but NOT the live q box
    f.querySelectorAll('input[type=text]').forEach(function(el){ if (el.name==='q') return; el.addEventListener('change', function(){ f.submit(); }); });
    // live search: type karte hi (thoda rukte hi) result — min 2 chars
    var qbox = f.querySelector('input[name=q]');
    if (qbox){
        var t;
        qbox.addEventListener('input', function(){
            clearTimeout(t);
            var v = qbox.value.trim();
            if (v.length === 1) return;               // 1 char par mat chalo
            t = setTimeout(function(){ f.submit(); }, 600);
        });
        qbox.addEventListener('change', function(){ clearTimeout(t); f.submit(); });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
