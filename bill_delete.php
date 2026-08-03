<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    db()->prepare('DELETE FROM bills WHERE id=? AND scheme=?')->execute([$id, $scheme]);
    flash('Bill delete ho gaya.');
}
redirect(BASE_URL . '/bills.php?scheme=' . $scheme);
