<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'patientsearch';
$page_title = 'Patient Search';

$pdo = db();
$q = trim($_GET['q'] ?? '');
$rows = []; $count = 0;
if ($q !== '') {
    $like = "%$q%";
    $st = $pdo->prepare("SELECT claim_id, card_id, esm_name, patient_name, patient_type, accept_date, net_claim_amt, approved_amt, status
        FROM echs_claims
        WHERE patient_name LIKE ? OR esm_name LIKE ? OR card_id LIKE ?
        ORDER BY COALESCE(accept_date,'1900-01-01') DESC LIMIT 300");
    $st->execute([$like, $like, $like]);
    $rows = $st->fetchAll();
    $count = count($rows);
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>⊕ Patient Search</h1></div>

<div class="card form">
    <form method="get" class="inline-search">
        <input type="hidden" name="scheme" value="ECHS">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Patient / ESM naam ya card ID…" autofocus>
        <button class="btn btn-primary">Search</button>
    </form>
    <p class="muted small">Patient naam, ESM naam ya card ID se dhundein. (Max 300 results)</p>
</div>

<?php if ($q === ''): ?>
    <p class="muted">Search karne ke liye upar naam daalein.</p>
<?php elseif (!$rows): ?>
    <div class="card"><p class="muted">"<strong><?= e($q) ?></strong>" ke liye kuch nahi mila.</p></div>
<?php else: ?>
    <div class="card">
        <h2><?= number_format($count) ?> result<?= $count>1?'s':'' ?></h2>
        <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Claim</th><th>Patient</th><th>ESM</th><th>Card</th><th>Type</th><th>Accept</th><th class="r">Net</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>"><?= e($c['claim_id']) ?></a></td>
                    <td><?= e($c['patient_name'] ?: '-') ?></td>
                    <td><?= e($c['esm_name'] ?: '-') ?></td>
                    <td><a class="link" href="<?= BASE_URL ?>/echs_card.php?scheme=ECHS&card=<?= urlencode($c['card_id']) ?>"><?= e($c['card_id'] ?: '-') ?></a></td>
                    <td><?= e($c['patient_type'] ?: '-') ?></td>
                    <td><?= $c['accept_date'] ? e(date('d-m-y', strtotime($c['accept_date']))) : '-' ?></td>
                    <td class="r"><?= inr($c['net_claim_amt'],0) ?></td>
                    <td><span class="pill pill-<?= echs_category($c['status']) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
