<?php
/**
 * One-click installer.
 * Open  https://noble.subhashkaler.com/install/  in your browser.
 * It creates all tables and your admin login.
 * DELETE this /install/ folder after setup for security.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$done = false; $error = ''; $step = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_user = trim($_POST['admin_user'] ?? '');
    $admin_name = trim($_POST['admin_name'] ?? '');
    $admin_pass = $_POST['admin_pass'] ?? '';

    if ($admin_user === '' || strlen($admin_pass) < 6) {
        $error = 'Username bharein aur password kam se kam 6 characters ka rakhein.';
    } else {
        try {
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            // run statements
            $pdo = db();
            $pdo->exec($sql);

            // create / update admin
            $chk = $pdo->prepare('SELECT id FROM users WHERE username=?');
            $chk->execute([$admin_user]);
            $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
            if ($row = $chk->fetch()) {
                $pdo->prepare('UPDATE users SET password_hash=?, full_name=?, is_active=1 WHERE id=?')
                    ->execute([$hash, $admin_name ?: $admin_user, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)')
                    ->execute([$admin_user, $hash, $admin_name ?: $admin_user, 'admin']);
            }
            $done = true; $step = 'done';
        } catch (Exception $e) {
            $error = 'Setup me error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
<div class="login-card" style="max-width:440px">
    <div class="login-logo">◈</div>
    <h1>Setup — <?= e(APP_NAME) ?></h1>
    <p class="login-sub">Database tables + admin login banayein</p>

    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($done): ?>
        <div class="flash flash-success">
            ✅ Setup complete! Tables ban gaye aur admin login ready hai.
        </div>
        <p style="text-align:left"><strong>Ab yeh zaroor karein:</strong></p>
        <ol style="text-align:left;font-size:.9rem;color:#444">
            <li>Security ke liye <code>/install/</code> folder <strong>delete</strong> kar dein.</li>
            <li>Phir login karein.</li>
        </ol>
        <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/login.php">Login page kholें →</a>
    <?php else: ?>
        <form method="post" style="text-align:left">
            <label>Admin Username</label>
            <input name="admin_user" value="<?= e($_POST['admin_user'] ?? 'admin') ?>" required>
            <label>Aapka Naam</label>
            <input name="admin_name" value="<?= e($_POST['admin_name'] ?? 'Subhash Kaler') ?>">
            <label>Password (kam se kam 6 char)</label>
            <input type="password" name="admin_pass" required>
            <button class="btn btn-primary btn-block" style="margin-top:16px">Install Now</button>
        </form>
        <p class="login-foot">Pehle <code>config/database.php</code> me DB details bharna zaroori hai.</p>
    <?php endif; ?>
</div>
</body>
</html>
