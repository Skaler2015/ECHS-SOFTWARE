<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/echs.php';
echs_ensure_table();
if (!echs_is_admin()) { http_response_code(403); die('Admin only'); }

$data = ['generated'=>date('c'), 'tables'=>[]];
foreach (['echs_claims','echs_payments','echs_tasks','echs_docs','echs_contacts','echs_claim_history','echs_uploads'] as $t) {
    $rows = [];
    foreach (db()->query("SELECT * FROM `$t`") as $r) { $rows[] = $r; }
    $data['tables'][$t] = $rows;
}
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="echs_backup_' . date('Y-m-d_His') . '.json"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
