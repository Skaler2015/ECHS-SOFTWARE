<?php
/**
 * Serve a claim-attached document (login-gated). File bytes live under uploads/rghs/,
 * never exposed directly — only reachable through this authenticated proxy.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/rghs.php';
rghs_ensure_table();

$id = (int)($_GET['id'] ?? 0);
$dl = isset($_GET['dl']);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$s = db()->prepare("SELECT stored_name, orig_name, mime, bytes FROM rghs_docs WHERE id=?");
$s->execute([$id]);
$doc = $s->fetch();
if (!$doc) { http_response_code(404); exit('Not found'); }

$dir  = realpath(__DIR__ . '/../uploads/rghs');
$path = $dir ? $dir . '/' . basename($doc['stored_name']) : '';
// guard against traversal: resolved path must sit inside uploads/rghs
if (!$path || strpos(realpath($path) ?: '', $dir) !== 0 || !is_file($path)) {
    http_response_code(404); exit('File missing');
}

$mime = $doc['mime'] ?: 'application/octet-stream';
$disp = $dl ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $doc['orig_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
