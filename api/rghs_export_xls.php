<?php
/** RGHS claims -> Excel-openable file (HTML table with .xls; Excel opens it natively). */
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/rghs.php';
rghs_ensure_table();

[$where, $args] = rghs_build_filter($_GET);
$pdo = db();
$st = $pdo->prepare("SELECT tid, sub_year, sub_month, enrollment_id, patient_name, gender, card_no, status,
    hospital_name, claim_type, department, admit_date, discharge_date, los, claim_amt, tpa_amt, cu_amt,
    paid_amount, payment_status, utr, payment_date, tds_paid,
    submit_date, cu_action_date, query_status, grade, category, doctor_name, mobile
    FROM rghs_claims $where ORDER BY submit_date DESC, tid DESC");
$st->execute($args);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="rghs-claims-' . date('Y-m-d') . '.xls"');
echo "\xEF\xBB\xBF";
$cols = ['TID','Year','Month','Enrollment','Patient','Gender','Card','Status','Hospital','Type','Department',
    'Admission','Discharge','LOS','Claimed','TPA Approved','CU Approved','Paid','Payment Status','UTR','Payment Date','TDS',
    'Submitted','CU Action','Query','Grade','Category','Doctor','Mobile'];
echo '<table border="1"><thead><tr>';
foreach ($cols as $c) echo '<th>' . htmlspecialchars($c) . '</th>';
echo '</tr></thead><tbody>';
foreach ($st as $r) {
    echo '<tr>';
    foreach ($r as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
