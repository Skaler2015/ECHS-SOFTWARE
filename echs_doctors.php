<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'doctors';
$page_title = 'ECHS Doctors';
$pdo = db();

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $ret = $_POST['return'] ?? (BASE_URL.'/echs_doctors.php?scheme=ECHS');

    // ---- A) Doctor master --------------------------------------------------
    if ($act === 'add_doctor') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            try {
                $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)")->execute([$name]);
                echs_log('doctor_add', $name);
                flash("Doctor '$name' add ho gaya.");
            } catch (Exception $e) { flash('Error: '.$e->getMessage(), 'error'); }
        }
        redirect($ret);
    }
    elseif ($act === 'rename_doctor') {
        $old = trim($_POST['old_name'] ?? '');
        $new = trim($_POST['new_name'] ?? '');
        if ($old !== '' && $new !== '' && $old !== $new) {
            $pdo->beginTransaction();
            // rename master (fall back to delete+insert if UNIQUE collides)
            try { $pdo->prepare("UPDATE echs_doctors SET name=? WHERE name=?")->execute([$new, $old]); }
            catch (Exception $e) { $pdo->prepare("DELETE FROM echs_doctors WHERE name=?")->execute([$old]); }
            $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)")->execute([$new]);
            // move every claim off the old name onto the new one
            $u = $pdo->prepare("UPDATE echs_claims SET doctor_name=? WHERE doctor_name=?");
            $u->execute([$new, $old]); $cnt = $u->rowCount();
            $pdo->commit();
            echs_log('doctor_rename', "$old -> $new ($cnt)");
            flash("'$old' -> '$new' ho gaya ($cnt claims update).");
        }
        redirect($ret);
    }
    elseif ($act === 'del_doctor') {
        // master only — claims keep their doctor_name
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare("DELETE FROM echs_doctors WHERE name=?")->execute([$name]);
            echs_log('doctor_del', $name);
            flash("Doctor '$name' master list se hata diya (claims untouched).");
        }
        redirect($ret);
    }

    // ---- B) Bulk assign selected claims -----------------------------------
    elseif ($act === 'bulk_assign') {
        $doc = trim($_POST['doctor_name'] ?? '');
        $ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'strlen'));
        if ($doc === '') { flash('Pehle doctor select karein.', 'error'); redirect($ret); }
        if (!$ids)       { flash('Koi claim select nahi hua.', 'error'); redirect($ret); }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("UPDATE echs_claims SET doctor_name=?, doctor_manual=1, updated_at=NOW() WHERE claim_id IN ($in)");
        $st->execute(array_merge([$doc], $ids));
        $n = $st->rowCount();
        try { $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)")->execute([$doc]); } catch (Exception $e) {}
        echs_log('bulk_assign', "$n claims -> $doc");
        flash("$n claims '$doc' ko assign ho gaye.");
        redirect($ret);
    }

    // ---- C) Rule-based auto-assign ----------------------------------------
    elseif ($act === 'auto_assign') {
        $doc  = trim($_POST['doctor_name'] ?? '');
        $rule = $_POST['rule'] ?? '';
        $ruleCond = [
            'opd' => "patient_type='O'",
            'ipd' => "patient_type='I'",
            'emg' => "admit_type='E'",
            'all' => "1=1",
        ];
        if ($doc === '') { flash('Pehle doctor select karein.', 'error'); redirect($ret); }
        if (!isset($ruleCond[$rule])) { flash('Rule galat hai.', 'error'); redirect($ret); }
        // only ever touch currently-unassigned claims
        $sql = "UPDATE echs_claims SET doctor_name=?, doctor_manual=1, updated_at=NOW()
                WHERE (doctor_name IS NULL OR doctor_name='') AND (".$ruleCond[$rule].")";
        $st = $pdo->prepare($sql);
        $st->execute([$doc]);
        $n = $st->rowCount();
        try { $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)")->execute([$doc]); } catch (Exception $e) {}
        echs_log('auto_assign', "rule=$rule -> $doc ($n claims)");
        flash("Auto-assign: $n unassigned claims '$doc' ko de diye (rule: $rule).");
        redirect($ret);
    }

    redirect($ret);
}

// ---------------------------------------------------------------------------
// A) Doctor master data + workload counts
// ---------------------------------------------------------------------------
$stats = [];   // doctor_name => count of claims
foreach ($pdo->query("SELECT doctor_name, COUNT(*) n FROM echs_claims
        WHERE doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name") as $r) {
    $stats[$r['doctor_name']] = (int)$r['n'];
}
$masters = [];
foreach ($pdo->query("SELECT name FROM echs_doctors ORDER BY name") as $r) $masters[$r['name']] = true;
$names = array_values(array_unique(array_merge(array_keys($masters), array_keys($stats))));
sort($names);

$unassigned = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE doctor_name IS NULL OR doctor_name=''")->fetch()['n'];
$assigned   = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims WHERE doctor_name IS NOT NULL AND doctor_name<>''")->fetch()['n'];
$docList    = echs_doctor_list();

// ---------------------------------------------------------------------------
// C) Auto-assign preview counts.
// Every rule is scoped to currently-UNASSIGNED claims only. So we take the
// base pool (doctor_name IS NULL OR ''), and count how many of those match
// each rule's WHERE clause. A single grouped query does all four:
//   opd = unassigned & patient_type='O'
//   ipd = unassigned & patient_type='I'
//   emg = unassigned & admit_type='E'
//   all = every unassigned claim (the "remaining" catch-all)
// These are exactly the WHERE conditions the auto_assign UPDATE will use,
// so the preview number == the number that will actually be updated.
// ---------------------------------------------------------------------------
$pc = $pdo->query("SELECT
        COALESCE(SUM(patient_type='O'),0) opd,
        COALESCE(SUM(patient_type='I'),0) ipd,
        COALESCE(SUM(admit_type='E'),0)   emg,
        COUNT(*) allc
    FROM echs_claims WHERE (doctor_name IS NULL OR doctor_name='')")->fetch();
$prev = [
    'opd' => (int)$pc['opd'],
    'ipd' => (int)$pc['ipd'],
    'emg' => (int)$pc['emg'],
    'all' => (int)$pc['allc'],
];

// ---------------------------------------------------------------------------
// B) Bulk-assign claim list (filtered + paginated, 50/page)
// ---------------------------------------------------------------------------
$f_type   = trim($_GET['f_type'] ?? '');
$f_cat    = trim($_GET['f_cat'] ?? '');
$f_status = trim($_GET['f_status'] ?? '');
$f_region = trim($_GET['f_region'] ?? '');
$f_from   = trim($_GET['f_from'] ?? '');
$f_to     = trim($_GET['f_to'] ?? '');
$f_nodoc  = !empty($_GET['f_nodoc']);

$bw = []; $ba = [];
if ($f_type   !== '' && in_array($f_type, ['O','I'], true)) { $bw[] = 'patient_type = ?'; $ba[] = $f_type; }
if (in_array($f_cat, ['settled','inprocess','query','rejected','cancelled','pending'], true)) { $bw[] = 'category = ?'; $ba[] = $f_cat; }
if ($f_status !== '') { $bw[] = 'status = ?'; $ba[] = $f_status; }
if ($f_region !== '') { $bw[] = 'region = ?'; $ba[] = $f_region; }
if ($f_from   !== '') { $bw[] = 'accept_date >= ?'; $ba[] = $f_from; }
if ($f_to     !== '') { $bw[] = 'accept_date <= ?'; $ba[] = $f_to; }
if ($f_nodoc)         { $bw[] = "(doctor_name IS NULL OR doctor_name='')"; }
$bwhere = $bw ? ('WHERE '.implode(' AND ', $bw)) : '';

$bper = 50;
$bpage = max(1, (int)($_GET['bpage'] ?? 1));
$boff  = ($bpage-1)*$bper;

$bcst = $pdo->prepare("SELECT COUNT(*) n FROM echs_claims $bwhere");
$bcst->execute($ba);
$bTotal = (int)$bcst->fetch()['n'];
$bPages = max(1, (int)ceil($bTotal / $bper));

$bsql = "SELECT claim_id, patient_name, esm_name, card_id, patient_type, admit_type,
                status, category, accept_date, claim_amt, doctor_name
         FROM echs_claims $bwhere
         ORDER BY COALESCE(accept_date,'1900-01-01') DESC, claim_id DESC
         LIMIT $bper OFFSET $boff";
$bst = $pdo->prepare($bsql); $bst->execute($ba);
$bRows = $bst->fetchAll();

// region + status option lists for the filter selects
$regions = [];
foreach ($pdo->query("SELECT DISTINCT region FROM echs_claims WHERE region IS NOT NULL AND region<>'' ORDER BY region") as $r) $regions[] = $r['region'];
$statusList = echs_status_list();

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
// build a URL to this page preserving current filters (for pagination)
function dqs($over){
    $q = array_merge($_GET, $over); $q['scheme'] = 'ECHS';
    return BASE_URL.'/echs_doctors.php?'.http_build_query(array_filter($q, function($v){ return $v!==null && $v!==''; }));
}
$curUrl = dqs([]);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🩺 ECHS Doctors &amp; Assignment</h1>
    <div class="page-actions"><a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All Claims</a></div>
</div>

<div class="card form">
    <h2>🔎 Claim ka doctor(s) set karein</h2>
    <p class="muted small">Claim ID likhiye — us claim ka doctor set/edit karne ke liye. Ek claim par <strong>kai doctor + alag-alag amount</strong> daal sakte hain (unka total bill ke barabar hona chahiye).</p>
    <form onsubmit="var v=document.getElementById('goClaim').value.trim(); if(v){location.href='<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id='+encodeURIComponent(v)+'#doctors';} return false;"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input id="goClaim" placeholder="Claim ID (e.g. 22613845)" style="flex:1;min-width:200px;padding:9px 11px;border:1px solid var(--line);border-radius:9px">
        <button class="btn btn-primary">Doctor set karein →</button>
    </form>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format(count($names)) ?></div><div class="stat-lbl">Total Doctors</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= number_format($assigned) ?></div><div class="stat-lbl">Assigned claims</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($unassigned) ?></div><div class="stat-lbl">Bina doctor ke claims</div></div>
</div>

<!-- ================= C) RULE-BASED AUTO-ASSIGN (top: customer's #1 need) ==== -->
<div class="card form">
    <h2>⚡ Auto-Assign (rule based)</h2>
    <p class="muted small">Har rule sirf <strong>abhi tak un-assigned</strong> (<?= number_format($unassigned) ?>) claims par lagta hai. Neeche count = kitne claims is rule se assign honge.</p>
    <form method="post" onsubmit="return confirm('Yeh rule un-assigned claims par apply karega. Continue?');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="auto_assign">
        <input type="hidden" name="return" value="<?= e($curUrl) ?>">
        <div class="grid3">
            <div class="fld">
                <label>Rule chunein</label>
                <select name="rule" required>
                    <option value="opd">All OPD &rarr; doctor (<?= number_format($prev['opd']) ?> claims)</option>
                    <option value="ipd">All IPD &rarr; doctor (<?= number_format($prev['ipd']) ?> claims)</option>
                    <option value="emg">All Emergency &rarr; doctor (<?= number_format($prev['emg']) ?> claims)</option>
                    <option value="all">All remaining un-assigned &rarr; doctor (<?= number_format($prev['all']) ?> claims)</option>
                </select>
            </div>
            <div class="fld">
                <label>Doctor *</label>
                <input list="echsDocs" name="doctor_name" required placeholder="Dr. ...">
            </div>
            <div class="fld" style="align-self:end">
                <button class="btn btn-primary" type="submit">Apply rule</button>
            </div>
        </div>
    </form>
    <table class="tbl" style="margin-top:6px">
        <thead><tr><th>Rule</th><th class="r">Un-assigned claims affected</th></tr></thead>
        <tbody>
            <tr><td>All OPD</td><td class="r"><?= number_format($prev['opd']) ?></td></tr>
            <tr><td>All IPD</td><td class="r"><?= number_format($prev['ipd']) ?></td></tr>
            <tr><td>All Emergency (admit_type = E)</td><td class="r"><?= number_format($prev['emg']) ?></td></tr>
            <tr><td>All remaining un-assigned</td><td class="r"><?= number_format($prev['all']) ?></td></tr>
        </tbody>
    </table>
</div>

<!-- ================= A) DOCTOR MASTER ===================================== -->
<div class="card form">
    <h2>➕ Naya Doctor</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="add_doctor">
        <input type="hidden" name="return" value="<?= e($curUrl) ?>">
        <div class="grid3">
            <div class="fld"><label>Doctor ka naam *</label><input name="name" required placeholder="Dr. ..."></div>
            <div class="fld" style="align-self:end"><button class="btn btn-primary">+ Add Doctor</button></div>
        </div>
    </form>
    <p class="muted small">💡 Rename karoge to us doctor ke saare claims me naam badal jayega. Delete sirf master list se hata (claims safe).</p>
</div>

<div class="card">
    <h2>Doctors List</h2>
    <?php if (!$names): ?><p class="muted">Abhi koi doctor nahi.</p><?php else: ?>
    <div class="tbl-scroll">
    <table class="tbl">
        <thead><tr><th>Doctor</th><th class="r">Assigned claims</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($names as $nm): $n = $stats[$nm] ?? 0; ?>
            <tr>
                <td><strong><?= e($nm) ?></strong></td>
                <td class="r">
                    <?php if ($n>0): ?>
                        <a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&doctor=<?= urlencode($nm) ?>"><?= number_format($n) ?></a>
                    <?php else: ?><span class="muted">0</span><?php endif; ?>
                </td>
                <td class="r nowrap">
                    <a class="link" href="#" onclick="renameDoc('<?= e(addslashes($nm)) ?>');return false;">Rename</a>
                    <a class="link danger" href="#" onclick="delDoc('<?= e(addslashes($nm)) ?>',<?= (int)$n ?>);return false;">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<datalist id="echsDocs"><?php foreach ($docList as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>

<form method="post" id="renameForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="act" value="rename_doctor"><input type="hidden" name="return" value="<?= e($curUrl) ?>"><input type="hidden" name="old_name" id="rn_old"><input type="hidden" name="new_name" id="rn_new"></form>
<form method="post" id="delForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="act" value="del_doctor"><input type="hidden" name="return" value="<?= e($curUrl) ?>"><input type="hidden" name="name" id="dl_name"></form>

<script>
function selected(){ return Array.prototype.slice.call(document.querySelectorAll('.rowchk:checked')); }
function toggleAll(cb){ document.querySelectorAll('.rowchk').forEach(function(c){ c.checked = cb.checked; }); }
function confirmAssign(){
    if (!selected().length){ alert('Pehle kuch claims select karein (checkbox).'); return false; }
    return confirm(selected().length + ' claims assign karein?');
}
function renameDoc(old){
    var nn = prompt("Naya naam (is doctor ke sab claims me badlega):", old);
    if (nn && nn.trim() && nn.trim() !== old){
        document.getElementById('rn_old').value = old;
        document.getElementById('rn_new').value = nn.trim();
        document.getElementById('renameForm').submit();
    }
}
function delDoc(name, n){
    var msg = n>0 ? ("'"+name+"' ke "+n+" claims hain. Master list se hataayen? (claims ka doctor naam waisa hi rahega)") : ("'"+name+"' hataayen?");
    if (!confirm(msg)) return;
    document.getElementById('dl_name').value = name;
    document.getElementById('delForm').submit();
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
