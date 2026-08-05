<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$page_title = $meta['short'] . ' Dashboard';
$active = 'dashboard';

// ECHS is now a native upload-based claims tracker.
if ($scheme === 'ECHS') {
    require __DIR__ . '/includes/echs_dashboard.php';
    return;
}

// RGHS is now an upload-based claims tracker.
if ($scheme === 'RGHS') {
    require __DIR__ . '/includes/rghs_dashboard.php';
    return;
}

// stats
$stat = ['patients'=>0,'bills'=>0,'pending'=>0,'submitted'=>0,'paid'=>0,'amount'=>0,'paid_amount'=>0];
try {
    $q = db()->prepare('SELECT COUNT(*) n FROM patients WHERE scheme=?'); $q->execute([$scheme]);
    $stat['patients'] = (int)$q->fetch()['n'];

    $q = db()->prepare("SELECT
            COUNT(*) n,
            COALESCE(SUM(total_amount),0) amt,
            SUM(status='Pending') pend,
            SUM(status='Submitted') sub,
            SUM(status='Paid') paid,
            COALESCE(SUM(CASE WHEN status='Paid' THEN total_amount ELSE 0 END),0) paid_amt
        FROM bills WHERE scheme=?");
    $q->execute([$scheme]);
    $r = $q->fetch();
    $stat['bills']=(int)$r['n']; $stat['amount']=(float)$r['amt'];
    $stat['pending']=(int)$r['pend']; $stat['submitted']=(int)$r['sub'];
    $stat['paid']=(int)$r['paid']; $stat['paid_amount']=(float)$r['paid_amt'];
} catch (Exception $e) {}

// recent bills
$recent = [];
try {
    $q = db()->prepare('SELECT * FROM bills WHERE scheme=? ORDER BY id DESC LIMIT 8');
    $q->execute([$scheme]);
    $recent = $q->fetchAll();
} catch (Exception $e) {}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1><?= e($meta['icon']) ?> <?= e($meta['short']) ?> Dashboard</h1>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>">+ New Bill</a>
        <a class="btn" href="<?= BASE_URL ?>/patient_form.php?scheme=<?= $scheme ?>">+ New Patient</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= $stat['patients'] ?></div><div class="stat-lbl">Patients</div></div>
    <div class="stat-card"><div class="stat-num"><?= $stat['bills'] ?></div><div class="stat-lbl">Total Bills</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= $stat['pending'] ?></div><div class="stat-lbl">Pending</div></div>
    <div class="stat-card info"><div class="stat-num"><?= $stat['submitted'] ?></div><div class="stat-lbl">Submitted</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= $stat['paid'] ?></div><div class="stat-lbl">Paid</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($stat['amount']) ?></div><div class="stat-lbl">Total Claimed</div></div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Recent Bills</h2>
        <a href="<?= BASE_URL ?>/bills.php?scheme=<?= $scheme ?>">View all →</a>
    </div>
    <?php if (!$recent): ?>
        <p class="muted">Abhi tak koi bill nahi hai. <a href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>">Pehla bill banayein →</a></p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Bill No</th><th>Date</th><th>Patient</th><th>Card No</th><th class="r">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $b): ?>
            <tr>
                <td><?= e($b['bill_no']) ?></td>
                <td><?= fdate($b['bill_date']) ?></td>
                <td><?= e($b['patient_name']) ?></td>
                <td><?= e($b['card_no']) ?></td>
                <td class="r"><?= money($b['total_amount']) ?></td>
                <td><span class="pill pill-<?= strtolower($b['status']) ?>"><?= e($b['status']) ?></span></td>
                <td class="r">
                    <a class="link" href="<?= BASE_URL ?>/bill_print.php?id=<?= $b['id'] ?>" target="_blank">Print</a>
                    <a class="link" href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>&id=<?= $b['id'] ?>">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
