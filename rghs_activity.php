<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'activity';
$page_title = 'RGHS Activity';
$pdo = db();

$rows = $pdo->query("SELECT * FROM rghs_activity ORDER BY id DESC LIMIT 300")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>📋 Activity Log</h1></div>
<div class="card">
    <p class="muted small">Kisne kab kya kiya — upload, doctor change, bulk action, backup/restore.</p>
    <?php if (!$rows): ?><p class="muted">Abhi koi activity nahi.</p><?php else: ?>
    <div class="tbl-scroll">
    <table class="tbl">
        <thead><tr><th>Time</th><th>Kisne</th><th>Action</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="small nowrap"><?= e(date('d-m-y H:i', strtotime($r['created_at']))) ?></td>
                <td class="small"><?= e($r['who']) ?></td>
                <td><span class="pill pill-info"><?= e($r['action']) ?></span></td>
                <td class="small"><?= e($r['detail']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
