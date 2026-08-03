<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/echs.php';
echs_ensure_table();

// filters (same as claims list)
$q=trim($_GET['q']??''); $status=trim($_GET['status']??''); $ptype=trim($_GET['ptype']??'');
$from=trim($_GET['from']??''); $to=trim($_GET['to']??'');
$where=[];$args=[];
if($q!==''){$where[]='(claim_id LIKE ? OR card_id LIKE ? OR esm_name LIKE ? OR patient_name LIKE ?)';$l="%$q%";array_push($args,$l,$l,$l,$l);}
if($status!==''){$where[]='status=?';$args[]=$status;}
if($ptype!==''){$where[]='patient_type=?';$args[]=$ptype;}
if($from!==''){$where[]='accept_date>=?';$args[]=$from;}
if($to!==''){$where[]='accept_date<=?';$args[]=$to;}
$wsql=$where?('WHERE '.implode(' AND ',$where)):'';
$st=db()->prepare("SELECT claim_id,region,hospital_name,card_id,esm_name,patient_name,patient_type,admit_type,accept_date_raw,net_claim_amt,approved_amt,status,processed_on_raw FROM echs_claims $wsql ORDER BY accept_date DESC, claim_id DESC");
$st->execute($args);

// SpreadsheetML (Excel opens this .xls cleanly, keeps text formatting)
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="echs_claims_' . date('Y-m-d') . '.xls"');
function x($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"utf-8\"></head><body><table border=1>";
$cols = ['Claim ID','Region','Hospital','Card ID','Name of ESM','Patient','Type','Admit','Accept Date','Net Claim Amt','Approved Amt','Status','Processed On'];
echo '<tr>'; foreach($cols as $c) echo '<th>'.x($c).'</th>'; echo '</tr>';
while ($r = $st->fetch()) {
    echo '<tr>';
    echo '<td>'.x($r['claim_id']).'</td><td>'.x($r['region']).'</td><td>'.x($r['hospital_name']).'</td>';
    echo '<td>="'.x($r['card_id']).'"</td><td>'.x($r['esm_name']).'</td><td>'.x($r['patient_name']).'</td>';
    echo '<td>'.x($r['patient_type']).'</td><td>'.x($r['admit_type']).'</td><td>'.x($r['accept_date_raw']).'</td>';
    echo '<td>'.x($r['net_claim_amt']).'</td><td>'.x($r['approved_amt']).'</td><td>'.x($r['status']).'</td><td>'.x($r['processed_on_raw']).'</td>';
    echo '</tr>';
}
echo '</table></body></html>';
