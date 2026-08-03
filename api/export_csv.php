<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
$scheme = current_scheme();
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$status = $_GET['status'] ?? '';

$sql = 'SELECT * FROM bills WHERE scheme=? AND bill_date BETWEEN ? AND ?';
$args = [$scheme, $from, $to];
if ($status !== '' && in_array($status, ['Pending','Submitted','Paid','Rejected'])) { $sql .= ' AND status=?'; $args[] = $status; }
$sql .= ' ORDER BY bill_date, id';
$q = db()->prepare($sql); $q->execute($args);
$rows = $q->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $scheme . '_bills_' . $from . '_to_' . $to . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Bill No','Date','Patient','Card No','Doctor','Hospital','Sub Total','Discount','Total','Status']);
foreach ($rows as $b) {
    fputcsv($out, [$b['bill_no'],$b['bill_date'],$b['patient_name'],$b['card_no'],$b['doctor_name'],$b['hospital'],$b['sub_total'],$b['discount'],$b['total_amount'],$b['status']]);
}
fclose($out);
