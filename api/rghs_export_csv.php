<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/rghs.php';
rghs_ensure_table();

[$where, $args] = rghs_build_filter($_GET);
$pdo = db();
$st = $pdo->prepare("SELECT tid, sub_year, sub_month, enrollment_id, patient_name, gender, card_no, status,
    hospital_name, claim_type, department, admit_date, discharge_date, los, claim_amt, tpa_amt, cu_amt,
    submit_date, tpa_action_date, cu_action_date, query_status, grade, category, doctor_name, mobile, invoice_no
    FROM rghs_claims $where ORDER BY submit_date DESC, tid DESC");
$st->execute($args);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rghs-claims-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, ['TID','Year','Month','Enrollment','Patient','Gender','Card','Status','Hospital','Type','Department',
    'Admission','Discharge','LOS','Claimed','TPA Approved','CU Approved','Submitted','TPA Action','CU Action',
    'Query','Grade','Category','Doctor','Mobile','Invoice']);
foreach ($st as $r) {
    fputcsv($out, [
        $r['tid'], $r['sub_year'], $r['sub_month'], $r['enrollment_id'], $r['patient_name'], $r['gender'], $r['card_no'],
        $r['status'], $r['hospital_name'], $r['claim_type'], $r['department'],
        $r['admit_date'], $r['discharge_date'], $r['los'], $r['claim_amt'], $r['tpa_amt'], $r['cu_amt'],
        $r['submit_date'], $r['tpa_action_date'], $r['cu_action_date'], $r['query_status'], $r['grade'],
        $r['category'], $r['doctor_name'], $r['mobile'], $r['invoice_no'],
    ]);
}
fclose($out);
