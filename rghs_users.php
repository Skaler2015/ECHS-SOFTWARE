<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'users';
$page_title = 'Staff Users';
$pdo = db();

$u = current_user();
$isAdmin = (($u['role'] ?? '') === 'admin');
if (!$isAdmin) {
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><h2>Sirf admin</h2><p class="muted">Ye page sirf admin ke liye hai.</p></div>';
    require __DIR__ . '/includes/footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'add') {
            $un = trim($_POST['username'] ?? ''); $fn = trim($_POST['full_name'] ?? '');
            $pw = $_POST['password'] ?? ''; $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            if ($un !== '' && $pw !== '') {
                $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role, is_active) VALUES (?,?,?,?,1)")
                    ->execute([$un, $fn ?: $un, password_hash($pw, PASSWORD_DEFAULT), $role]);
                rghs_log('user_add', $un.' ('.$role.')');
                flash("User '$un' ban gaya.");
            } else flash('Username aur password zaroori.', 'error');
        } elseif ($act === 'role') {
            $id = (int)$_POST['id']; $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $id]);
            flash('Role update ho gaya.');
        } elseif ($act === 'toggle') {
            $id = (int)$_POST['id'];
            if ($id === (int)($u['id'] ?? 0)) flash('Apne aap ko disable nahi kar sakte.', 'error');
            else { $pdo->prepare("UPDATE users SET is_active = 1-is_active WHERE id=?")->execute([$id]); flash('Status badla.'); }
        } elseif ($act === 'reset') {
            $id = (int)$_POST['id']; $pw = $_POST['password'] ?? '';
            if ($pw !== '') { $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]); flash('Password reset ho gaya.'); }
        }
    } catch (Exception $e) { flash('Error: '.$e->getMessage(), 'error'); }
    redirect(BASE_URL.'/rghs_users.php?scheme=RGHS');
}

$users = $pdo->query("SELECT id, username, full_name, role, is_active FROM users ORDER BY id")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>👥 Staff Users</h1></div>

<div class="card form">
    <h2>Naya user add karein</h2>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add">
        <div class="grid3">
            <div class="fld"><label>Username *</label><input name="username" required></div>
            <div class="fld"><label>Pura naam</label><input name="full_name"></div>
            <div class="fld"><label>Password *</label><input name="password" type="text" required></div>
        </div>
        <div class="fld"><label>Role</label>
            <select name="role"><option value="staff">Staff (view + normal work)</option><option value="admin">Admin (sab kuch)</option></select>
        </div>
        <div class="form-actions"><button class="btn btn-primary">+ Add User</button></div>
    </form>
    <p class="muted small">Staff normal kaam kar sakta hai; sirf <strong>admin</strong> hi restore/backup, users, aur doctor-delete jaise sensitive kaam kar sakta hai.</p>
</div>

<div class="card">
    <h2>All users</h2>
    <table class="tbl">
        <thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $usr): ?>
            <tr>
                <td><strong><?= e($usr['full_name'] ?: $usr['username']) ?></strong><div class="muted small"><?= e($usr['username']) ?></div></td>
                <td>
                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="role"><input type="hidden" name="id" value="<?= $usr['id'] ?>">
                        <select name="role" onchange="this.form.submit()">
                            <option value="staff" <?= $usr['role']==='staff'?'selected':'' ?>>Staff</option>
                            <option value="admin" <?= $usr['role']==='admin'?'selected':'' ?>>Admin</option>
                        </select>
                    </form>
                </td>
                <td><span class="pill <?= $usr['is_active']?'pill-settled':'pill-rejected' ?>"><?= $usr['is_active']?'Active':'Disabled' ?></span></td>
                <td class="r nowrap">
                    <form method="post" style="display:inline" onsubmit="return confirm('Status badlein?')"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= $usr['id'] ?>"><button class="btn btn-light"><?= $usr['is_active']?'Disable':'Enable' ?></button></form>
                    <a class="link" href="#" onclick="reset(<?= $usr['id'] ?>);return false;">Reset password</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<form method="post" id="resetForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="act" value="reset"><input type="hidden" name="id" id="rs_id"><input type="hidden" name="password" id="rs_pw"></form>
<script>
function reset(id){ var pw=prompt("Naya password:"); if(pw&&pw.trim()){ document.getElementById('rs_id').value=id; document.getElementById('rs_pw').value=pw.trim(); document.getElementById('resetForm').submit(); } }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
