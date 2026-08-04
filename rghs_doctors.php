<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'doctors';
$page_title = 'RGHS Doctors';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            try {
                $pdo->prepare("INSERT INTO rghs_doctors (name,specialty,phone) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE specialty=VALUES(specialty), phone=VALUES(phone)")
                    ->execute([$name, trim($_POST['specialty'] ?? '') ?: null, trim($_POST['phone'] ?? '') ?: null]);
                flash('Doctor add ho gaya.');
            } catch (Exception $e) { flash('Error: '.$e->getMessage(),'error'); }
        }
    } elseif ($act === 'rename') {
        $old = trim($_POST['old_name'] ?? ''); $new = trim($_POST['new_name'] ?? '');
        if ($old !== '' && $new !== '') {
            $pdo->beginTransaction();
            $n = $pdo->prepare("UPDATE rghs_claims SET doctor_name=? WHERE doctor_name=?"); $n->execute([$new,$old]); $cnt=$n->rowCount();
            try { $pdo->prepare("UPDATE rghs_doctors SET name=? WHERE name=?")->execute([$new,$old]); }
            catch (Exception $e) { $pdo->prepare("DELETE FROM rghs_doctors WHERE name=?")->execute([$old]); }
            $pdo->prepare("INSERT IGNORE INTO rghs_doctors (name) VALUES (?)")->execute([$new]);
            $pdo->commit();
            flash("'$old' → '$new' ho gaya ($cnt claims update).");
        }
    } elseif ($act === 'del') {
        $name = trim($_POST['name'] ?? ''); $clear = !empty($_POST['clear_claims']);
        $pdo->prepare("DELETE FROM rghs_doctors WHERE name=?")->execute([$name]);
        if ($clear) $pdo->prepare("UPDATE rghs_claims SET doctor_name=NULL WHERE doctor_name=?")->execute([$name]);
        flash("Doctor '$name' hata diya".($clear?' (claims se bhi)':'').'.');
    }
    redirect(BASE_URL.'/rghs_doctors.php?scheme=RGHS');
}

$stats = [];
foreach ($pdo->query("SELECT doctor_name name, COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu, COALESCE(SUM(paid_amount),0) paid
    FROM rghs_claims WHERE doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name") as $r) $stats[$r['name']]=$r;
$masters = [];
foreach ($pdo->query("SELECT * FROM rghs_doctors ORDER BY name") as $r) $masters[$r['name']]=$r;
$names = array_unique(array_merge(array_keys($masters), array_keys($stats))); sort($names);
$unassigned = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims WHERE doctor_name IS NULL OR doctor_name=''")->fetch()['n'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🩺 Doctors</h1>
    <div class="page-actions"><a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">All Claims</a></div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format(count($names)) ?></div><div class="stat-lbl">Total Doctors</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= number_format($unassigned) ?></div><div class="stat-lbl">Bina doctor ke claims</div></div>
</div>

<div class="card form">
    <h2>Naya Doctor add karein</h2>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="grid3">
            <div class="fld"><label>Doctor ka naam *</label><input name="name" required placeholder="Dr. ..."></div>
            <div class="fld"><label>Specialty</label><input name="specialty"></div>
            <div class="fld"><label>Phone</label><input name="phone"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">+ Add Doctor</button></div>
    </form>
    <p class="muted small">💡 RGHS sheet me doctor naam already aata hai. Yahan naam badloge (rename) to sab claims me badal jayega.</p>
</div>

<div class="card">
    <h2>Doctors List</h2>
    <?php if (!$names): ?><p class="muted">Abhi koi doctor nahi.</p><?php else: ?>
    <div class="tbl-scroll">
    <table class="tbl">
        <thead><tr><th>Doctor</th><th>Specialty</th><th class="r">Claims</th><th class="r">Claimed</th><th class="r">Approved</th><th class="r">Paid</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($names as $nm): $s=$stats[$nm]??['n'=>0,'claim'=>0,'cu'=>0,'paid'=>0]; $m=$masters[$nm]??null; ?>
            <tr>
                <td><strong><?= e($nm) ?></strong></td>
                <td><?= e($m['specialty'] ?? '-') ?></td>
                <td class="r"><?= number_format($s['n']) ?></td>
                <td class="r"><?= inr($s['claim'],0) ?></td>
                <td class="r"><?= inr($s['cu'],0) ?></td>
                <td class="r"><?= inr($s['paid'],0) ?></td>
                <td class="r nowrap">
                    <?php if ($s['n']>0): ?><a class="link" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&doctor=<?= urlencode($nm) ?>">View</a><?php endif; ?>
                    <a class="link" href="#" onclick="renameDoc('<?= e(addslashes($nm)) ?>');return false;">Rename</a>
                    <a class="link danger" href="#" onclick="delDoc('<?= e(addslashes($nm)) ?>',<?= (int)$s['n'] ?>);return false;">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<form method="post" id="renameForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="act" value="rename"><input type="hidden" name="old_name" id="rn_old"><input type="hidden" name="new_name" id="rn_new"></form>
<form method="post" id="delForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="name" id="dl_name"><input type="hidden" name="clear_claims" id="dl_clear" value=""></form>
<script>
function renameDoc(old){ var nn=prompt("Naya naam (sab claims me badlega):",old); if(nn&&nn.trim()&&nn.trim()!==old){ document.getElementById('rn_old').value=old; document.getElementById('rn_new').value=nn.trim(); document.getElementById('renameForm').submit(); } }
function delDoc(name,n){ var msg=n>0?("'"+name+"' ke "+n+" claims hain. Doctor list se hataayen?"):("'"+name+"' hataayen?"); if(!confirm(msg))return; document.getElementById('dl_name').value=name; if(n>0){ document.getElementById('dl_clear').value=confirm("Claims me se bhi doctor naam HATA dein? (OK=haan)")?'1':''; } document.getElementById('delForm').submit(); }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
