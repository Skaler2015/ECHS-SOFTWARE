<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();
$scheme = 'ECHS'; $meta = scheme_meta('ECHS'); $active = 'manage'; $page_title = 'Recent Changes';

$days = max(1, (int)($_GET['days'] ?? 30));
$st = db()->prepare("SELECT h.*, c.patient_name, c.net_claim_amt FROM echs_claim_history h
    LEFT JOIN echs_claims c ON c.claim_id = h.claim_id COLLATE utf8mb4_unicode_ci
    WHERE h.changed_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND h.from_status IS NOT NULL
    ORDER BY h.id DESC LIMIT 500");
$st->execute([$days]);
$rows = $st->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🔄 Recent Status Changes</h1>
    <div class="page-actions">
        <?php foreach ([7,30,90,365] as $d): ?>
            <a class="btn <?= $days==$d?'btn-primary':'' ?>" href="?scheme=ECHS&days=<?= $d ?>"><?= $d ?> din</a>
        <?php endforeach; ?>
    </div>
</div>
<div class="card">
    <p class="muted small">Pichhle <?= $days ?> din me jin claims ka status badla (har naya upload track karta hai).</p>
    <?php if (!$rows): ?><p class="muted">Is avdhi me koi badlaav nahi.</p><?php else: ?>
    <table class="tbl">
        <thead><tr><th>Date</th><th>Claim</th><th>Patient</th><th>Purana</th><th>Naya</th><th class="r">Net</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= fdate($r['changed_at']) ?></td>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($r['claim_id']) ?>"><?= e($r['claim_id']) ?></a></td>
                <td><?= e($r['patient_name']) ?></td>
                <td><span class="pill pill-<?= echs_category($r['from_status']) ?>"><?= e($r['from_status']) ?></span></td>
                <td><span class="pill pill-<?= echs_category($r['to_status']) ?>"><?= e($r['to_status']) ?></span></td>
                <td class="r"><?= inr($r['net_claim_amt'],0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
