<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/icons.php';
store_ensure_table();

$scheme = 'STORE';
$meta   = scheme_meta('STORE');
$active = 'claims';
$pdo = db();

$inv = trim($_GET['inv'] ?? '');
$st = $pdo->prepare("SELECT * FROM store_claims WHERE invoice_no = ?");
$st->execute([$inv]); $c = $st->fetch();

if (!$c) {
    $page_title = 'Invoice not found';
    if (is_panel()) panel_head($page_title); else require __DIR__ . '/includes/header.php';
    echo '<div class="card"><p class="muted">Invoice ('.e($inv).') nahi mila.</p><p><a class="btn" href="'.BASE_URL.'/store_claims.php?scheme=STORE">← Invoices</a></p></div>';
    if (is_panel()) panel_foot(); else require __DIR__ . '/includes/footer.php';
    return;
}
$page_title = 'Invoice ' . $c['invoice_no'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $note = trim($_POST['notes'] ?? ''); $assignee = trim($_POST['assigned_to'] ?? ''); $fu = !empty($_POST['followup']) ? 1 : 0;
        $pdo->prepare("UPDATE store_claims SET notes=?, followup=?, assigned_to=?, updated_at=NOW() WHERE invoice_no=?")->execute([$note ?: null, $fu, $assignee ?: null, $inv]);
        flash('Save ho gaya.');
        redirect(BASE_URL.'/store_claim.php?scheme=STORE&inv='.urlencode($inv).(is_panel()?'&panel=1':''));
    } elseif ($act === 'addnote') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') { $who = current_user()['full_name'] ?? 'staff'; $pdo->prepare("INSERT INTO store_notes (invoice_no,note,who) VALUES (?,?,?)")->execute([$inv, $note, $who]); flash('Note add ho gaya.'); }
        redirect(BASE_URL.'/store_claim.php?scheme=STORE&inv='.urlencode($inv).(is_panel()?'&panel=1':''));
    }
}

$hist = $pdo->prepare("SELECT * FROM store_claim_history WHERE invoice_no=? ORDER BY changed_at DESC"); $hist->execute([$inv]); $history = $hist->fetchAll();
$notesList = $pdo->prepare("SELECT * FROM store_notes WHERE invoice_no=? ORDER BY id DESC"); $notesList->execute([$inv]); $notesList = $notesList->fetchAll();
$staff = store_staff_list();

// RGHS claim linked by TID
$rghsClaim = null;
if (!empty($c['tid'])) { try { $r=$pdo->prepare("SELECT tid,status,claim_amt,cu_amt FROM rghs_claims WHERE tid=?"); $r->execute([$c['tid']]); $rghsClaim=$r->fetch()?:null; } catch (Exception $e) {} }

// other invoices of same card
$more = [];
if (!empty($c['card_no'])) { $m=$pdo->prepare("SELECT invoice_no, status, claim_amt, submit_date FROM store_claims WHERE card_no=? AND invoice_no<>? ORDER BY submit_date DESC LIMIT 20"); $m->execute([$c['card_no'], $inv]); $more=$m->fetchAll(); }

$cat = $c['category'] ?: store_category($c['status']);
if (is_panel()) panel_head($page_title); else require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1 style="font-size:1.15rem">Invoice <?= e($c['invoice_no']) ?></h1>
    <div class="page-actions"><a class="btn" href="<?= BASE_URL ?>/store_claims.php?scheme=STORE">← All Invoices</a></div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= inr($c['claim_amt'],0) ?></div><div class="stat-lbl">Pharmacy claimed</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($c['tpa_amt'],0) ?></div><div class="stat-lbl">TPA approved</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($c['cu_amt'],0) ?></div><div class="stat-lbl">CU approved</div></div>
    <div class="stat-card <?= $cat==='rejected'||$cat==='deleted'?'danger':($cat==='approved'?'ok':'warn') ?>"><div class="stat-num" style="font-size:.95rem"><?= e($c['status']) ?></div><div class="stat-lbl">Status</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Patient & invoice</h2>
        <table class="kv">
            <tr><td>Invoice No.</td><th><?= e($c['invoice_no']) ?></th></tr>
            <tr><td>Transaction Id</td><th><?php if($c['tid']): ?><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($c['tid']) ?>"><?= e($c['tid']) ?></a><?php else: ?>-<?php endif; ?></th></tr>
            <tr><td>Patient</td><th><?= e($c['patient_name']) ?> <span class="muted">(<?= e($c['gender']) ?>)</span></th></tr>
            <tr><td>RGHS Card</td><th><?= e($c['card_no']) ?></th></tr>
            <tr><td>Enrollment ID</td><th><?= e($c['enrollment_id']) ?></th></tr>
            <tr><td>Mobile</td><th><?= e($c['mobile']) ?></th></tr>
            <tr><td>Submitted</td><th><?= fdate($c['submit_date']) ?> <span class="muted">(<?= e($c['sub_month']) ?> <?= e($c['sub_year']) ?>)</span></th></tr>
        </table>
    </div>
    <div class="card">
        <h2>Amounts & remarks</h2>
        <table class="kv">
            <tr><td>Pharmacy claim</td><th><?= money($c['claim_amt']) ?></th></tr>
            <tr><td>TPA approved</td><th><?= money($c['tpa_amt']) ?></th></tr>
            <tr><td>CU approved</td><th class="ok"><?= money($c['cu_amt']) ?></th></tr>
            <tr><td>Category</td><th><?= e(ucfirst($cat)) ?></th></tr>
        </table>
        <?php if (!empty($c['cu_remark']) || !empty($c['tpa_remark'])): ?>
            <?php if (!empty($c['cu_remark'])): ?><p class="small" style="margin:8px 0 0"><strong>CU:</strong> <?= nl2br(e($c['cu_remark'])) ?></p><?php endif; ?>
            <?php if (!empty($c['tpa_remark'])): ?><p class="small" style="margin:4px 0 0"><strong>TPA:</strong> <?= nl2br(e($c['tpa_remark'])) ?></p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($rghsClaim): ?>
<div class="card" style="border-left:4px solid var(--brand)">
    <h2>🔗 RGHS claim (linked by TID)</h2>
    <p class="small">Status: <strong><?= e($rghsClaim['status']) ?></strong> · Claimed <?= money($rghsClaim['claim_amt']) ?> · CU approved <?= money($rghsClaim['cu_amt']) ?>
        · <a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($c['tid']) ?>">RGHS claim kholein →</a></p>
</div>
<?php endif; ?>

<div class="detail-grid">
    <div class="card form">
        <h2>Assign · Flag · Note</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
            <div class="fld"><label>Assigned to (staff)</label><select name="assigned_to"><option value="">— koi nahi —</option>
                <?php foreach ($staff as $sf): ?><option value="<?= e($sf) ?>" <?= ($c['assigned_to']??'')===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label>Quick note</label><textarea name="notes" rows="2"><?= e($c['notes']) ?></textarea></div>
            <label class="inline" style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" name="followup" value="1" <?= $c['followup']?'checked':'' ?>> Follow-up flag</label>
            <div class="form-actions"><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
    <div class="card">
        <h2>📝 Notes timeline</h2>
        <form method="post" style="display:flex;gap:8px;margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="act" value="addnote">
            <input name="note" placeholder="Naya note…" required style="flex:1;padding:9px 11px;border:1px solid var(--line);border-radius:9px"><button class="btn btn-primary">Add</button></form>
        <?php if (!$notesList): ?><p class="muted small">Abhi koi note nahi.</p><?php else: ?>
        <ul class="timeline"><?php foreach ($notesList as $nt): ?><li><span class="tl-date"><?= e(date('d-m-y', strtotime($nt['created_at']))) ?></span><span class="small"><?= e($nt['note']) ?> <span class="muted">— <?= e($nt['who']) ?></span></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Status history</h2>
    <?php if (!$history): ?><p class="muted">Koi change record nahi.</p><?php else: ?>
    <ul class="timeline"><?php foreach ($history as $h): ?><li><span class="tl-date"><?= e(date('d-m-y', strtotime($h['changed_at']))) ?></span><span class="small"><?= e($h['from_status'] ?: 'नया') ?> → <strong><?= e($h['to_status']) ?></strong></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>

<?php if ($more): ?>
<div class="card">
    <h2>Isi card ke aur invoices (<?= count($more) ?>)</h2>
    <table class="tbl"><thead><tr><th>Invoice</th><th>Status</th><th>Submitted</th><th class="r">Claimed</th></tr></thead><tbody>
    <?php foreach ($more as $m): ?><tr><td><a class="link" href="<?= BASE_URL ?>/store_claim.php?scheme=STORE&inv=<?= urlencode($m['invoice_no']) ?>"><?= e($m['invoice_no']) ?></a></td><td class="small"><?= e($m['status']) ?></td><td class="small"><?= fdate($m['submit_date']) ?></td><td class="r"><?= inr($m['claim_amt'],0) ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div>
<?php endif; ?>

<?php if (is_panel()) panel_foot(); else require __DIR__ . '/includes/footer.php'; ?>
