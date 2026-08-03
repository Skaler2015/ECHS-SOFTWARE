<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'payments';
$page_title = 'ECHS Payments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        db()->prepare("INSERT INTO echs_payments (claim_id,amount,pay_date,utr,mode,remarks) VALUES (?,?,?,?,?,?)")
           ->execute([
               trim($_POST['claim_id'] ?? '') ?: null,
               (float)($_POST['amount'] ?? 0),
               ($_POST['pay_date'] ?? '') ?: null,
               trim($_POST['utr'] ?? '') ?: null,
               trim($_POST['mode'] ?? '') ?: null,
               trim($_POST['remarks'] ?? '') ?: null,
           ]);
        echs_log('payment_add', ($_POST['claim_id'] ?? '') . ' ₹' . ($_POST['amount'] ?? 0));
        flash('Payment record ho gaya.');
    } elseif ($act === 'del') {
        db()->prepare("DELETE FROM echs_payments WHERE id=?")->execute([(int)$_POST['id']]);
        flash('Payment delete ho gaya.');
    }
    redirect(BASE_URL . '/echs_payments.php?scheme=ECHS');
}

// reconciliation
$appr = db()->query("SELECT COALESCE(SUM(approved_amt),0) s FROM echs_claims WHERE status LIKE '%Settled%'")->fetch()['s'];
$recv = db()->query("SELECT COALESCE(SUM(amount),0) s FROM echs_payments")->fetch()['s'];
$balance = $appr - $recv;

$rows = db()->query("SELECT * FROM echs_payments ORDER BY COALESCE(pay_date,'0000-00-00') DESC, id DESC LIMIT 500")->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>💵 Payments &amp; Reconciliation</h1></div>

<div class="stat-grid">
    <div class="stat-card ok"><div class="stat-num"><?= money($appr) ?></div><div class="stat-lbl">Approved (Settled claims)</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($recv) ?></div><div class="stat-lbl">Received (recorded)</div></div>
    <div class="stat-card <?= $balance>0?'warn':'' ?>"><div class="stat-num"><?= money($balance) ?></div><div class="stat-lbl">Balance (baaki)</div></div>
</div>

<div class="card form">
    <h2>Naya Payment record karein</h2>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="grid3">
            <div class="fld"><label>Claim ID (optional)</label><input name="claim_id" placeholder="jaise 22613978"></div>
            <div class="fld"><label>Amount *</label><input type="number" step="0.01" name="amount" required></div>
            <div class="fld"><label>Date</label><input type="date" name="pay_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="fld"><label>UTR / Ref No.</label><input name="utr"></div>
            <div class="fld"><label>Mode</label>
                <select name="mode"><?php foreach (['','NEFT','RTGS','Cheque','Cash','Other'] as $m): ?><option><?= $m ?></option><?php endforeach; ?></select>
            </div>
            <div class="fld"><label>Remarks</label><input name="remarks"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">+ Add Payment</button></div>
    </form>
</div>

<div class="card">
    <h2>Payment History</h2>
    <?php if (!$rows): ?><p class="muted">Abhi koi payment record nahi.</p><?php else: ?>
    <table class="tbl">
        <thead><tr><th>Date</th><th>Claim</th><th class="r">Amount</th><th>UTR</th><th>Mode</th><th>Remarks</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td class="nowrap"><?= fdate($p['pay_date']) ?></td>
                <td><?php if ($p['claim_id']): ?><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($p['claim_id']) ?>"><?= e($p['claim_id']) ?></a><?php else: ?>-<?php endif; ?></td>
                <td class="r"><?= money($p['amount']) ?></td>
                <td><?= e($p['utr'] ?: '-') ?></td>
                <td><?= e($p['mode'] ?: '-') ?></td>
                <td><?= e($p['remarks'] ?: '-') ?></td>
                <td class="r"><form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn-x">✕</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
