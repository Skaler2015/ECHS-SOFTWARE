<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'changes';
$page_title = 'Kya Badla';
$pdo = db();

$days = (int)($_GET['days'] ?? 14); if ($days < 1 || $days > 120) $days = 14;

// status changes in the window
$statusChg = $pdo->prepare("SELECT h.tid, h.from_status, h.to_status, h.changed_at, c.patient_name, c.claim_amt
    FROM rghs_claim_history h LEFT JOIN rghs_claims c ON c.tid = h.tid COLLATE utf8mb4_unicode_ci
    WHERE h.from_status IS NOT NULL AND h.changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY h.changed_at DESC LIMIT 300");
$statusChg->execute([$days]); $statusChg = $statusChg->fetchAll();

// newly paid
$newPaid = $pdo->prepare("SELECT tid, patient_name, paid_amount, payment_date, utr
    FROM rghs_claims WHERE payment_date IS NOT NULL AND payment_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY payment_date DESC LIMIT 200");
$newPaid->execute([$days]); $newPaid = $newPaid->fetchAll();

// newly seen (imported first time in window)
$newClaims = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE first_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)");
$newClaims->execute([$days]); $nc = $newClaims->fetch();

// count summary
$becameApproved = 0; $becameRejected = 0; $becameQuery = 0;
foreach ($statusChg as $h) { $c=rghs_category($h['to_status']); if($c==='approved')$becameApproved++; elseif($c==='rejected')$becameRejected++; elseif($c==='query')$becameQuery++; }

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🔔 Kya Badla</h1>
    <div class="page-actions">
        <?php foreach ([7,14,30,60] as $d): ?>
            <a class="btn <?= $days===$d?'btn-primary':'' ?>" href="<?= BASE_URL ?>/rghs_changes.php?scheme=RGHS&days=<?= $d ?>"><?= $d ?> din</a>
        <?php endforeach; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($nc['n']) ?></div><div class="stat-lbl">Naye claims (<?= $days ?> din)</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= number_format($becameApproved) ?></div><div class="stat-lbl">Approve hue</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($becameRejected) ?></div><div class="stat-lbl">Reject hue</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format(count($newPaid)) ?></div><div class="stat-lbl">Payment aaye</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>💰 Naye payments (<?= count($newPaid) ?>)</h2>
        <?php if (!$newPaid): ?><p class="muted">Is period me koi payment nahi.</p><?php else: ?>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>Date</th><th class="r">Paid</th><th>UTR</th></tr></thead><tbody>
        <?php foreach ($newPaid as $p): ?><tr>
            <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($p['tid']) ?>"><?= e($p['patient_name'] ?: $p['tid']) ?></a></td>
            <td class="small"><?= e(date('d-m-y', strtotime($p['payment_date']))) ?></td>
            <td class="r"><?= inr($p['paid_amount'],0) ?></td><td class="small"><?= e($p['utr']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>🔁 Status changes (<?= count($statusChg) ?>)</h2>
        <?php if (!$statusChg): ?><p class="muted">Koi status change nahi.</p><?php else: ?>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>Change</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($statusChg as $h): ?><tr>
            <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($h['tid']) ?>"><?= e($h['patient_name'] ?: $h['tid']) ?></a></td>
            <td class="small"><?= e($h['from_status']) ?> → <strong><?= e($h['to_status']) ?></strong></td>
            <td class="small"><?= e(date('d-m-y', strtotime($h['changed_at']))) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
