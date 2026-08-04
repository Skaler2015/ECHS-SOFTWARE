<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'claims';

$id = trim($_GET['id'] ?? ($_POST['claim_id'] ?? ''));

$UPLOAD_DIR = __DIR__ . '/uploads/echs';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = trim($_POST['claim_id'] ?? '');
    $act = $_POST['act'] ?? 'notes';

    if ($act === 'notes') {
        db()->prepare("UPDATE echs_claims SET notes=?, followup=?, doctor_name=? WHERE claim_id=?")
            ->execute([trim($_POST['notes'] ?? ''), isset($_POST['followup'])?1:0, trim($_POST['doctor_name'] ?? ''), $cid]);
        flash('Save ho gaya.');
    } elseif ($act === 'contact') {
        db()->prepare("INSERT INTO echs_contacts (card_id,name,phone,address,updated_at) VALUES (?,?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE name=VALUES(name),phone=VALUES(phone),address=VALUES(address),updated_at=NOW()")
            ->execute([trim($_POST['card_id_val']??''), trim($_POST['cname']??''), trim($_POST['cphone']??''), trim($_POST['caddr']??'')]);
        flash('Contact save ho gaya.');
    } elseif ($act === 'payment') {
        db()->prepare("INSERT INTO echs_payments (claim_id,amount,pay_date,utr,mode,remarks) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, (float)($_POST['amount']??0), ($_POST['pay_date']??'')?:null, trim($_POST['utr']??'')?:null, trim($_POST['mode']??'')?:null, trim($_POST['remarks']??'')?:null]);
        flash('Payment record ho gaya.');
    } elseif ($act === 'task') {
        db()->prepare("INSERT INTO echs_tasks (claim_id,title,due_date) VALUES (?,?,?)")
            ->execute([$cid, trim($_POST['title']??''), ($_POST['due_date']??'')?:null]);
        flash('Task add ho gaya.');
    } elseif ($act === 'task_done') {
        db()->prepare("UPDATE echs_tasks SET done=1,done_at=NOW() WHERE id=?")->execute([(int)$_POST['tid']]);
    } elseif ($act === 'doc') {
        if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0755, true);
        if (!empty($_FILES['docfile']['name']) && $_FILES['docfile']['error'] === UPLOAD_ERR_OK) {
            $orig = $_FILES['docfile']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png','gif','webp','xls','xlsx','doc','docx'];
            if (in_array($ext, $allowed)) {
                $stored = 'd' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['docfile']['tmp_name'], $UPLOAD_DIR . '/' . $stored)) {
                    db()->prepare("INSERT INTO echs_docs (claim_id,stored_name,orig_name,size) VALUES (?,?,?,?)")
                        ->execute([$cid, $stored, $orig, (int)$_FILES['docfile']['size']]);
                    flash('Document upload ho gaya.');
                }
            } else {
                flash('Yeh file type allowed nahi (pdf/jpg/png/xls/doc).', 'error');
            }
        }
    } elseif ($act === 'doc_del') {
        $d = db()->prepare("SELECT stored_name FROM echs_docs WHERE id=? AND claim_id=?");
        $d->execute([(int)$_POST['did'], $cid]);
        if ($row = $d->fetch()) { @unlink($UPLOAD_DIR . '/' . $row['stored_name']); }
        db()->prepare("DELETE FROM echs_docs WHERE id=?")->execute([(int)$_POST['did']]);
        flash('Document delete ho gaya.');
    }
    redirect(BASE_URL . '/echs_claim.php?scheme=ECHS&id=' . urlencode($cid));
}

$q = db()->prepare("SELECT * FROM echs_claims WHERE claim_id=?");
$q->execute([$id]);
$c = $q->fetch();
if (!$c) { flash('Claim nahi mila.', 'error'); redirect(BASE_URL . '/echs_claims.php?scheme=ECHS'); }

$hist = db()->prepare("SELECT * FROM echs_claim_history WHERE claim_id=? ORDER BY id DESC");
$hist->execute([$id]); $hist = $hist->fetchAll();

$pays = db()->prepare("SELECT * FROM echs_payments WHERE claim_id=? ORDER BY id DESC");
$pays->execute([$id]); $pays = $pays->fetchAll();
$paySum = array_sum(array_column($pays, 'amount'));

$docs = db()->prepare("SELECT * FROM echs_docs WHERE claim_id=? ORDER BY id DESC");
$docs->execute([$id]); $docs = $docs->fetchAll();

$tasks = db()->prepare("SELECT * FROM echs_tasks WHERE claim_id=? ORDER BY done, (due_date IS NULL), due_date");
$tasks->execute([$id]); $tasks = $tasks->fetchAll();

$contact = null;
if ($c['card_id']) {
    $ct = db()->prepare("SELECT * FROM echs_contacts WHERE card_id=?");
    $ct->execute([$c['card_id']]); $contact = $ct->fetch();
}

$others = [];
if ($c['card_id']) {
    $o = db()->prepare("SELECT claim_id, patient_name, status, net_claim_amt, accept_date_raw FROM echs_claims WHERE card_id=? AND claim_id<>? ORDER BY accept_date DESC LIMIT 20");
    $o->execute([$c['card_id'], $id]); $others = $o->fetchAll();
}

$page_title = 'Claim ' . $c['claim_id'];
$cat = echs_category($c['status']);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Claim #<?= e($c['claim_id']) ?></h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_claim_edit.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>">✏️ Edit</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">← All Claims</a>
    </div>
</div>

<?php if (!empty($c['nmi_remarks'])): ?>
<div class="nmi-box">
    <div class="muted small">📝 Portal Query (Need More Information)<?= !empty($c['nmi_date'])?' · '.fdate($c['nmi_date']):'' ?></div>
    <div class="nmi-q"><?= e($c['nmi_remarks']) ?></div>
</div>
<?php endif; ?>

<div class="detail-grid">
    <div class="card">
        <h2>Claim Details</h2>
        <table class="kv">
            <tr><td>Status</td><th><span class="pill pill-<?= $cat ?>"><?= e($c['status']) ?></span></th></tr>
            <tr><td>Card ID</td><th><?= e($c['card_id']) ?></th></tr>
            <tr><td>Name of ESM</td><th><?= e($c['esm_name']) ?></th></tr>
            <tr><td>Patient</td><th><?= e($c['patient_name']) ?></th></tr>
            <tr><td>Type</td><th><?= e($c['patient_type']) ?> (<?= $c['patient_type']==='I'?'IPD':'OPD' ?>) / <?= e($c['admit_type']) ?></th></tr>
            <tr><td>Hospital</td><th><?= e($c['hospital_name']) ?></th></tr>
            <tr><td>Doctor</td><th><?= e($c['doctor_name'] ?: '-') ?></th></tr>
            <tr><td>Accept Date</td><th><?= e($c['accept_date_raw'] ?: '-') ?></th></tr>
            <tr><td>Processed On</td><th><?= e($c['processed_on_raw'] ?: '-') ?></th></tr>
            <tr><td>Net Claim Amt</td><th><?= money($c['net_claim_amt']) ?></th></tr>
            <tr><td>Approved Amt</td><th><?= money($c['approved_amt']) ?></th></tr>
            <tr><td>Deduction</td><th><?= money($c['net_claim_amt'] - $c['approved_amt']) ?></th></tr>
            <tr><td>Received (manual)</td><th><?= money($paySum) ?></th></tr>
        </table>
        <?php if (!empty($c['settlement_id']) || (float)($c['amt_credited'] ?? 0) > 0): ?>
        <h2 style="margin-top:16px">💳 Settlement Details</h2>
        <table class="kv">
            <tr><td>Settlement ID</td><th><?= e($c['settlement_id'] ?: '-') ?></th></tr>
            <tr><td>Settlement Date</td><th><?= fdate($c['settle_date']) ?></th></tr>
            <tr><td>ECHS Discount</td><th><?= money($c['echs_disc']) ?></th></tr>
            <tr><td>TDS Amount</td><th><?= money($c['tds_amt']) ?></th></tr>
            <tr><td>BPA Fees</td><th><?= money($c['bpa_fees']) ?></th></tr>
            <tr><td>Recovery</td><th><?= money($c['recovery_amt']) ?></th></tr>
            <tr><td><strong>Amt Credited</strong></td><th><strong style="color:#16A34A"><?= money($c['amt_credited']) ?></strong></th></tr>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>🩺 Doctor &amp; Notes</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="act" value="notes"><input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>">
            <label>Doctor ka naam</label>
            <input name="doctor_name" list="doclist" value="<?= e($c['doctor_name'] ?? '') ?>" placeholder="Dr. ka naam likhein" autocomplete="off" style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;margin-bottom:10px">
            <datalist id="doclist"><?php foreach (echs_doctor_list() as $dn): ?><option value="<?= e($dn) ?>"></option><?php endforeach; ?></datalist>
            <label class="chk"><input type="checkbox" name="followup" value="1" <?= $c['followup']?'checked':'' ?>> Follow-up chahiye 🚩</label>
            <textarea name="notes" rows="4" class="notes-area" placeholder="Notes..."><?= e($c['notes']) ?></textarea>
            <div class="form-actions"><button class="btn btn-primary">Save</button></div>
        </form>

        <h2 style="margin-top:18px">📞 Contact (Card <?= e($c['card_id']) ?>)</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="act" value="contact"><input type="hidden" name="card_id_val" value="<?= e($c['card_id']) ?>">
            <div class="grid2">
                <div class="fld"><label>Naam</label><input name="cname" value="<?= e($contact['name'] ?? $c['esm_name']) ?>"></div>
                <div class="fld"><label>Phone</label><input name="cphone" value="<?= e($contact['phone'] ?? '') ?>"></div>
                <div class="fld col2"><label>Address</label><input name="caddr" value="<?= e($contact['address'] ?? '') ?>"></div>
            </div>
            <div class="form-actions"><button class="btn">Save Contact</button></div>
        </form>
    </div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>💵 Payments (<?= money($paySum) ?>)</h2>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="act" value="payment"><input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>">
            <div class="grid3">
                <div class="fld"><label>Amount</label><input type="number" step="0.01" name="amount" required></div>
                <div class="fld"><label>Date</label><input type="date" name="pay_date" value="<?= date('Y-m-d') ?>"></div>
                <div class="fld"><label>UTR</label><input name="utr"></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary">+ Add Payment</button></div>
        </form>
        <?php if ($pays): ?>
        <table class="tbl" style="margin-top:10px">
            <thead><tr><th>Date</th><th class="r">Amount</th><th>UTR</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pays as $p): ?>
                <tr><td><?= fdate($p['pay_date']) ?></td><td class="r"><?= money($p['amount']) ?></td><td><?= e($p['utr'] ?: '-') ?></td>
                <td class="r"><a class="link" href="<?= BASE_URL ?>/echs_payments.php?scheme=ECHS">manage</a></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>📎 Documents</h2>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="act" value="doc"><input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>">
            <input type="file" name="docfile" accept=".pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.doc,.docx" required>
            <div class="form-actions"><button class="btn btn-primary">⬆ Upload</button></div>
        </form>
        <?php if ($docs): ?>
        <ul class="doclist">
        <?php foreach ($docs as $d): ?>
            <li>
                <a href="<?= BASE_URL ?>/api/echs_doc.php?id=<?= $d['id'] ?>" target="_blank">📄 <?= e($d['orig_name']) ?></a>
                <span class="muted small">(<?= round($d['size']/1024) ?> KB)</span>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?><input type="hidden" name="act" value="doc_del"><input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>"><input type="hidden" name="did" value="<?= $d['id'] ?>"><button class="btn-x">✕</button></form>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="muted small">Koi document nahi. Bill/approval letter scan karके upload karein.</p><?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>✅ Is claim ke Tasks</h2>
    <form method="post" class="inline-add">
        <?= csrf_field() ?><input type="hidden" name="act" value="task"><input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>">
        <input name="title" placeholder="Naya task..." required style="flex:1;padding:8px;border:1px solid var(--line);border-radius:8px">
        <input type="date" name="due_date" style="padding:8px;border:1px solid var(--line);border-radius:8px">
        <button class="btn btn-primary">+ Add</button>
    </form>
    <?php if ($tasks): ?>
    <table class="tbl" style="margin-top:10px">
        <tbody>
        <?php foreach ($tasks as $t): ?>
            <tr class="<?= $t['done']?'muted':'' ?>">
                <td style="width:30px"><?php if(!$t['done']):?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="task_done"><input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>"><input type="hidden" name="tid" value="<?= $t['id'] ?>"><button class="btn-x" style="background:#dcfce7;color:#166534">✓</button></form><?php else:?>✓<?php endif;?></td>
                <td style="<?= $t['done']?'text-decoration:line-through':'' ?>"><?= e($t['title']) ?></td>
                <td class="nowrap"><?= $t['due_date']?fdate($t['due_date']):'' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>🕒 Status History</h2>
    <?php if (!$hist): ?><p class="muted">Abhi tak koi status-change record nahi.</p><?php else: ?>
    <ul class="timeline">
    <?php foreach ($hist as $x): ?>
        <li><span class="tl-dot"></span><div class="tl-body"><strong><?= e($x['to_status']) ?></strong>
        <?php if ($x['from_status']): ?><span class="muted"> ← <?= e($x['from_status']) ?></span><?php endif; ?>
        <div class="muted small"><?= fdate($x['changed_at']) ?> · <?= e(date('H:i', strtotime($x['changed_at']))) ?></div></div></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if ($others): ?>
<div class="card">
    <h2>Isi Card ke doosre claims</h2>
    <table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Accept</th><th class="r">Net</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($others as $o): ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($o['claim_id']) ?>"><?= e($o['claim_id']) ?></a></td>
                <td><?= e($o['patient_name']) ?></td><td><?= e($o['accept_date_raw'] ?: '-') ?></td>
                <td class="r"><?= inr($o['net_claim_amt'],0) ?></td>
                <td><span class="pill pill-<?= echs_category($o['status']) ?>"><?= e($o['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
