<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'bank';
$page_title = 'Bank Reconciliation';
$pdo = db();

/** Core UTR token from a stored UTR like "/XUTR/RBISH00659020352" -> "RBISH00659020352". */
function utr_token($utr) {
    $utr = strtoupper((string)$utr);
    if (preg_match('/[A-Z]{2,}\d{6,}/', $utr, $m)) return $m[0];
    $parts = preg_split('#[/\s]+#', $utr);
    return end($parts) ?: '';
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['bankfile']['tmp_name'])) {
    csrf_check();
    $bankText = @file_get_contents($_FILES['bankfile']['tmp_name']);
    if ($bankText === false) $bankText = '';
    $bankUpper = strtoupper($bankText);

    // all SUCCESS payments with a UTR
    $pays = $pdo->query("SELECT p.tid, p.utr, p.paid_amount, p.credit_date, c.patient_name
        FROM rghs_payments p LEFT JOIN rghs_claims c ON c.tid = p.tid COLLATE utf8mb4_unicode_ci
        WHERE p.final_status LIKE '%SUCCESS%' AND p.utr IS NOT NULL AND p.utr <> ''")->fetchAll();

    $matched = []; $unmatched = []; $mAmt = 0; $uAmt = 0;
    foreach ($pays as $p) {
        $tok = utr_token($p['utr']);
        if ($tok !== '' && strpos($bankUpper, $tok) !== false) { $matched[] = $p; $mAmt += $p['paid_amount']; }
        else { $unmatched[] = $p; $uAmt += $p['paid_amount']; }
    }
    rghs_log('bank_recon', count($matched).' matched, '.count($unmatched).' unmatched');
    $result = ['matched'=>$matched, 'unmatched'=>$unmatched, 'mAmt'=>$mAmt, 'uAmt'=>$uAmt, 'total'=>count($pays)];
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>🏦 Bank Reconciliation</h1></div>

<div class="card">
    <h2>Bank statement upload karein</h2>
    <p class="muted small">Apna bank statement <strong>CSV</strong> (ya text) me export karke yahan daalein.
       Software aapke SUCCESS payments ke <strong>UTR</strong> statement me dhundhega — kaunsa paisa sach me
       aaya (matched) aur kaunsa nahi mila (check karein). Koi data save nahi hota, sirf milaan hota hai.</p>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="upload-drop"><input type="file" name="bankfile" accept=".csv,.txt" required></div>
        <div class="form-actions" style="margin-top:12px"><button class="btn btn-primary">Reconcile karein</button></div>
    </form>
</div>

<?php if ($result): ?>
<div class="stat-grid">
    <div class="stat-card ok"><div class="stat-num"><?= number_format(count($result['matched'])) ?></div><div class="stat-lbl">Matched (<?= money($result['mAmt']) ?>)</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format(count($result['unmatched'])) ?></div><div class="stat-lbl">Bank me nahi mila (<?= money($result['uAmt']) ?>)</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($result['total']) ?></div><div class="stat-lbl">Total SUCCESS payments</div></div>
</div>

<div class="card">
    <h2>❌ Bank statement me nahi mile (<?= count($result['unmatched']) ?>)</h2>
    <?php if (!$result['unmatched']): ?><p class="muted">Sab payments bank me mil gaye ✅</p><?php else: ?>
    <p class="muted small">In payments ka UTR aapke statement me nahi mila — ho sakta hai alag date/statement me ho, ya check karna ho.</p>
    <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>UTR</th><th>Credit date</th><th class="r">Amount</th></tr></thead><tbody>
    <?php foreach ($result['unmatched'] as $p): ?><tr>
        <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($p['tid']) ?>"><?= e($p['patient_name'] ?: $p['tid']) ?></a></td>
        <td class="small"><?= e($p['utr']) ?></td><td class="small"><?= $p['credit_date']?e(date('d-m-y',strtotime($p['credit_date']))):'-' ?></td>
        <td class="r"><?= inr($p['paid_amount'],0) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
