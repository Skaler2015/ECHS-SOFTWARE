<?php
/**
 * Central key-value store for the ECHS Claims Tracker.
 *
 * The tracker (echs/app.html) talks to:
 *      GET    /api/data/<key>   -> {"value": "<stored string>"} or {"value": null}
 *      POST   /api/data/<key>   -> body = raw string, stored as-is
 *      DELETE /api/data/<key>   -> removes the key
 *
 * Data lives in one MySQL table (echs_kv) so every device/browser sees the
 * same data. Only logged-in staff can read or write (session-protected),
 * because this holds real hospital claim data.
 *
 * This mirrors the little Python server (server.py) the hospital used on a
 * LAN, but backed by the hosting database instead of local files.
 */

require_once __DIR__ . '/../../includes/auth.php';   // starts session, gives is_logged_in()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---- auth: only logged-in users ----
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
}

// ---- resolve key (from rewrite ?key= or PATH_INFO) ----
$raw = $_GET['key'] ?? '';
if ($raw === '' && !empty($_SERVER['PATH_INFO'])) {
    $raw = ltrim($_SERVER['PATH_INFO'], '/');
}
// sanitize: only safe chars, cap length
$key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$raw);
$key = substr($key, 0, 80);
if ($key === '') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_key']);
    exit;
}

$pdo = db();
// ensure store table exists (once)
$pdo->exec("CREATE TABLE IF NOT EXISTS `echs_kv` (
    `kkey` VARCHAR(80) NOT NULL PRIMARY KEY,
    `kval` LONGTEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// daily backup snapshots (one row per key per day, kept for safety/restore)
$pdo->exec("CREATE TABLE IF NOT EXISTS `echs_kv_backup` (
    `kkey` VARCHAR(80) NOT NULL,
    `snap_date` DATE NOT NULL,
    `kval` LONGTEXT NULL,
    `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`kkey`,`snap_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {

    case 'GET':
        $st = $pdo->prepare("SELECT kval FROM echs_kv WHERE kkey = ?");
        $st->execute([$key]);
        $row = $st->fetch();
        echo json_encode(['value' => $row ? $row['kval'] : null]);
        break;

    case 'POST':
    case 'PUT':
        $body = file_get_contents('php://input');
        if ($body === false) $body = '';

        // read current value first (to detect real changes)
        $cur = $pdo->prepare("SELECT kval FROM echs_kv WHERE kkey = ?");
        $cur->execute([$key]);
        $old = $cur->fetch();
        $changed = !$old || (string)$old['kval'] !== $body;

        // save live value
        $st = $pdo->prepare("INSERT INTO echs_kv (kkey, kval) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE kval = VALUES(kval)");
        $st->execute([$key, $body]);

        // automatic safety net: keep one snapshot per key per day for 30 days,
        // so real data can always be recovered even after a bad change.
        if ($changed && $body !== '') {
            try {
                $bk = $pdo->prepare("INSERT INTO echs_kv_backup (kkey, snap_date, kval)
                    VALUES (?, CURDATE(), ?)
                    ON DUPLICATE KEY UPDATE kval = VALUES(kval), saved_at = NOW()");
                $bk->execute([$key, $body]);
                $pdo->prepare("DELETE FROM echs_kv_backup WHERE kkey = ? AND snap_date < (CURDATE() - INTERVAL 30 DAY)")
                    ->execute([$key]);
            } catch (Exception $e) { /* backup is best-effort, never block a save */ }
        }

        echo json_encode(['success' => true, 'bytes' => strlen($body)]);
        break;

    case 'DELETE':
        $pdo->prepare("DELETE FROM echs_kv WHERE kkey = ?")->execute([$key]);
        echo json_encode(['success' => true]);
        break;

    case 'OPTIONS':
        http_response_code(204);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
}
