<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'activity';
$page_title = 'ECHS Activity';
$pdo = db();

$per   = 100;
$page  = max(1, (int)($_GET['page'] ?? 1));
$total = (int)$pdo->query("SELECT COUNT(*) n FROM echs_activity")->fetch()['n'];
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per;

$st = $pdo->prepare("SELECT * FROM echs_activity ORDER BY id DESC LIMIT $per OFFSET $offset");
$st->execute();
$rows = $st->fetchAll();
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
    <?php if ($pages > 1): ?>
    <div class="pager" style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($page>1): ?><a class="btn btn-light" href="<?= BASE_URL ?>/echs_activity.php?scheme=ECHS&page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
        <span class="muted small">Page <?= $page ?> / <?= $pages ?> · <?= number_format($total) ?> entries</span>
        <?php if ($page<$pages): ?><a class="btn btn-light" href="<?= BASE_URL ?>/echs_activity.php?scheme=ECHS&page=<?= $page+1 ?>">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
