<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'tasks';
$page_title = 'ECHS Tasks';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        db()->prepare("INSERT INTO echs_tasks (claim_id,title,due_date) VALUES (?,?,?)")
           ->execute([trim($_POST['claim_id']??'')?:null, trim($_POST['title']??''), ($_POST['due_date']??'')?:null]);
        flash('Task add ho gaya.');
    } elseif ($act === 'toggle') {
        $tid = (int)$_POST['id'];
        db()->prepare("UPDATE echs_tasks SET done=1-done, done_at=IF(done=0,NOW(),NULL) WHERE id=?")->execute([$tid]);
    } elseif ($act === 'del') {
        db()->prepare("DELETE FROM echs_tasks WHERE id=?")->execute([(int)$_POST['id']]);
    }
    redirect(BASE_URL . '/echs_tasks.php?scheme=ECHS');
}

$open = db()->query("SELECT * FROM echs_tasks WHERE done=0 ORDER BY (due_date IS NULL), due_date ASC, id DESC")->fetchAll();
$doneRows = db()->query("SELECT * FROM echs_tasks WHERE done=1 ORDER BY done_at DESC LIMIT 50")->fetchAll();
$today = date('Y-m-d');

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>✅ Follow-up Tasks</h1></div>

<div class="card form">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="grid3">
            <div class="fld col2"><label>Task *</label><input name="title" required placeholder="jaise: 30171295 par polyclinic ko phone karo"></div>
            <div class="fld"><label>Due Date</label><input type="date" name="due_date"></div>
            <div class="fld"><label>Claim ID (optional)</label><input name="claim_id"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">+ Add Task</button></div>
    </form>
</div>

<div class="card">
    <h2>Baaki Tasks (<?= count($open) ?>)</h2>
    <?php if (!$open): ?><p class="muted">Koi pending task nahi. 🎉</p><?php else: ?>
    <table class="tbl">
        <thead><tr><th></th><th>Task</th><th>Claim</th><th>Due</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($open as $t): $overdue = $t['due_date'] && $t['due_date'] < $today; ?>
            <tr class="<?= $overdue?'row-overdue':'' ?>">
                <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-x" style="background:#dcfce7;color:#166534" title="Done">✓</button></form></td>
                <td><?= e($t['title']) ?></td>
                <td><?php if($t['claim_id']):?><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($t['claim_id']) ?>"><?= e($t['claim_id']) ?></a><?php else:?>-<?php endif;?></td>
                <td class="nowrap"><?= $t['due_date']?fdate($t['due_date']):'-' ?><?= $overdue?' <span class="pill pill-rejected">Overdue</span>':'' ?></td>
                <td class="r"><form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-x">✕</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($doneRows): ?>
<div class="card">
    <h2>Ho chuke Tasks</h2>
    <table class="tbl">
        <thead><tr><th></th><th>Task</th><th>Done on</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($doneRows as $t): ?>
            <tr class="muted">
                <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-x" title="Undo">↺</button></form></td>
                <td style="text-decoration:line-through"><?= e($t['title']) ?></td>
                <td><?= $t['done_at']?fdate($t['done_at']):'-' ?></td>
                <td class="r"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-x">✕</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
