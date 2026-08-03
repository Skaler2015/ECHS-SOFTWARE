<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();
$scheme = 'ECHS'; $meta = scheme_meta('ECHS'); $active = 'manage'; $page_title = 'Activity Log';

$rows = db()->query("SELECT * FROM echs_activity ORDER BY id DESC LIMIT 300")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📜 Activity Log</h1></div>
<div class="card">
<?php if (!$rows): ?><p class="muted">Abhi koi activity nahi.</p><?php else: ?>
<table class="tbl">
    <thead><tr><th>Date/Time</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr><td class="nowrap"><?= fdate($r['created_at']) ?> <?= e(date('H:i', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['who']) ?></td><td><span class="pill pill-info"><?= e($r['action']) ?></span></td><td><?= e($r['detail']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
