<?php
/**
 * RGHS import endpoint.
 *
 * The browser (rghs_upload.php) parses the Excel with SheetJS and POSTs the
 * rows here in small JSON batches. Each row is an array in the canonical
 * field order (rghs_field_names()). We upsert by TID and record status
 * changes. This keeps big imports fast and timeout-free on shared hosting.
 *
 * Request  (application/json):
 *   { "token": "<csrf>", "filename": "..", "batch": [[...],[...]], "first": true }
 * Response (json):
 *   { "ok": true, "read": N, "inserted": N, "updated": N, "changed": N }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rghs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'not_authenticated']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error'=>'bad_json']); exit; }

// CSRF: token must match the session token
$token = $body['token'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', (string)$token)) {
    http_response_code(400); echo json_encode(['error'=>'bad_token']); exit;
}

@set_time_limit(120);
rghs_ensure_table();
$pdo = db();

$kind = ($body['kind'] ?? 'claims') === 'payments' ? 'payments' : 'claims';

/* ============================ PAYMENTS ============================ */
if ($kind === 'payments') {
    $pfields = rghs_payment_field_names();
    $np = count($pfields);
    $pdate = array_flip(rghs_payment_date_fields());
    $pamt  = array_flip(rghs_payment_amount_fields());
    $idxTidP = array_search('tid', $pfields, true);

    $batch = $body['batch'] ?? [];
    if (!is_array($batch)) $batch = [];
    $recs = []; $tids = [];
    foreach ($batch as $row) {
        if (!is_array($row)) continue;
        $tid = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)($row[$idxTidP] ?? ''));
        $tid = trim($tid);
        if ($tid === '') continue;
        $rec = [];
        foreach ($pfields as $i => $f) {
            $val = $row[$i] ?? null;
            if (is_string($val)) { $val = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $val); $val = trim($val); }
            if (isset($pdate[$f]))     $val = rghs_pdate($val);
            elseif (isset($pamt[$f]))  $val = rghs_amount($val);
            elseif ($val === '')       $val = null;
            $rec[$f] = $val;
        }
        $rec['tid'] = $tid;
        $recs[] = $rec; $tids[] = $tid;
    }
    if (!$recs) { echo json_encode(['ok'=>true,'read'=>0,'inserted'=>0,'updated'=>0,'changed'=>0]); exit; }

    $prevP = [];
    $in = implode(',', array_fill(0, count($tids), '?'));
    $ps = $pdo->prepare("SELECT tid FROM rghs_payments WHERE tid IN ($in)");
    $ps->execute($tids);
    foreach ($ps as $r) $prevP[$r['tid']] = true;

    $colSql = '`' . implode('`,`', $pfields) . '`';
    $ph1 = '(' . implode(',', array_fill(0, $np, '?')) . ')';
    $placeholders = implode(',', array_fill(0, count($recs), $ph1));
    $updates = [];
    foreach ($pfields as $c) { if ($c === 'tid') continue; $updates[] = "`$c` = VALUES(`$c`)"; }
    $updates[] = "`updated_at` = NOW()";
    $sql = "INSERT INTO rghs_payments ($colSql) VALUES $placeholders ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
    $args = [];
    foreach ($recs as $rec) foreach ($pfields as $c) $args[] = $rec[$c];

    $ins = 0; $upd = 0;
    foreach ($recs as $rec) { if (isset($prevP[$rec['tid']])) $upd++; else $ins++; }

    try {
        $pdo->beginTransaction();
        $pdo->prepare($sql)->execute($args);
        rghs_rollup_payments($tids);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error'=>'db', 'message'=>$e->getMessage()]);
        exit;
    }

    if (!empty($body['first'])) {
        try {
            $pdo->prepare("INSERT INTO rghs_uploads (filename, kind, rows_read, inserted, updated) VALUES (?,?,?,?,?)")
                ->execute([mb_substr((string)($body['filename'] ?? 'payments'), 0, 200), 'payments', count($recs), $ins, $upd]);
        } catch (Exception $e) {}
    }
    echo json_encode(['ok'=>true, 'read'=>count($recs), 'inserted'=>$ins, 'updated'=>$upd, 'changed'=>0]);
    exit;
}

/* ============================ CLAIMS ============================ */
$fields  = rghs_field_names();                 // ordered
$nfields = count($fields);
$dateSet = array_flip(rghs_date_fields());
$amtSet  = array_flip(rghs_amount_fields());
$intSet  = array_flip(rghs_int_fields());
$idxTid  = array_search('tid', $fields, true);

$batch = $body['batch'] ?? [];
if (!is_array($batch)) $batch = [];

$rowsData = [];   // normalized assoc rows
$tids = [];
foreach ($batch as $row) {
    if (!is_array($row)) continue;
    $tid = trim((string)($row[$idxTid] ?? ''));
    if ($tid === '') continue;
    $rec = [];
    foreach ($fields as $i => $f) {
        $val = $row[$i] ?? null;
        if (is_string($val)) $val = trim($val);
        if (isset($dateSet[$f]))      $val = rghs_parse_date($val);
        elseif (isset($amtSet[$f]))   $val = rghs_amount($val);
        elseif (isset($intSet[$f]))   $val = rghs_int($val);
        elseif ($val === '' )         $val = null;
        $rec[$f] = $val;
    }
    $rec['tid'] = $tid;
    $rowsData[] = $rec;
    $tids[] = $tid;
}

if (!$rowsData) { echo json_encode(['ok'=>true,'read'=>0,'inserted'=>0,'updated'=>0,'changed'=>0]); exit; }

// existing statuses for these TIDs (one query)
$prev = [];
$in = implode(',', array_fill(0, count($tids), '?'));
$ps = $pdo->prepare("SELECT tid, status FROM rghs_claims WHERE tid IN ($in)");
$ps->execute($tids);
foreach ($ps as $r) $prev[$r['tid']] = $r['status'];

// build one multi-row upsert
$cols = $fields;
$colSql = '`' . implode('`,`', $cols) . '`';
$ph1 = '(' . implode(',', array_fill(0, $nfields, '?')) . ')';
$placeholders = implode(',', array_fill(0, count($rowsData), $ph1));

$updates = [];
foreach ($cols as $c) {
    if ($c === 'tid') continue;
    if ($c === 'doctor_name') {
        // keep a hand-set doctor name across re-imports
        $updates[] = "`doctor_name` = IF(doctor_manual=1, doctor_name, VALUES(doctor_name))";
    } else {
        $updates[] = "`$c` = VALUES(`$c`)";
    }
}
$updates[] = "`updated_at` = NOW()";

$sql = "INSERT INTO rghs_claims ($colSql) VALUES $placeholders
        ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

$args = [];
foreach ($rowsData as $rec) foreach ($cols as $c) $args[] = $rec[$c];

$inserted = 0; $updated = 0; $history = [];
foreach ($rowsData as $rec) {
    $tid = $rec['tid'];
    if (array_key_exists($tid, $prev)) {
        $updated++;
        if ($prev[$tid] !== $rec['status']) $history[] = [$tid, $prev[$tid], $rec['status']];
    } else {
        $inserted++;
        $history[] = [$tid, null, $rec['status']];
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare($sql)->execute($args);
    if ($history) {
        $h = $pdo->prepare("INSERT INTO rghs_claim_history (tid, from_status, to_status) VALUES (?,?,?)");
        foreach ($history as $row) $h->execute($row);
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error'=>'db', 'message'=>$e->getMessage()]);
    exit;
}

// log the upload once per file (on the first batch)
if (!empty($body['first'])) {
    try {
        $pdo->prepare("INSERT INTO rghs_uploads (filename, kind, rows_read, inserted, updated) VALUES (?,?,?,?,?)")
            ->execute([mb_substr((string)($body['filename'] ?? 'upload'), 0, 200), 'claims', count($rowsData), $inserted, $updated]);
    } catch (Exception $e) {}
}

echo json_encode(['ok'=>true, 'read'=>count($rowsData), 'inserted'=>$inserted, 'updated'=>$updated, 'changed'=>count($history)]);
