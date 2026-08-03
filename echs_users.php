<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'manage';
$page_title = 'Users';

if (!echs_is_admin()) { flash('Sirf admin users manage kar sakta hai.', 'error'); redirect(BASE_URL . '/echs_manage.php?scheme=ECHS'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'add') {
        $un = trim($_POST['username'] ?? '');
        $nm = trim($_POST['full_name'] ?? '');
        $pw = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
        if ($un !== '' && strlen($pw) >= 6) {
            try {
                db()->prepare("INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)")
                    ->execute([$un, password_hash($pw, PASSWORD_DEFAULT), $nm ?: $un, $role]);
                echs_log('user_add', $un . ' (' . $role . ')');
                flash('User add ho gaya.');
            } catch (Exception $e) { flash('Username pehle se hai.', 'error'); }
        } else { flash('Username aur 6+ char ka password zaroori.', 'error'); }
    } elseif ($act === 'toggle') {
        db()->prepare("UPDATE users SET is_active=1-is_active WHERE id=? AND username<>?")->execute([(int)$_POST['id'], current_user()['username']]);
    } elseif ($act === 'reset') {
        $pw = $_POST['password'] ?? '';
        if (strlen($pw) >= 6) { db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$_POST['id']]); flash('Password reset ho gaya.'); }
    } elseif ($act === 'del') {
        db()->prepare("DELETE FROM users WHERE id=? AND username<>?")->execute([(int)$_POST['id'], current_user()['username']]);
        flash('User delete ho gaya.');
    }
    redirect(BASE_URL . '/echs_users.php?scheme=ECHS');
}

$users = db()->query("SELECT id,username,full_name,role,is_active,created_at FROM users ORDER BY id")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>👥 Staff Users</h1></div>

<div class="card form">
    <h2>Naya user add karein</h2>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="grid4">
            <div class="fld"><label>Username *</label><input name="username" required></div>
            <div class="fld"><label>Naam</label><input name="full_name"></div>
            <div class="fld"><label>Password *</label><input name="password" required></div>
            <div class="fld"><label>Role</label><select name="role"><option value="staff">Staff (limited)</option><option value="admin">Admin (full)</option></select></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">+ Add User</button></div>
    </form>
    <p class="muted small">Staff: dekh aur entry kar sakta hai. Admin: users + data delete bhi kar sakta hai.</p>
</div>

<div class="card">
    <table class="tbl">
        <thead><tr><th>User</th><th>Naam</th><th>Role</th><th>Active</th><th>Reset Password</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td><?= e($u['full_name']) ?></td>
                <td><span class="pill pill-<?= $u['role']==='admin'?'settled':'info' ?>"><?= e($u['role']) ?></span></td>
                <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-light" style="padding:4px 10px"><?= $u['is_active']?'✅ Yes':'❌ No' ?></button></form></td>
                <td><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input name="password" placeholder="naya password" style="padding:5px;border:1px solid var(--line);border-radius:6px"><button class="btn btn-light" style="padding:4px 10px">Set</button></form></td>
                <td class="r"><?php if ($u['username']!==current_user()['username']): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete user?')"><?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn-x">✕</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
