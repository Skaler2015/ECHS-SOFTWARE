<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'tasks';
$page_title = 'ECHS Tasks';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $pdo->prepare("INSERT INTO echs_tasks (claim_id,title,due_date) VALUES (?,?,?)")
                ->execute([trim($_POST['claim_id'] ?? '') ?: null, $title, trim($_POST['due_date'] ?? '') ?: null]);
            echs_log('task_add',$title); flash('Task add ho gaya.');
        }
    } elseif ($act === 'done') {
        $pdo->prepare("UPDATE echs_tasks SET done=1 WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($act === 'undone') {
        $pdo->prepare("UPDATE echs_tasks SET done=0 WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($act === 'del') {
        $pdo->prepare("DELETE FROM echs_tasks WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect(BASE_URL.'/echs_tasks.php?scheme=ECHS');
}

$open = $pdo->query("SELECT t.*, c.patient_name FROM echs_tasks t LEFT JOIN echs_claims c ON c.claim_id = t.claim_id COLLATE utf8mb4_unicode_ci
    WHERE t.done=0 ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC")->fetchAll();
$doneList = $pdo->query("SELECT t.*, c.patient_name FROM echs_tasks t LEFT JOIN echs_claims c ON c.claim_id = t.claim_id COLLATE utf8mb4_unicode_ci
    WHERE t.done=1 ORDER BY t.created_at DESC, t.id DESC LIMIT 30")->fetchAll();
$overdue = 0; foreach ($open as $t) if ($t['due_date'] && $t['due_date'] < date('Y-m-d')) $overdue++;

$prefCid = trim($_GET['id'] ?? '');
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>✔ Follow-up Tasks</h1></div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format(count($open)) ?></div><div class="stat-lbl">Open tasks</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($overdue) ?></div><div class="stat-lbl">Overdue</div></div>
</div>

<div class="card form">
    <h2>Naya task</h2>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="grid3">
            <div class="fld"><label>Task *</label><input name="title" required placeholder="e.g. Query ka reply bhejo"></div>
            <div class="fld"><label>Claim ID (optional)</label><input name="claim_id" value="<?= e($prefCid) ?>"></div>
            <div class="fld"><label>Due date</label><input type="date" name="due_date"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">+ Add Task</button></div>
    </form>
</div>

<div class="card">
    <h2>Open (<?= count($open) ?>)</h2>
    <?php if (!$open): ?><p class="muted">Koi pending task nahi 🎉</p><?php else: ?>
    <table class="tbl">
        <thead><tr><th>Task</th><th>Claim</th><th>Due</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($open as $t): $od = $t['due_date'] && $t['due_date'] < date('Y-m-d'); ?>
            <tr>
                <td><?= e($t['title']) ?></td>
                <td class="small"><?php if ($t['claim_id']): ?><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($t['claim_id']) ?>"><?= e($t['patient_name'] ?: $t['claim_id']) ?></a><?php else: ?>-<?php endif; ?></td>
                <td class="small" <?= $od?'style="color:#dc3545;font-weight:600"':'' ?>><?= $t['due_date'] ? e(date('d-m-Y', strtotime($t['due_date']))) : '-' ?><?= $od?' ⚠':'' ?></td>
                <td class="r nowrap">
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="done"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-light">✓ Done</button></form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-x">✕</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($doneList): ?>
<div class="card">
    <h2>Recently done</h2>
    <table class="tbl">
        <tbody>
        <?php foreach ($doneList as $t): ?>
            <tr><td class="muted"><s><?= e($t['title']) ?></s></td><td class="small muted"><?= e($t['patient_name'] ?: $t['claim_id']) ?></td>
                <td class="r"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="undone"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-light small">↺ reopen</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
