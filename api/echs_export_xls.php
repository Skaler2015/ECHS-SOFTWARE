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

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="echs_claims_' . date('Y-m-d') . '.xls"');
function x($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"utf-8\"></head><body><table border=1>";
$cols = ['Claim ID','Region','Hospital','Card ID','Name of ESM','Patient','Type','Admit','Accept Date',
    'Net Claim Amt','Approved Amt','Deduction','ECHS Disc','TDS','BPA Fees','Amt Credited','Settlement ID','Settle Date',
    'Status','Processed On','NMI Date','NMI Remarks'];
echo '<tr>'; foreach($cols as $c) echo '<th>'.x($c).'</th>'; echo '</tr>';
while ($r = $st->fetch()) {
    echo '<tr>';
    echo '<td>'.x($r['claim_id']).'</td><td>'.x($r['region']).'</td><td>'.x($r['hospital_name']).'</td>';
    echo '<td>="'.x($r['card_id']).'"</td><td>'.x($r['esm_name']).'</td><td>'.x($r['patient_name']).'</td>';
    echo '<td>'.x($r['patient_type']).'</td><td>'.x($r['admit_type']).'</td><td>'.x($r['accept_date_raw']).'</td>';
    echo '<td>'.x($r['net_claim_amt']).'</td><td>'.x($r['approved_amt']).'</td><td>'.x($r['deduction']).'</td>';
    echo '<td>'.x($r['echs_disc']).'</td><td>'.x($r['tds_amt']).'</td><td>'.x($r['bpa_fees']).'</td><td>'.x($r['amt_credited']).'</td>';
    echo '<td>'.x($r['settlement_id']).'</td><td>'.x($r['settle_date']).'</td>';
    echo '<td>'.x($r['status']).'</td><td>'.x($r['processed_on_raw']).'</td><td>'.x($r['nmi_date']).'</td><td>'.x($r['nmi_remarks']).'</td>';
    echo '</tr>';
}
echo '</table></body></html>';
