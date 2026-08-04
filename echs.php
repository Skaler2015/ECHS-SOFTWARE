<?php
/**
 * ECHS Claims Tracker — Noble Care Hospital.
 *
 * This is the standalone tracker app (single-page HTML) that the hospital
 * shared. It stores all its data centrally through the /api/data/ backend
 * (see api/data/index.php), so every device sees the same data.
 *
 * We serve it through PHP so that only logged-in staff can open it — the
 * same login that protects the rest of the software.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$file = __DIR__ . '/echs/app.html';
if (!is_file($file)) {
    http_response_code(500);
    exit('ECHS app file (echs/app.html) nahi mili.');
}

header('Content-Type: text/html; charset=utf-8');
// no-cache so app updates reach users immediately after a deploy
header('Cache-Control: no-store, must-revalidate');
readfile($file);
