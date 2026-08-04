<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'claims';
$pdo = db();

$tid = trim($_GET['tid'] ?? '');
$st = $pdo->prepare("SELECT * FROM rghs_claims WHERE tid = ?");
$st->execute([$tid]);
$c = $st->fetch();

if (!$c) {
    $page_title = 'Claim not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><p class="muted">Claim (TID '.e($tid).') nahi mila.</p><p><a class="btn" href="'.BASE_URL.'/rghs_claims.php?scheme=RGHS">← Claims</a></p></div>';
    require __DIR__ . '/includes/footer.php';
    return;
}
$page_title = 'Claim ' . $c['tid'];

// save note / doctor override
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['act'] ?? '') === 'save') {
        $doc = trim($_POST['doctor_name'] ?? '');
        $note = trim($_POST['notes'] ?? '');
        $fu = !empty($_POST['followup']) ? 1 : 0;
        $manual = ($doc !== '' && $doc !== ($c['doctor_name'] ?? '')) ? 1 : (int)$c['doctor_manual'];
        $pdo->prepare("UPDATE rghs_claims SET doctor_name=?, doctor_manual=?, notes=?, followup=?, updated_at=NOW() WHERE tid=?")
            ->execute([$doc ?: null, $doc!==''?1:$manual, $note ?: null, $fu, $tid]);
        flash('Save ho gaya.');
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid));
    }
}

$hist = $pdo->prepare("SELECT * FROM rghs_claim_history WHERE tid=? ORDER BY changed_at DESC");
$hist->execute([$tid]); $history = $hist->fetchAll();

// other claims of same enrollment/card
$more = [];
if (!empty($c['enrollment_id'])) {
    $m = $pdo->prepare("SELECT tid, status, claim_amt, submit_date FROM rghs_claims WHERE enrollment_id=? AND tid<>? ORDER BY submit_date DESC LIMIT 20");
    $m->execute([$c['enrollment_id'], $tid]); $more = $m->fetchAll();
}

function fdate($d){ return $d ? date('d-m-Y', strtotime($d)) : '-'; }
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Claim <?= e($c['tid']) ?></h1>
    <div class="page-actions"><a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">← All Claims</a></div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= inr($c['claim_amt'],0) ?></div><div class="stat-lbl">Claimed</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($c['tpa_amt'],0) ?></div><div class="stat-lbl">TPA approved</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($c['cu_amt'],0) ?></div><div class="stat-lbl">CU approved</div></div>
    <div class="stat-card <?= rghs_category($c['status'])==='rejected'?'danger':(rghs_category($c['status'])==='approved'?'ok':'warn') ?>">
        <div class="stat-num" style="font-size:1rem"><?= e($c['status']) ?></div><div class="stat-lbl">Status</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Patient & claim</h2>
        <table class="kv">
            <tr><td>Patient</td><th><?= e($c['patient_name']) ?> <span class="muted">(<?= e($c['gender']) ?>)</span></th></tr>
            <tr><td>RGHS Card</td><th><?= e($c['card_no']) ?></th></tr>
            <tr><td>Enrollment ID</td><th><?= e($c['enrollment_id']) ?></th></tr>
            <tr><td>Mobile</td><th><?= e($c['mobile']) ?></th></tr>
            <tr><td>Type</td><th><?= e($c['claim_type']) ?> · <?= e($c['department']) ?></th></tr>
            <tr><td>Category</td><th><?= e($c['category']) ?></th></tr>
            <tr><td>Hospital</td><th><?= e($c['hospital_name']) ?> <?= $c['nabh']==='Yes'?'<span class="pill pill-info">NABH</span>':'' ?></th></tr>
            <tr><td>Grade</td><th><?= e($c['grade']) ?></th></tr>
            <tr><td>Invoice</td><th><?= e($c['invoice_no']) ?></th></tr>
        </table>
    </div>
    <div class="card">
        <h2>Dates & amounts</h2>
        <table class="kv">
            <tr><td>Admission</td><th><?= fdate($c['admit_date']) ?></th></tr>
            <tr><td>Discharge</td><th><?= fdate($c['discharge_date']) ?> <span class="muted">(<?= e($c['los']) ?> din)</span></th></tr>
            <tr><td>Claim submitted</td><th><?= fdate($c['submit_date']) ?></th></tr>
            <tr><td>TPA final action</td><th><?= fdate($c['tpa_action_date']) ?></th></tr>
            <tr><td>CU final action</td><th><?= fdate($c['cu_action_date']) ?></th></tr>
            <tr><td>Query status</td><th><?= e($c['query_status']) ?></th></tr>
        </table>
    </div>
</div>

<?php if (!empty($c['tpa_remarks']) || !empty($c['cu_remarks']) || !empty($c['last_query_remark'])): ?>
<div class="card">
    <h2>Remarks</h2>
    <?php if (!empty($c['cu_remarks'])): ?><p><strong>CU:</strong> <?= nl2br(e($c['cu_remarks'])) ?></p><?php endif; ?>
    <?php if (!empty($c['tpa_remarks'])): ?><p><strong>TPA:</strong> <?= nl2br(e($c['tpa_remarks'])) ?></p><?php endif; ?>
    <?php if (!empty($c['last_query_remark']) && $c['last_query_remark']!=='NA'): ?><p><strong>Last query:</strong> <?= nl2br(e($c['last_query_remark'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($c['package_name'])): ?>
<div class="card">
    <h2>Package</h2>
    <p class="small"><?= e($c['package_name']) ?></p>
    <p class="muted small">Codes: <?= e($c['package_code']) ?></p>
</div>
<?php endif; ?>

<div class="detail-grid">
    <div class="card form">
        <h2>Notes & doctor</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="act" value="save">
            <div class="fld"><label>Treating doctor</label><input name="doctor_name" value="<?= e($c['doctor_name']) ?>"></div>
            <div class="fld"><label>Notes (aapke liye)</label><textarea name="notes" rows="3"><?= e($c['notes']) ?></textarea></div>
            <label class="inline" style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" name="followup" value="1" <?= $c['followup']?'checked':'' ?>> Follow-up flag</label>
            <div class="form-actions"><button class="btn btn-primary">Save</button></div>
        </form>
        <p class="muted small">Doctor naam badloge to woh dubara upload par bhi bana rahega.</p>
    </div>
    <div class="card">
        <h2>Status history</h2>
        <?php if (!$history): ?><p class="muted">Koi change record nahi.</p><?php else: ?>
        <ul class="timeline">
            <?php foreach ($history as $h): ?>
                <li><span class="tl-date"><?= e(date('d-m-y', strtotime($h['changed_at']))) ?></span>
                    <span class="small"><?= e($h['from_status'] ?: 'नया') ?> → <strong><?= e($h['to_status']) ?></strong></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($more): ?>
<div class="card">
    <h2>Isi beneficiary ke aur claims (<?= count($more) ?>)</h2>
    <table class="tbl">
        <thead><tr><th>TID</th><th>Status</th><th>Submitted</th><th class="r">Claimed</th></tr></thead>
        <tbody>
        <?php foreach ($more as $m): ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($m['tid']) ?>"><?= e($m['tid']) ?></a></td>
                <td class="small"><?= e($m['status']) ?></td><td class="small"><?= fdate($m['submit_date']) ?></td>
                <td class="r"><?= inr($m['claim_amt'],0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
