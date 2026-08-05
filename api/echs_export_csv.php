<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/echs.php';
echs_ensure_table();

[$where, $args] = echs_build_filter($_GET);
$pdo = db();
$st = $pdo->prepare("SELECT claim_id, region, hospital_name, card_id, esm_name, patient_name, patient_type, admit_type,
    accept_date, claim_amt, approved_amt, processed_on, status, category, doctor_name, assigned_to
    FROM echs_claims $where ORDER BY accept_date DESC, claim_id DESC");
$st->execute($args);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="echs_claims_'.date('Ymd_His').'.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
fputcsv($out, ['Claim ID','Region','Hospital','Card ID','ESM Name','Patient Name','Type','Admit','Accept Date','Net Claim Amt','Approved Amt','Processed On','Status','Category','Doctor','Assigned To']);
foreach ($st as $r) {
    $r['patient_type'] = echs_ptype($r['patient_type']);
    $r['admit_type']   = echs_atype($r['admit_type']);
    fputcsv($out, $r);
}
fclose($out);
