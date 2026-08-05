<?php
/**
 * ECHS import endpoint.
 *
 * The browser (echs_upload.php) parses each CLAIMLIST .xls with SheetJS and
 * POSTs the rows here in small JSON batches. Each row is an array in the
 * canonical field order (echs_field_names()). The workflow *status* is not a
 * column in the sheet — it comes from the file's header line and is sent once
 * per file as `status`; we apply it to every row in the batch and derive the
 * category + lifecycle order server-side. We upsert by Claim ID and record
 * status changes.
 *
 * Request (application/json):
 *   { "token": "<csrf>", "filename": "..", "status": "Claim Settled",
 *     "batch": [[...],[...]], "first": true }
 * Response (json):
 *   { "ok": true, "read": N, "inserted": N, "updated": N, "changed": N }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/echs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['error'=>'not_authenticated']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error'=>'bad_json']); exit; }

$token = $body['token'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', (string)$token)) {
    http_response_code(400); echo json_encode(['error'=>'bad_token']); exit;
}

@set_time_limit(120);
echs_ensure_table();
$pdo = db();

$statusLabel = echs_status_clean($body['status'] ?? '');
$stageOrder  = echs_stage_order($statusLabel);
$category    = echs_category($statusLabel);

$fields  = echs_field_names();                 // ordered (12 excel columns)
$nfields = count($fields);
$dateSet = array_flip(echs_date_fields());
$amtSet  = array_flip(echs_amount_fields());
$idxCid  = array_search('claim_id', $fields, true);

$batch = $body['batch'] ?? [];
if (!is_array($batch)) $batch = [];

$rowsData = [];
$cids = [];
foreach ($batch as $row) {
    if (!is_array($row)) continue;
    $cid = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)($row[$idxCid] ?? ''));
    $cid = trim($cid);
    if ($cid === '') continue;
    $rec = [];
    foreach ($fields as $i => $f) {
        $val = $row[$i] ?? null;
        if (is_string($val)) { $val = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $val); $val = trim($val); }
        if (isset($dateSet[$f]))     $val = echs_parse_date($val);
        elseif (isset($amtSet[$f]))  $val = echs_amount($val);
        elseif ($val === '')         $val = null;
        $rec[$f] = $val;
    }
    $rec['claim_id']    = $cid;
    $rec['status']      = $statusLabel !== '' ? $statusLabel : null;
    $rec['category']    = $statusLabel !== '' ? $category : null;
    $rec['stage_order'] = $stageOrder;
    $rowsData[] = $rec;
    $cids[] = $cid;
}

if (!$rowsData) { echo json_encode(['ok'=>true,'read'=>0,'inserted'=>0,'updated'=>0,'changed'=>0]); exit; }

// existing statuses for these Claim IDs (one query)
$prev = [];
$in = implode(',', array_fill(0, count($cids), '?'));
$ps = $pdo->prepare("SELECT claim_id, status FROM echs_claims WHERE claim_id IN ($in)");
$ps->execute($cids);
foreach ($ps as $r) $prev[$r['claim_id']] = $r['status'];

// build one multi-row upsert (excel cols + status/category/stage_order)
$cols = array_merge($fields, ['status', 'category', 'stage_order']);
$ncols = count($cols);
$colSql = '`' . implode('`,`', $cols) . '`';
$ph1 = '(' . implode(',', array_fill(0, $ncols, '?')) . ')';
$placeholders = implode(',', array_fill(0, count($rowsData), $ph1));

$updates = [];
foreach ($cols as $c) {
    if ($c === 'claim_id') continue;
    if ($c === 'doctor_name') {
        $updates[] = "`doctor_name` = IF(doctor_manual=1, doctor_name, VALUES(doctor_name))";
    } elseif (in_array($c, ['claim_amt','approved_amt'], true)) {
        // don't overwrite a real amount with 0 from an early-stage (10-col) file
        $updates[] = "`$c` = IF(VALUES(`$c`) > 0, VALUES(`$c`), `$c`)";
    } else {
        $updates[] = "`$c` = VALUES(`$c`)";
    }
}
$updates[] = "`updated_at` = NOW()";

$sql = "INSERT INTO echs_claims ($colSql) VALUES $placeholders
        ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

$args = [];
foreach ($rowsData as $rec) foreach ($cols as $c) $args[] = $rec[$c];

$inserted = 0; $updated = 0; $history = [];
foreach ($rowsData as $rec) {
    $cid = $rec['claim_id'];
    if (array_key_exists($cid, $prev)) {
        $updated++;
        if ((string)$prev[$cid] !== (string)$rec['status']) $history[] = [$cid, $prev[$cid], $rec['status']];
    } else {
        $inserted++;
        $history[] = [$cid, null, $rec['status']];
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare($sql)->execute($args);
    if ($history) {
        $h = $pdo->prepare("INSERT INTO echs_claim_history (claim_id, from_status, to_status) VALUES (?,?,?)");
        foreach ($history as $row) $h->execute($row);
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error'=>'db', 'message'=>$e->getMessage()]);
    exit;
}

// auto query tracking: open a draft query for claims that entered "query/need-info",
// and auto-close the auto-created ones once the claim moves past that stage.
try {
    $inCids = implode(',', array_fill(0, count($cids), '?'));
    $pdo->prepare("INSERT INTO echs_queries (claim_id, query_text, status, priority, auto, who, raised_on)
        SELECT c.claim_id, CONCAT('Auto: ', COALESCE(NULLIF(c.status,''),'Need More Information')), 'open', 'medium', 1, 'auto', CURDATE()
        FROM echs_claims c
        WHERE c.claim_id IN ($inCids) AND c.category='query'
          AND NOT EXISTS (SELECT 1 FROM echs_queries q WHERE q.claim_id = c.claim_id COLLATE utf8mb4_unicode_ci)")
        ->execute($cids);
    $pdo->prepare("UPDATE echs_queries q JOIN echs_claims c ON c.claim_id = q.claim_id COLLATE utf8mb4_unicode_ci
        SET q.status='closed', q.replied_on=CURDATE(), q.reply_text=COALESCE(q.reply_text, CONCAT('Auto-closed: ', c.status))
        WHERE c.claim_id IN ($inCids) AND q.auto=1 AND q.status<>'closed' AND c.category<>'query'")
        ->execute($cids);
} catch (Exception $e) {}

if (!empty($body['first'])) {
    try {
        $pdo->prepare("INSERT INTO echs_uploads (filename, status_label, rows, who) VALUES (?,?,?,?)")
            ->execute([mb_substr((string)($body['filename'] ?? 'upload'), 0, 200), mb_substr($statusLabel,0,90), count($rowsData), (current_user()['full_name'] ?? 'staff')]);
        echs_log('upload', ($body['filename'] ?? 'upload').' ['.$statusLabel."]: +$inserted new, $updated upd");
    } catch (Exception $e) {}
}

echo json_encode(['ok'=>true, 'read'=>count($rowsData), 'inserted'=>$inserted, 'updated'=>$updated, 'changed'=>count($history)]);
