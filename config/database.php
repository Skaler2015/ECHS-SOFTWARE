<?php
/**
 * Database connection.
 *
 * >>> HOW CREDENTIALS WORK <<<
 * Real DB username/password live in  config/secrets.php  (NOT in git,
 * NOT overwritten by auto-deploy). Copy config/secrets.sample.php to
 * config/secrets.php on your hosting ONCE and fill in your details.
 *
 * If secrets.php is missing, the fallback defaults below are used
 * (handy for local testing).
 */

// Load real credentials if present (created once on the server).
$secrets = __DIR__ . '/secrets.php';
if (is_file($secrets)) {
    require $secrets;
}

// Fallback defaults (used only if secrets.php not found).
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'noble_health');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a shared PDO connection.
 */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            $base = defined('BASE_URL') ? BASE_URL : '';
            die('<div style="font-family:sans-serif;max-width:600px;margin:60px auto;padding:24px;border:1px solid #f5c2c7;background:#f8d7da;border-radius:8px;color:#842029">'
                . '<h2>Database se connection nahi ho paya</h2>'
                . '<p>Kripya <code>config/secrets.php</code> me apni hosting ke database details (naam, user, password) sahi bharein.</p>'
                . '<p>Agar tables nahi bane, to <a href="' . $base . '/install/">install page</a> kholein.</p>'
                . '</div>');
        }
    }
    return $pdo;
}
