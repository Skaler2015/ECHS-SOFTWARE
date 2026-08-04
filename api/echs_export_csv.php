<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/echs.php';
echs_ensure_table();

list($wsql, $args) = echs_build_filter($_GET);
$st = db()->prepare("SELECT claim_id,region,hospital_name,card_id,esm_name,patient_name,patient_type,admit_type,
    accept_date_raw,net_claim_amt,approved_amt,(net_claim_amt-approved_amt) AS deduction,
    echs_disc,tds_amt,bpa_fees,amt_credited,settlement_id,settle_date,status,processed_on_raw,nmi_date,nmi_remarks
    FROM echs_claims $wsql ORDER BY accept_date DESC, claim_id DESC");
$st->execute($args);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="echs_claims_' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Claim ID','Region','Hospital','Card ID','Name of ESM','Patient','Type','Admit','Accept Date',
    'Net Claim Amt','Approved Amt','Deduction','ECHS Disc','TDS','BPA Fees','Amt Credited','Settlement ID','Settle Date',
    'Status','Processed On','NMI Date','NMI Remarks']);
while ($r = $st->fetch()) {
    fputcsv($out, [$r['claim_id'],$r['region'],$r['hospital_name'],$r['card_id'],$r['esm_name'],$r['patient_name'],
        $r['patient_type'],$r['admit_type'],$r['accept_date_raw'],$r['net_claim_amt'],$r['approved_amt'],$r['deduction'],
        $r['echs_disc'],$r['tds_amt'],$r['bpa_fees'],$r['amt_credited'],$r['settlement_id'],$r['settle_date'],
        $r['status'],$r['processed_on_raw'],$r['nmi_date'],$r['nmi_remarks']]);
}
fclose($out);
