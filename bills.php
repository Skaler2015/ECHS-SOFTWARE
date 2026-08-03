<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$page_title = $meta['short'] . ' Bills';
$active = 'bills';

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$rows = [];
try {
    $sql = 'SELECT * FROM bills WHERE scheme=?';
    $args = [$scheme];
    if ($search !== '') {
        $sql .= ' AND (bill_no LIKE ? OR patient_name LIKE ? OR card_no LIKE ?)';
        $like = "%$search%"; array_push($args, $like, $like, $like);
    }
    if ($status !== '' && in_array($status, ['Pending','Submitted','Paid','Rejected'])) {
        $sql .= ' AND status=?'; $args[] = $status;
    }
    $sql .= ' ORDER BY id DESC LIMIT 500';
    $q = db()->prepare($sql); $q->execute($args);
    $rows = $q->fetchAll();
} catch (Exception $e) {}

$sum = array_sum(array_column($rows, 'total_amount'));

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Bills / Claims — <?= e($meta['short']) ?></h1>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>">+ New Bill</a>
    </div>
</div>

<form class="searchbar" method="get">
    <input type="hidden" name="scheme" value="<?= $scheme ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Bill no, patient, card no...">
    <select name="status">
        <option value="">All status</option>
        <?php foreach (['Pending','Submitted','Paid','Rejected'] as $s): ?>
            <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
    <?php if ($search||$status): ?><a class="btn btn-light" href="<?= BASE_URL ?>/bills.php?scheme=<?= $scheme ?>">Clear</a><?php endif; ?>
</form>

<div class="card">
<?php if (!$rows): ?>
    <p class="muted">Koi bill nahi mila. <a href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>">Naya bill banayein →</a></p>
<?php else: ?>
    <table class="tbl">
        <thead><tr><th>Bill No</th><th>Date</th><th>Patient</th><th>Card No</th><th>Hospital</th><th class="r">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $b): ?>
            <tr>
                <td><?= e($b['bill_no']) ?></td>
                <td><?= fdate($b['bill_date']) ?></td>
                <td><?= e($b['patient_name']) ?></td>
                <td><?= e($b['card_no']) ?></td>
                <td><?= e($b['hospital'] ?: '-') ?></td>
                <td class="r"><?= money($b['total_amount']) ?></td>
                <td><span class="pill pill-<?= strtolower($b['status']) ?>"><?= e($b['status']) ?></span></td>
                <td class="r nowrap">
                    <a class="link" href="<?= BASE_URL ?>/bill_print.php?id=<?= $b['id'] ?>" target="_blank">Print</a>
                    <a class="link" href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>&id=<?= $b['id'] ?>">Edit</a>
                    <a class="link danger" href="<?= BASE_URL ?>/bill_delete.php?id=<?= $b['id'] ?>&scheme=<?= $scheme ?>" onclick="return confirm('Yeh bill delete karein?')">Del</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="5" class="r"><strong>Total (<?= count($rows) ?> bills)</strong></td><td class="r"><strong><?= money($sum) ?></strong></td><td colspan="2"></td></tr></tfoot>
    </table>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
