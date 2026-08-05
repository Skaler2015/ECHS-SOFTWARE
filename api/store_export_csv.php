<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/store.php';
store_ensure_table();

[$where, $args] = store_build_filter($_GET);
$pdo = db();
$st = $pdo->prepare("SELECT invoice_no, tid, sub_year, sub_month, patient_name, gender, card_no, status, category,
    claim_amt, tpa_amt, cu_amt, submit_date, mobile
    FROM store_claims $where ORDER BY submit_date DESC, invoice_no DESC");
$st->execute($args);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="store_claims_'.date('Ymd_His').'.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, ['Invoice No','TID','Sub Year','Sub Month','Patient Name','Gender','Card No','Status','Category',
    'Pharmacy Claim Amt','TPA Approved Amt','CU Approved Amt','Submit Date','Mobile']);
foreach ($st as $r) {
    fputcsv($out, $r);
}
fclose($out);
