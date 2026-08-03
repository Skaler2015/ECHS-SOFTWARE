<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$d = db()->prepare("SELECT * FROM echs_docs WHERE id=?");
$d->execute([$id]);
$doc = $d->fetch();
if (!$doc) { http_response_code(404); die('Not found'); }

$path = __DIR__ . '/../uploads/echs/' . basename($doc['stored_name']);
if (!is_file($path)) { http_response_code(404); die('File missing'); }

$ext = strtolower(pathinfo($doc['orig_name'], PATHINFO_EXTENSION));
$types = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
          'gif'=>'image/gif','webp'=>'image/webp'];
$mime = $types[$ext] ?? 'application/octet-stream';
$inline = isset($types[$ext]) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $inline . '; filename="' . basename($doc['orig_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
