<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(BASE_URL . '/home.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if (attempt_login($username, $password)) {
        redirect(BASE_URL . '/home.php');
    }
    $error = 'Username ya password galat hai.';
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-logo">◈</div>
        <h1><?= e(APP_NAME) ?></h1>
        <p class="login-sub">RGHS + ECHS Claims Management</p>

        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <label>Username</label>
            <input type="text" name="username" required autofocus>
            <label>Password</label>
            <input type="password" name="password" required>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
        <p class="login-foot">Sirf authorized user ke liye · <?= e(APP_OWNER) ?></p>
    </div>
</body>
</html>
