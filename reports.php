<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$page_title = 'Reports';
$active = 'reports';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

$rows = [];
$totals = ['count'=>0,'amount'=>0];
try {
    $sql = 'SELECT * FROM bills WHERE scheme=? AND bill_date BETWEEN ? AND ?';
    $args = [$scheme, $from, $to];
    if ($status !== '' && in_array($status, ['Pending','Submitted','Paid','Rejected'])) {
        $sql .= ' AND status=?'; $args[] = $status;
    }
    $sql .= ' ORDER BY bill_date, id';
    $q = db()->prepare($sql); $q->execute($args);
    $rows = $q->fetchAll();
    $totals['count'] = count($rows);
    $totals['amount'] = array_sum(array_column($rows, 'total_amount'));
} catch (Exception $e) {}

// status breakdown
$byStatus = ['Pending'=>0,'Submitted'=>0,'Paid'=>0,'Rejected'=>0];
foreach ($rows as $r) { $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + $r['total_amount']; }

$isPrint = isset($_GET['print']);
if (!$isPrint) require __DIR__ . '/includes/header.php';
else {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report</title><link rel="stylesheet" href="'.BASE_URL.'/assets/css/style.css"></head><body class="print-page"><div class="print-toolbar no-print"><button class="btn btn-primary" onclick="window.print()">🖨️ Print</button></div><div class="invoice">';
    echo '<h1>'.e(setting('org_name','Noble')).' — '.e($meta['short']).' Report</h1>';
    echo '<p>Period: '.fdate($from).' to '.fdate($to).'</p>';
}
?>
<?php if (!$isPrint): ?>
<div class="page-head">
    <h1>Reports — <?= e($meta['short']) ?></h1>
    <div class="page-actions">
        <a class="btn" target="_blank" href="<?= BASE_URL ?>/reports.php?scheme=<?= $scheme ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&status=<?= e($status) ?>&print=1">🖨️ Print</a>
        <a class="btn" href="<?= BASE_URL ?>/api/export_csv.php?scheme=<?= $scheme ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&status=<?= e($status) ?>">⬇ CSV</a>
    </div>
</div>

<form class="searchbar" method="get">
    <input type="hidden" name="scheme" value="<?= $scheme ?>">
    <label class="inline">From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="inline">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <select name="status">
        <option value="">All status</option>
        <?php foreach (['Pending','Submitted','Paid','Rejected'] as $s): ?>
            <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Apply</button>
</form>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= $totals['count'] ?></div><div class="stat-lbl">Bills</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($totals['amount']) ?></div><div class="stat-lbl">Total</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= money($byStatus['Pending']) ?></div><div class="stat-lbl">Pending</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= money($byStatus['Paid']) ?></div><div class="stat-lbl">Paid</div></div>
</div>
<?php endif; ?>

<div class="card">
    <table class="tbl">
        <thead><tr><th>Bill No</th><th>Date</th><th>Patient</th><th>Card No</th><th>Hospital</th><th class="r">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $b): ?>
            <tr>
                <td><?= e($b['bill_no']) ?></td>
                <td><?= fdate($b['bill_date']) ?></td>
                <td><?= e($b['patient_name']) ?></td>
                <td><?= e($b['card_no']) ?></td>
                <td><?= e($b['hospital'] ?: '-') ?></td>
                <td class="r"><?= money($b['total_amount']) ?></td>
                <td><?= e($b['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">Is period me koi bill nahi.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td colspan="5" class="r"><strong>Total</strong></td><td class="r"><strong><?= money($totals['amount']) ?></strong></td><td></td></tr></tfoot>
    </table>
</div>

<?php
if (!$isPrint) require __DIR__ . '/includes/footer.php';
else echo '</div></body></html>';
?>
