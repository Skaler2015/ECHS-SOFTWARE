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
 * Parse a smart search string into SQL conditions (for the claims lists).
 *
 * Supported syntax (all tokens are ANDed together):
 *   - plain word            -> matched (LIKE) across every column in $textCols
 *   - "quoted phrase"       -> same, but the whole phrase as one term
 *   - key:value             -> a specific field from $fieldMap
 *                              ($fieldMap[key] = [column,'like'] | [column,'eq'] | [null, callable])
 *   - amt>N / amt<N / >N    -> compare $amountCol (>= / <=)
 *   - age>N / age<N         -> compare $ageExpr (days), e.g. "pending 60+ din"
 *
 * Returns [conds[], args[]] — caller ANDs the conds into its WHERE.
 * All values are bound as placeholders (no SQL injection).
 */
function smart_search($q, array $textCols, array $fieldMap = [], $amountCol = null, $ageExpr = null) {
    $conds = []; $args = [];
    $q = trim((string)$q);
    if ($q === '') return [$conds, $args];

    // tokenize, keeping "quoted phrases" together
    if (!preg_match_all('/"([^"]+)"|(\S+)/', $q, $m, PREG_SET_ORDER)) return [$conds, $args];

    foreach ($m as $tk) {
        $tok = ($tk[1] !== '') ? $tk[1] : ($tk[2] ?? '');
        $tok = trim($tok);
        if ($tok === '') continue;

        // key:value operator
        if (preg_match('/^([a-zA-Z]+):(.*)$/', $tok, $mm) && isset($fieldMap[strtolower($mm[1])])) {
            $val = trim($mm[2], '"');
            if ($val === '') continue;
            [$col, $mode] = $fieldMap[strtolower($mm[1])];
            if (is_callable($mode)) {
                [$c, $a] = $mode($val);
                if ($c) { $conds[] = $c; foreach ((array)$a as $x) $args[] = $x; }
            } elseif ($mode === 'eq') {
                $conds[] = "$col = ?"; $args[] = $val;
            } else {
                $conds[] = "$col LIKE ?"; $args[] = "%$val%";
            }
            continue;
        }

        // amount:  amt>N / amt<N / amt>=N / >N / <N
        if ($amountCol && preg_match('/^(?:amt)?([<>])(=?)(\d+(?:\.\d+)?)$/i', $tok, $mm)) {
            $conds[] = "$amountCol " . $mm[1] . $mm[2] . " ?";
            $args[] = (float)$mm[3];
            continue;
        }

        // age:  age>N / age<N / age>=N   (days since $ageExpr's date)
        if ($ageExpr && preg_match('/^age([<>])(=?)(\d+)$/i', $tok, $mm)) {
            $conds[] = "($ageExpr) " . $mm[1] . $mm[2] . " ?";
            $args[] = (int)$mm[3];
            continue;
        }

        // plain term -> OR across all text columns
        if ($textCols) {
            $ors = [];
            foreach ($textCols as $c) { $ors[] = "$c LIKE ?"; $args[] = "%$tok%"; }
            $conds[] = '(' . implode(' OR ', $ors) . ')';
        }
    }
    return [$conds, $args];
}

/** Standard query/objection reason tags (shared by RGHS + ECHS query panels). */
function query_reasons() {
    return ['Document missing','Amount mismatch','Eligibility','Signature/Stamp','Package/Rate','Discharge/Bill','Other'];
}
/** Query priorities. */
function query_priorities() { return ['high'=>'High','medium'=>'Medium','low'=>'Low']; }

/**
 * Generate the next bill number for a scheme, e.g. RGHS-0001.
 */
function next_bill_no($scheme) {
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM bills WHERE scheme = ?');
    $stmt->execute([$scheme]);
    $count = (int)$stmt->fetch()['c'] + 1;
    return $scheme . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}
