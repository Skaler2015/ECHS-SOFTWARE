<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
rghs_ensure_table();

$pdo = db();
$tid = trim($_GET['tid'] ?? '');
$st = $pdo->prepare("SELECT * FROM rghs_claims WHERE tid = ?"); $st->execute([$tid]); $c = $st->fetch();
if (!$c) { http_response_code(404); exit('Claim nahi mila.'); }
$pst = $pdo->prepare("SELECT * FROM rghs_payments WHERE tid = ?"); $pst->execute([$tid]); $pay = $pst->fetch() ?: null;
function fd($d){ return $d ? date('d-m-Y', strtotime($d)) : '-'; }
$hospName = $c['hospital_name'] ?: APP_OWNER;
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="utf-8"><title>Claim <?= e($c['tid']) ?> — Statement</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:0;padding:24px;font-size:13px}
.head{text-align:center;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:16px}
.head h1{margin:0;font-size:18px}.head .sub{color:#555;font-size:12px}
h2{font-size:13px;background:#f0f0f0;padding:6px 8px;margin:16px 0 8px;border-left:3px solid #333}
table{width:100%;border-collapse:collapse;margin-bottom:8px}
td,th{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top}
td.k{width:32%;color:#555;background:#fafafa}
.two{display:flex;gap:16px}.two>div{flex:1}
.amt{text-align:right}
.foot{margin-top:24px;color:#777;font-size:11px;text-align:center;border-top:1px solid #ccc;padding-top:8px}
@media print{ .noprint{display:none} body{padding:6px} }
.btn{display:inline-block;padding:8px 16px;background:#1B2F5E;color:#fff;border:none;border-radius:6px;text-decoration:none;cursor:pointer}
</style>
</head>
<body>
<div class="noprint" style="text-align:right;margin-bottom:10px">
    <button class="btn" onclick="window.print()">🖨️ Print / PDF</button>
    <a class="btn" style="background:#555" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($tid) ?>">← Back</a>
</div>

<div class="head">
    <h1><?= e($hospName) ?></h1>
    <div class="sub">RGHS Claim Statement · Generated <?= date('d-m-Y H:i') ?></div>
</div>

<div class="two">
  <div>
    <h2>Patient</h2>
    <table>
        <tr><td class="k">Name</td><td><?= e($c['patient_name']) ?> (<?= e($c['gender']) ?>)</td></tr>
        <tr><td class="k">RGHS Card</td><td><?= e($c['card_no']) ?></td></tr>
        <tr><td class="k">Enrollment</td><td><?= e($c['enrollment_id']) ?></td></tr>
        <tr><td class="k">Mobile</td><td><?= e($c['mobile']) ?></td></tr>
        <tr><td class="k">Doctor</td><td><?= e($c['doctor_name']) ?></td></tr>
    </table>
  </div>
  <div>
    <h2>Claim</h2>
    <table>
        <tr><td class="k">TID</td><td><?= e($c['tid']) ?></td></tr>
        <tr><td class="k">Type / Dept</td><td><?= e($c['claim_type']) ?> / <?= e($c['department']) ?></td></tr>
        <tr><td class="k">Status</td><td><?= e($c['status']) ?></td></tr>
        <tr><td class="k">Admission</td><td><?= fd($c['admit_date']) ?> — <?= fd($c['discharge_date']) ?></td></tr>
        <tr><td class="k">Submitted</td><td><?= fd($c['submit_date']) ?></td></tr>
    </table>
  </div>
</div>

<h2>Amounts</h2>
<table>
    <tr><td class="k">Hospital claim amount</td><td class="amt"><?= money($c['claim_amt']) ?></td></tr>
    <tr><td class="k">TPA approved</td><td class="amt"><?= money($c['tpa_amt']) ?></td></tr>
    <tr><td class="k">CU approved</td><td class="amt"><?= money($c['cu_amt']) ?></td></tr>
    <?php if ($pay): ?>
    <tr><td class="k">Paid amount</td><td class="amt"><?= money($pay['paid_amount']) ?></td></tr>
    <tr><td class="k">TDS deducted</td><td class="amt"><?= money($pay['tds_deducted']) ?></td></tr>
    <?php endif; ?>
</table>

<?php if ($pay): ?>
<h2>Payment</h2>
<table>
    <tr><td class="k">Final status</td><td><?= e($pay['final_status']) ?></td></tr>
    <tr><td class="k">Credit date</td><td><?= fd($pay['credit_date']) ?></td></tr>
    <tr><td class="k">UTR</td><td><?= e($pay['utr']) ?></td></tr>
    <tr><td class="k">Bank / IFSC</td><td><?= e($pay['bank_name']) ?> / <?= e($pay['ifsc']) ?></td></tr>
    <tr><td class="k">Account</td><td><?= e($pay['account_no']) ?></td></tr>
    <tr><td class="k">Treasury voucher</td><td><?= e($pay['treasury_voucher']) ?></td></tr>
</table>
<?php endif; ?>

<?php if (!empty($c['cu_remarks']) || !empty($c['tpa_remarks'])): ?>
<h2>Remarks</h2>
<table>
    <?php if (!empty($c['cu_remarks'])): ?><tr><td class="k">CU</td><td><?= e($c['cu_remarks']) ?></td></tr><?php endif; ?>
    <?php if (!empty($c['tpa_remarks'])): ?><tr><td class="k">TPA</td><td><?= e($c['tpa_remarks']) ?></td></tr><?php endif; ?>
</table>
<?php endif; ?>

<div class="foot">Ye computer-generated statement hai — <?= e(APP_NAME) ?>.</div>
</body>
</html>
