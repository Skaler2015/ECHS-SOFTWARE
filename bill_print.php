<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$q = db()->prepare('SELECT * FROM bills WHERE id=?');
$q->execute([$id]);
$b = $q->fetch();
if (!$b) { die('Bill nahi mila.'); }
$meta = scheme_meta($b['scheme']);

$qi = db()->prepare('SELECT * FROM bill_items WHERE bill_id=? ORDER BY id');
$qi->execute([$id]);
$items = $qi->fetchAll();

$org_name = setting('org_name', 'Noble Medical Store');
$org_addr = setting('org_address', '');
$org_phone= setting('org_phone', '');
$org_dl   = setting('org_dl_no', '');
$org_gst  = setting('org_gstin', '');
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <title>Bill <?= e($b['bill_no']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="print-page">
<div class="print-toolbar no-print">
    <button class="btn btn-primary" onclick="window.print()">🖨️ Print</button>
    <a class="btn btn-light" href="<?= BASE_URL ?>/bills.php?scheme=<?= $b['scheme'] ?>">← Back</a>
</div>

<div class="invoice" style="--scheme-color:<?= e($meta['color']) ?>">
    <div class="inv-head">
        <div>
            <h1><?= e($org_name) ?></h1>
            <p><?= e($org_addr) ?><?= $org_phone?' · 📞 '.e($org_phone):'' ?></p>
            <p class="small"><?= $org_dl?'D.L. No: '.e($org_dl).'  ':'' ?><?= $org_gst?'GSTIN: '.e($org_gst):'' ?></p>
        </div>
        <div class="inv-scheme" style="background:<?= e($meta['color']) ?>">
            <?= e($meta['icon']) ?> <?= e($meta['short']) ?><br><small>CLAIM BILL</small>
        </div>
    </div>

    <div class="inv-meta">
        <table>
            <tr><td>Bill No</td><th><?= e($b['bill_no']) ?></th><td>Bill Date</td><th><?= fdate($b['bill_date']) ?></th></tr>
            <tr><td>Patient</td><th><?= e($b['patient_name']) ?></th><td><?= e($meta['card_label']) ?></td><th><?= e($b['card_no']) ?></th></tr>
            <tr><td>Doctor</td><th><?= e($b['doctor_name'] ?: '-') ?></th><td>Hospital</td><th><?= e($b['hospital'] ?: '-') ?></th></tr>
            <tr><td>Presc. Date</td><th><?= fdate($b['prescription_date']) ?></th><td><?= $b['scheme']==='ECHS'?'Referral No':'Ref No' ?></td><th><?= e($b['referral_no'] ?: '-') ?></th></tr>
        </table>
    </div>

    <table class="inv-items">
        <thead><tr><th>#</th><th>Medicine</th><th>Batch</th><th>Exp</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
        <tbody>
        <?php $i=1; foreach ($items as $it): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= e($it['medicine']) ?></td>
                <td><?= e($it['batch']) ?></td>
                <td><?= e($it['expiry']) ?></td>
                <td class="r"><?= rtrim(rtrim(number_format($it['qty'],2),'0'),'.') ?></td>
                <td class="r"><?= number_format($it['rate'],2) ?></td>
                <td class="r"><?= number_format($it['amount'],2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="6" class="r">Sub Total</td><td class="r"><?= number_format($b['sub_total'],2) ?></td></tr>
            <?php if ((float)$b['discount']>0): ?><tr><td colspan="6" class="r">Discount</td><td class="r">- <?= number_format($b['discount'],2) ?></td></tr><?php endif; ?>
            <tr class="grand"><td colspan="6" class="r">Grand Total</td><td class="r"><?= money($b['total_amount']) ?></td></tr>
        </tfoot>
    </table>

    <?php if ($b['remarks']): ?><p class="inv-remarks"><strong>Remarks:</strong> <?= e($b['remarks']) ?></p><?php endif; ?>

    <div class="inv-foot">
        <div>Status: <strong><?= e($b['status']) ?></strong></div>
        <div class="sign">Authorised Signatory<br><small><?= e($org_name) ?></small></div>
    </div>
</div>
<script>window.addEventListener('load',function(){ /* auto focus print btn */ });</script>
</body>
</html>
