<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'patients';
$page_title = 'RGHS Patient / Beneficiary';
$pdo = db();

$q = trim($_GET['q'] ?? $_GET['card'] ?? '');
$claims = []; $agg = null; $name=''; $card=''; $enrol='';
if ($q !== '') {
    // match by card, enrollment, or name
    $st = $pdo->prepare("SELECT * FROM rghs_claims
        WHERE card_no = ? OR enrollment_id = ? OR patient_name LIKE ?
        ORDER BY COALESCE(submit_date,'1900-01-01') DESC, tid DESC LIMIT 500");
    $st->execute([$q, $q, "%$q%"]);
    $claims = $st->fetchAll();
    if ($claims) {
        foreach ($claims as $c) { if (!empty($c['patient_name'])) { $name=$c['patient_name']; $card=$c['card_no']; $enrol=$c['enrollment_id']; break; } }
        $ids = array_column($claims,'tid');
        $agg = [
            'n' => count($claims),
            'claim' => array_sum(array_column($claims,'claim_amt')),
            'cu' => array_sum(array_column($claims,'cu_amt')),
            'paid' => array_sum(array_column($claims,'paid_amount')),
        ];
        // payments for these tids
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pst = $pdo->prepare("SELECT * FROM rghs_payments WHERE tid IN ($in)");
        $pst->execute($ids); $paysByTid = [];
        foreach ($pst as $p) $paysByTid[$p['tid']] = $p;
    }
}

function pdate($d){ return $d ? date('d-m-y', strtotime($d)) : '-'; }
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>⊕ Patient / Beneficiary</h1></div>

<div class="card form">
    <form method="get" class="inline-search">
        <input type="hidden" name="scheme" value="RGHS">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="RGHS Card No, Enrollment ID, ya Patient naam…" autofocus>
        <button class="btn btn-primary">Search</button>
    </form>
    <p class="muted small">Card ya enrollment se saare claims + payments ek jagah dikhenge.</p>
</div>

<?php if ($q === ''): ?>
    <p class="muted">Upar card / enrollment / naam daalein.</p>
<?php elseif (!$claims): ?>
    <div class="card"><p class="muted">"<strong><?= e($q) ?></strong>" ke liye kuch nahi mila.</p></div>
<?php else: ?>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= number_format($agg['n']) ?></div><div class="stat-lbl"><?= e($name ?: 'Patient') ?></div></div>
        <div class="stat-card"><div class="stat-num"><?= inr($agg['claim'],0) ?></div><div class="stat-lbl">Claimed</div></div>
        <div class="stat-card ok"><div class="stat-num"><?= inr($agg['cu'],0) ?></div><div class="stat-lbl">CU Approved</div></div>
        <div class="stat-card info"><div class="stat-num"><?= inr($agg['paid'],0) ?></div><div class="stat-lbl">Paid (received)</div></div>
    </div>
    <?php if ($card || $enrol): ?><p class="muted small">Card: <strong><?= e($card) ?></strong> · Enrollment: <strong><?= e($enrol) ?></strong></p><?php endif; ?>

    <div class="card">
        <h2>Claims & payments (<?= number_format($agg['n']) ?>)</h2>
        <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>TID</th><th>Type</th><th>Status</th><th>Submitted</th><th class="r">Claimed</th><th class="r">Approved</th><th class="r">Paid</th><th>UTR</th></tr></thead>
            <tbody>
            <?php foreach ($claims as $c): $p = $paysByTid[$c['tid']] ?? null; ?>
                <tr>
                    <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($c['tid']) ?>"><?= e($c['tid']) ?></a></td>
                    <td class="small"><?= e($c['claim_type'] ?: '-') ?></td>
                    <td><span class="pill pill-<?= rghs_category($c['status'])==='approved'?'settled':(rghs_category($c['status'])==='rejected'?'rejected':(rghs_category($c['status'])==='query'?'info':'process')) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                    <td class="small"><?= pdate($c['submit_date']) ?></td>
                    <td class="r"><?= inr($c['claim_amt'],0) ?></td>
                    <td class="r"><?= inr($c['cu_amt'],0) ?></td>
                    <td class="r"><?= $c['paid_amount']>0 ? inr($c['paid_amount'],0) : '<span class="muted">-</span>' ?></td>
                    <td class="small"><?= $p ? e($p['utr']) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
