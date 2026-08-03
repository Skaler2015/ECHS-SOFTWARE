<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'claims';

$id = trim($_GET['id'] ?? '');

// save notes / followup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = trim($_POST['claim_id'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $fu = isset($_POST['followup']) ? 1 : 0;
    db()->prepare("UPDATE echs_claims SET notes=?, followup=? WHERE claim_id=?")->execute([$notes, $fu, $cid]);
    flash('Notes save ho gaye.');
    redirect(BASE_URL . '/echs_claim.php?scheme=ECHS&id=' . urlencode($cid));
}

$q = db()->prepare("SELECT * FROM echs_claims WHERE claim_id=?");
$q->execute([$id]);
$c = $q->fetch();
if (!$c) { flash('Claim nahi mila.', 'error'); redirect(BASE_URL . '/echs_claims.php?scheme=ECHS'); }

$hist = [];
$h = db()->prepare("SELECT * FROM echs_claim_history WHERE claim_id=? ORDER BY id DESC");
$h->execute([$id]);
$hist = $h->fetchAll();

// other claims of same card
$others = [];
if ($c['card_id']) {
    $o = db()->prepare("SELECT claim_id, patient_name, status, net_claim_amt, accept_date_raw FROM echs_claims WHERE card_id=? AND claim_id<>? ORDER BY accept_date DESC LIMIT 20");
    $o->execute([$c['card_id'], $id]);
    $others = $o->fetchAll();
}

$page_title = 'Claim ' . $c['claim_id'];
$cat = echs_category($c['status']);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Claim #<?= e($c['claim_id']) ?></h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">← All Claims</a>
    </div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Claim Details</h2>
        <table class="kv">
            <tr><td>Status</td><th><span class="pill pill-<?= $cat ?>"><?= e($c['status']) ?></span></th></tr>
            <tr><td>Card ID</td><th><?= e($c['card_id']) ?></th></tr>
            <tr><td>Name of ESM</td><th><?= e($c['esm_name']) ?></th></tr>
            <tr><td>Patient</td><th><?= e($c['patient_name']) ?></th></tr>
            <tr><td>Type</td><th><?= e($c['patient_type']) ?> (<?= $c['patient_type']==='I'?'IPD':'OPD' ?>) / <?= e($c['admit_type']) ?></th></tr>
            <tr><td>Region</td><th><?= e($c['region']) ?></th></tr>
            <tr><td>Hospital</td><th><?= e($c['hospital_name']) ?></th></tr>
            <tr><td>Accept Date</td><th><?= e($c['accept_date_raw'] ?: '-') ?></th></tr>
            <tr><td>Processed On</td><th><?= e($c['processed_on_raw'] ?: '-') ?></th></tr>
            <tr><td>Net Claim Amt</td><th><?= money($c['net_claim_amt']) ?></th></tr>
            <tr><td>Approved Amt</td><th><?= money($c['approved_amt']) ?></th></tr>
            <tr><td>Deduction</td><th><?= money($c['net_claim_amt'] - $c['approved_amt']) ?></th></tr>
        </table>
    </div>

    <div class="card">
        <h2>📝 Follow-up Notes</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="claim_id" value="<?= e($c['claim_id']) ?>">
            <label class="chk"><input type="checkbox" name="followup" value="1" <?= $c['followup']?'checked':'' ?>> Follow-up chahiye (flag)</label>
            <textarea name="notes" rows="6" class="notes-area" placeholder="Yahan apne notes likhein — kisko phone karna hai, kya document chahiye, etc."><?= e($c['notes']) ?></textarea>
            <div class="form-actions"><button class="btn btn-primary">Save Notes</button></div>
        </form>
    </div>
</div>

<div class="card">
    <h2>🕒 Status History</h2>
    <?php if (!$hist): ?>
        <p class="muted">Abhi tak koi status badla hua record nahi (pehli baar upload hone par yahan timeline banegi).</p>
    <?php else: ?>
        <ul class="timeline">
        <?php foreach ($hist as $x): ?>
            <li>
                <span class="tl-dot"></span>
                <div class="tl-body">
                    <strong><?= e($x['to_status']) ?></strong>
                    <?php if ($x['from_status']): ?><span class="muted"> ← <?= e($x['from_status']) ?></span><?php endif; ?>
                    <div class="muted small"><?= fdate($x['changed_at']) ?> · <?= e(date('H:i', strtotime($x['changed_at']))) ?></div>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($others): ?>
<div class="card">
    <h2>Isi Card ke doosre claims (<?= e($c['card_id']) ?>)</h2>
    <table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Accept</th><th class="r">Net</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($others as $o): ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($o['claim_id']) ?>"><?= e($o['claim_id']) ?></a></td>
                <td><?= e($o['patient_name']) ?></td>
                <td><?= e($o['accept_date_raw'] ?: '-') ?></td>
                <td class="r"><?= number_format($o['net_claim_amt'],0) ?></td>
                <td><span class="pill pill-<?= echs_category($o['status']) ?>"><?= e($o['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
