<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$org = setting('org_name', 'Noble Care Hospital');
$tot = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims")->fetch();
$byStatus = db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims GROUP BY status ORDER BY n DESC")->fetchAll();
$pend = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE " . echs_pending_condition())->fetch();
$recv = db()->query("SELECT COALESCE(SUM(amount),0) s FROM echs_payments")->fetch()['s'];
?>
<!DOCTYPE html>
<html lang="hi"><head>
<meta charset="utf-8"><title>ECHS Report</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head><body class="print-page">
<div class="print-toolbar no-print">
    <button class="btn btn-primary" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <a class="btn btn-light" href="<?= BASE_URL ?>/echs_reports.php?scheme=ECHS">← Back</a>
</div>
<div class="invoice">
    <div class="inv-head">
        <div><h1><?= e($org) ?></h1><p>ECHS Claims Summary Report</p><p class="small">Generated: <?= date('d-m-Y H:i') ?></p></div>
        <div class="inv-scheme" style="background:#198754">🎖️ ECHS</div>
    </div>

    <table class="inv-meta"><tr>
        <td>Total Claims</td><th><?= number_format($tot['n']) ?></th>
        <td>Net Claimed</td><th><?= money($tot['net']) ?></th>
    </tr><tr>
        <td>Approved</td><th><?= money($tot['app']) ?></th>
        <td>Deduction</td><th><?= money($tot['net']-$tot['app']) ?></th>
    </tr><tr>
        <td>Outstanding</td><th><?= money($pend['net']) ?> (<?= number_format($pend['n']) ?>)</th>
        <td>Received</td><th><?= money($recv) ?></th>
    </tr></table>

    <h3>Status-wise Breakdown</h3>
    <table class="inv-items">
        <thead><tr><th>Status</th><th class="r">Claims</th><th class="r">Net Amt</th><th class="r">Approved</th></tr></thead>
        <tbody>
        <?php foreach ($byStatus as $s): ?>
            <tr><td><?= e($s['status']) ?></td><td class="r"><?= number_format($s['n']) ?></td>
            <td class="r"><?= inr($s['net'],0) ?></td><td class="r"><?= inr($s['app'],0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="grand"><td class="r">Total</td><td class="r"><?= number_format($tot['n']) ?></td>
        <td class="r"><?= inr($tot['net'],0) ?></td><td class="r"><?= inr($tot['app'],0) ?></td></tr></tfoot>
    </table>
</div>
</body></html>
