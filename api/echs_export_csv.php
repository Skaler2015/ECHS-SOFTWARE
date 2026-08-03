<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/echs.php';
echs_ensure_table();

$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$ptype  = trim($_GET['ptype'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');

$where = []; $args = [];
if ($q !== '') { $where[]='(claim_id LIKE ? OR card_id LIKE ? OR esm_name LIKE ? OR patient_name LIKE ?)'; $l="%$q%"; array_push($args,$l,$l,$l,$l); }
if ($status !== '') { $where[]='status = ?'; $args[]=$status; }
if ($ptype !== '')  { $where[]='patient_type = ?'; $args[]=$ptype; }
if ($from !== '')   { $where[]='accept_date >= ?'; $args[]=$from; }
if ($to !== '')     { $where[]='accept_date <= ?'; $args[]=$to; }
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$st = db()->prepare("SELECT claim_id,region,hospital_name,card_id,esm_name,patient_name,patient_type,admit_type,accept_date_raw,net_claim_amt,approved_amt,status,processed_on_raw FROM echs_claims $wsql ORDER BY accept_date DESC, claim_id DESC");
$st->execute($args);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="echs_claims_' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Claim ID','Region','Hospital','Card ID','Name of ESM','Patient','Patient Type','Admit Type','Accept Date','Net Claim Amt','Approved Amt','Status','Processed On']);
while ($r = $st->fetch()) {
    fputcsv($out, [$r['claim_id'],$r['region'],$r['hospital_name'],$r['card_id'],$r['esm_name'],$r['patient_name'],$r['patient_type'],$r['admit_type'],$r['accept_date_raw'],$r['net_claim_amt'],$r['approved_amt'],$r['status'],$r['processed_on_raw']]);
}
fclose($out);
