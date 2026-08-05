<?php
/**
 * Medical Store (Pharmacy) import endpoint.
 * Browser parses the Pharmacy Invoice Tracker with SheetJS and POSTs rows here
 * in JSON batches (each row an array in store_field_names() order). Upsert by
 * Invoice No; record status changes; derive category server-side.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'not_authenticated']); exit; }
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error'=>'bad_json']); exit; }
$token = $body['token'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', (string)$token)) { http_response_code(400); echo json_encode(['error'=>'bad_token']); exit; }

@set_time_limit(120);
store_ensure_table();
$pdo = db();

$fields  = store_field_names();
$nfields = count($fields);
$dateSet = array_flip(store_date_fields());
$amtSet  = array_flip(store_amount_fields());
$intSet  = array_flip(store_int_fields());
$idxInv  = array_search('invoice_no', $fields, true);

$batch = $body['batch'] ?? [];
if (!is_array($batch)) $batch = [];

$rowsData = []; $invs = [];
foreach ($batch as $row) {
    if (!is_array($row)) continue;
    $inv = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)($row[$idxInv] ?? ''));
    $inv = trim($inv);
    if ($inv === '') continue;
    $rec = [];
    foreach ($fields as $i => $f) {
        $val = $row[$i] ?? null;
        if (is_string($val)) { $val = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $val); $val = trim($val); }
        if (isset($dateSet[$f]))     $val = store_parse_date($val);
        elseif (isset($amtSet[$f]))  $val = store_amount($val);
        elseif (isset($intSet[$f]))  $val = store_int($val);
        elseif ($val === '')         $val = null;
        $rec[$f] = $val;
    }
    $rec['invoice_no'] = $inv;
    $rec['category']   = store_category($rec['status'] ?? '');
    $rowsData[] = $rec; $invs[] = $inv;
}
if (!$rowsData) { echo json_encode(['ok'=>true,'read'=>0,'inserted'=>0,'updated'=>0,'changed'=>0]); exit; }

$prev = [];
$in = implode(',', array_fill(0, count($invs), '?'));
$ps = $pdo->prepare("SELECT invoice_no, status FROM store_claims WHERE invoice_no IN ($in)");
$ps->execute($invs);
foreach ($ps as $r) $prev[$r['invoice_no']] = $r['status'];

$cols = array_merge($fields, ['category']);
$ncols = count($cols);
$colSql = '`' . implode('`,`', $cols) . '`';
$ph1 = '(' . implode(',', array_fill(0, $ncols, '?')) . ')';
$placeholders = implode(',', array_fill(0, count($rowsData), $ph1));
$updates = [];
foreach ($cols as $c) { if ($c === 'invoice_no') continue; $updates[] = "`$c` = VALUES(`$c`)"; }
$updates[] = "`updated_at` = NOW()";
$sql = "INSERT INTO store_claims ($colSql) VALUES $placeholders ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

$args = [];
foreach ($rowsData as $rec) foreach ($cols as $c) $args[] = $rec[$c];

$inserted = 0; $updated = 0; $history = [];
foreach ($rowsData as $rec) {
    $inv = $rec['invoice_no'];
    if (array_key_exists($inv, $prev)) { $updated++; if ((string)$prev[$inv] !== (string)$rec['status']) $history[] = [$inv, $prev[$inv], $rec['status']]; }
    else { $inserted++; $history[] = [$inv, null, $rec['status']]; }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare($sql)->execute($args);
    if ($history) {
        $h = $pdo->prepare("INSERT INTO store_claim_history (invoice_no, from_status, to_status) VALUES (?,?,?)");
        foreach ($history as $row) $h->execute($row);
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error'=>'db', 'message'=>$e->getMessage()]);
    exit;
}

if (!empty($body['first'])) {
    try {
        $pdo->prepare("INSERT INTO store_uploads (filename, rows_read, inserted, updated, who) VALUES (?,?,?,?,?)")
            ->execute([mb_substr((string)($body['filename'] ?? 'upload'), 0, 200), count($rowsData), $inserted, $updated, (current_user()['full_name'] ?? 'staff')]);
        store_log('upload', ($body['filename'] ?? 'upload').": +$inserted new, $updated upd");
    } catch (Exception $e) {}
}
echo json_encode(['ok'=>true, 'read'=>count($rowsData), 'inserted'=>$inserted, 'updated'=>$updated, 'changed'=>count($history)]);
