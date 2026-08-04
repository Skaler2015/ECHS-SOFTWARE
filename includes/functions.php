<?php
/**
 * Shared helper functions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/** Escape output for HTML */
function e($v) {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect helper */
function redirect($path) {
    header('Location: ' . $path);
    exit;
}

/** Indian-style number grouping: 12345678.5 -> 1,23,45,678.50 */
function inr($n, $dec = 2) {
    $n = (float)$n;
    $neg = $n < 0;
    $n = abs($n);
    $s = number_format($n, $dec, '.', '');
    $parts = explode('.', $s);
    $int = $parts[0];
    $frac = isset($parts[1]) ? '.' . $parts[1] : '';
    $last3 = substr($int, -3);
    $rest = substr($int, 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $last3 = ',' . $last3;
    }
    return ($neg ? '-' : '') . $rest . $last3 . $frac;
}

/** Format money in Indian style, e.g. ₹1,23,45,678.50 */
function money($n) {
    return '₹' . inr($n, 2);
}

/** Format a date nicely (dd-mm-yyyy) */
function fdate($d) {
    if (!$d || $d === '0000-00-00') return '-';
    $ts = strtotime($d);
    return $ts ? date('d-m-Y', $ts) : e($d);
}

/** Return validated scheme code from request, default RGHS */
function current_scheme() {
    global $SCHEMES;
    $s = strtoupper($_GET['scheme'] ?? $_POST['scheme'] ?? 'RGHS');
    return isset($SCHEMES[$s]) ? $s : 'RGHS';
}

/** Get scheme meta array */
function scheme_meta($code = null) {
    global $SCHEMES;
    $code = $code ?: current_scheme();
    return $SCHEMES[$code] ?? $SCHEMES['RGHS'];
}

/** Read a setting value */
function setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (Exception $e) { /* table may not exist yet */ }
    }
    return $cache[$key] ?? $default;
}

/** CSRF token */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_check() {
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        die('Invalid form token. Please go back and try again.');
    }
}

/** Flash messages */
function flash($msg = null, $type = 'success') {
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return;
    }
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Generate the next bill number for a scheme, e.g. RGHS-0001.
 */
function next_bill_no($scheme) {
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM bills WHERE scheme = ?');
    $stmt->execute([$scheme]);
    $count = (int)$stmt->fetch()['c'] + 1;
    return $scheme . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}
