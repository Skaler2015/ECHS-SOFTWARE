<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$active = '';
$page_title = 'Settings';
$u = current_user();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'org') {
        $fields = ['org_name','org_address','org_phone','org_gstin','org_dl_no'];
        $stmt = db()->prepare('INSERT INTO settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
        foreach ($fields as $f) { $stmt->execute([$f, trim($_POST[$f] ?? '')]); }
        flash('Organisation details save ho gayi.');
        redirect(BASE_URL . '/settings.php');
    }

    if ($act === 'password') {
        $cur = $_POST['current'] ?? '';
        $new = $_POST['new'] ?? '';
        $cf  = $_POST['confirm'] ?? '';
        $q = db()->prepare('SELECT password_hash FROM users WHERE id=?');
        $q->execute([$u['id']]);
        $row = $q->fetch();
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            flash('Current password galat hai.', 'error');
        } elseif (strlen($new) < 6) {
            flash('Naya password kam se kam 6 characters ka ho.', 'error');
        } elseif ($new !== $cf) {
            flash('Naya password aur confirm match nahi karte.', 'error');
        } else {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            flash('Password change ho gaya.');
        }
        redirect(BASE_URL . '/settings.php');
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>⚙️ Settings</h1></div>

<div class="card form">
    <h2>Organisation / Shop Details</h2>
    <p class="muted small">Yeh bill (print) ke upar dikhega.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="org">
        <div class="grid2">
            <div class="fld"><label>Shop / Org Name</label><input name="org_name" value="<?= e(setting('org_name')) ?>"></div>
            <div class="fld"><label>Phone</label><input name="org_phone" value="<?= e(setting('org_phone')) ?>"></div>
            <div class="fld col2"><label>Address</label><input name="org_address" value="<?= e(setting('org_address')) ?>"></div>
            <div class="fld"><label>Drug Licence No.</label><input name="org_dl_no" value="<?= e(setting('org_dl_no')) ?>"></div>
            <div class="fld"><label>GSTIN</label><input name="org_gstin" value="<?= e(setting('org_gstin')) ?>"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Save Details</button></div>
    </form>
</div>

<div class="card form">
    <h2>Change Password</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="password">
        <div class="grid2">
            <div class="fld"><label>Current Password</label><input type="password" name="current" required></div>
            <div class="fld"></div>
            <div class="fld"><label>New Password</label><input type="password" name="new" required></div>
            <div class="fld"><label>Confirm New Password</label><input type="password" name="confirm" required></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Change Password</button></div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
