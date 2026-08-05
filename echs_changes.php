<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'changes';
$page_title = 'Kya Badla';
$pdo = db();

$days = (int)($_GET['days'] ?? 7); if ($days < 1 || $days > 120) $days = 7;

// status changes in the window
$statusChg = $pdo->prepare("SELECT h.claim_id, h.from_status, h.to_status, h.changed_at, c.patient_name, c.claim_amt
    FROM echs_claim_history h LEFT JOIN echs_claims c ON c.claim_id = h.claim_id COLLATE utf8mb4_unicode_ci
    WHERE h.from_status IS NOT NULL AND h.changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY h.changed_at DESC LIMIT 300");
$statusChg->execute([$days]); $statusChg = $statusChg->fetchAll();

// newly settled (processed_on inside window, category settled)
$newSettled = $pdo->prepare("SELECT claim_id, patient_name, approved_amt, processed_on
    FROM echs_claims WHERE category='settled' AND processed_on IS NOT NULL AND processed_on >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY processed_on DESC LIMIT 200");
$newSettled->execute([$days]); $newSettled = $newSettled->fetchAll();

// newly rejected (processed_on inside window, category rejected)
$newRejected = $pdo->prepare("SELECT claim_id, patient_name, claim_amt, processed_on
    FROM echs_claims WHERE category='rejected' AND processed_on IS NOT NULL AND processed_on >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY processed_on DESC LIMIT 200");
$newRejected->execute([$days]); $newRejected = $newRejected->fetchAll();

// newly seen (imported first time in window)
$newClaims = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM echs_claims WHERE first_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)");
$newClaims->execute([$days]); $nc = $newClaims->fetch();

// count summary from status changes
$becameSettled = 0; $becameRejected = 0; $becameQuery = 0;
foreach ($statusChg as $h) { $cat=echs_category($h['to_status']); if($cat==='settled')$becameSettled++; elseif($cat==='rejected'||$cat==='cancelled')$becameRejected++; elseif($cat==='query')$becameQuery++; }

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🔔 Kya Badla</h1>
    <div class="page-actions">
        <?php foreach ([7,14,30,60] as $d): ?>
            <a class="btn <?= $days===$d?'btn-primary':'' ?>" href="<?= BASE_URL ?>/echs_changes.php?scheme=ECHS&days=<?= $d ?>"><?= $d ?> din</a>
        <?php endforeach; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($nc['n']) ?></div><div class="stat-lbl">Naye claims (<?= $days ?> din)</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= number_format(count($newSettled)) ?></div><div class="stat-lbl">Settle hue</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format(count($newRejected)) ?></div><div class="stat-lbl">Reject hue</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format(count($statusChg)) ?></div><div class="stat-lbl">Status changes</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>✅ Naye settled (<?= count($newSettled) ?>)</h2>
        <?php if (!$newSettled): ?><p class="muted">Is period me koi settle nahi.</p><?php else: ?>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>Date</th><th class="r">Approved</th></tr></thead><tbody>
        <?php foreach ($newSettled as $p): ?><tr>
            <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($p['claim_id']) ?>"><?= e($p['patient_name'] ?: $p['claim_id']) ?></a></td>
            <td class="small"><?= e(date('d-m-y', strtotime($p['processed_on']))) ?></td>
            <td class="r"><?= inr($p['approved_amt'],0) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>❌ Naye rejected (<?= count($newRejected) ?>)</h2>
        <?php if (!$newRejected): ?><p class="muted">Is period me koi reject nahi.</p><?php else: ?>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>Date</th><th class="r">Claimed</th></tr></thead><tbody>
        <?php foreach ($newRejected as $p): ?><tr>
            <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($p['claim_id']) ?>"><?= e($p['patient_name'] ?: $p['claim_id']) ?></a></td>
            <td class="small"><?= e(date('d-m-y', strtotime($p['processed_on']))) ?></td>
            <td class="r"><?= inr($p['claim_amt'],0) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>🔁 Status changes (<?= count($statusChg) ?>)</h2>
        <?php if (!$statusChg): ?><p class="muted">Koi status change nahi.</p><?php else: ?>
        <div class="tbl-scroll"><table class="tbl"><thead><tr><th>Patient</th><th>Change</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($statusChg as $h): ?><tr>
            <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($h['claim_id']) ?>"><?= e($h['patient_name'] ?: $h['claim_id']) ?></a></td>
            <td class="small"><?= e($h['from_status']) ?> → <strong><?= e($h['to_status']) ?></strong></td>
            <td class="small"><?= e(date('d-m-y', strtotime($h['changed_at']))) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
