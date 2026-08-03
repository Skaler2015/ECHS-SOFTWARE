<?php
/**
 * Authentication guard.
 * Include this at the top of every protected page:  require '../includes/auth.php';
 */

require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Is a user logged in? */
function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

/** Current logged in user array */
function current_user() {
    return $_SESSION['user'] ?? null;
}

/** Require login or redirect to login page */
function require_login() {
    if (!is_logged_in()) {
        redirect(BASE_URL . '/login.php');
    }
}

/** Attempt login. Returns true/false */
function attempt_login($username, $password) {
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = [
            'id'        => $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ];
        return true;
    }
    return false;
}

/** Log out */
function logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
