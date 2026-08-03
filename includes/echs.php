<?php
/**
 * ECHS Claims module helpers.
 *
 * Reads the "CLAIMLIST_*.xls" files that are downloaded from the
 * ECHS / UTIITSL portal and stores/updates them in the `echs_claims` table.
 *
 * Uses the bundled SimpleXLS reader (lib/SimpleXLS.php) — no Composer needed.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../lib/SimpleXLS.php';

use Shuchkin\SimpleXLS;

/** Create/upgrade the ECHS claims table. Adds any missing columns so an
 *  older/partial table is migrated automatically without losing data. */
function echs_ensure_table() {
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_claims` (
        `claim_id` VARCHAR(30) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // desired columns (name => definition)
    $cols = [
        'region'          => "VARCHAR(80) NULL",
        'hospital_name'   => "VARCHAR(180) NULL",
        'card_id'         => "VARCHAR(40) NULL",
        'esm_name'        => "VARCHAR(180) NULL",
        'patient_name'    => "VARCHAR(180) NULL",
        'patient_type'    => "VARCHAR(6) NULL",
        'admit_type'      => "VARCHAR(6) NULL",
        'accept_date'     => "DATE NULL",
        'accept_date_raw' => "VARCHAR(20) NULL",
        'net_claim_amt'   => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'approved_amt'    => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'status'          => "VARCHAR(90) NULL",
        'status_code'     => "VARCHAR(30) NULL",
        'processed_on'    => "DATE NULL",
        'processed_on_raw'=> "VARCHAR(20) NULL",
        'first_seen'      => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at'      => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `echs_claims`") as $c) {
        $existing[strtolower($c['Field'])] = true;
    }
    foreach ($cols as $name => $def) {
        if (!isset($existing[strtolower($name)])) {
            $pdo->exec("ALTER TABLE `echs_claims` ADD COLUMN `$name` $def");
        }
    }
    // best-effort indexes (ignore if they already exist)
    foreach ([
        'idx_status'=>'status','idx_scode'=>'status_code','idx_card'=>'card_id',
        'idx_ptype'=>'patient_type','idx_acc'=>'accept_date'
    ] as $idx => $col) {
        try { $pdo->exec("ALTER TABLE `echs_claims` ADD INDEX `$idx` (`$col`)"); } catch (Exception $e) {}
    }
    $done = true;
}

/** Parse a dd-mm-yyyy (or dd/mm/yyyy) string to Y-m-d, or null. */
function echs_parse_date($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace('/', '-', $v);
    $d = DateTime::createFromFormat('d-m-Y', $v);
    if ($d && $d->format('d-m-Y') === $v) return $d->format('Y-m-d');
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Turn a raw amount string like "42532.00" into a float. */
function echs_amount($v) {
    $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $v === '' ? 0.0 : (float)$v;
}

/** Derive a friendly status from the sheet title row, e.g.
 *  "Review By Validator [App] as on 03/08/2026" -> "Review By Validator [App]" */
function echs_status_from_title($title) {
    $t = trim((string)$title);
    $t = preg_replace('/\s+as on\s+.*$/i', '', $t);
    return trim($t);
}

/** Derive a short code from the file name: CLAIMLIST_6S.xls -> 6S */
function echs_code_from_filename($name) {
    $base = preg_replace('/\.xls[x]?$/i', '', basename($name));
    $base = preg_replace('/^CLAIMLIST[_\-]?/i', '', $base);
    return substr(trim($base), 0, 30);
}

/**
 * Import a single .xls claim-list file.
 * Returns [ok=>bool, status=>string, read=>int, inserted=>int, updated=>int, error=>string]
 */
function echs_import_xls($path, $original_name = '') {
    echs_ensure_table();
    $res = ['ok'=>false,'status'=>'','read'=>0,'inserted'=>0,'updated'=>0,'error'=>''];

    $xls = SimpleXLS::parse($path);
    if (!$xls) {
        $res['error'] = 'File padhi nahi ja saki: ' . SimpleXLS::parseError();
        return $res;
    }
    $rows = $xls->rows();
    if (!$rows) { $res['error'] = 'File khali hai.'; return $res; }

    // find title (row containing "as on") and header row (first cell == Claim ID)
    $title = '';
    $headerRow = -1;
    foreach ($rows as $i => $r) {
        $first = trim((string)($r[0] ?? ''));
        if ($title === '' && stripos(implode(' ', array_map('strval', $r)), 'as on') !== false) {
            $title = $first;
        }
        if (strcasecmp($first, 'Claim ID') === 0) { $headerRow = $i; break; }
    }
    if ($headerRow < 0) { $res['error'] = 'Header (Claim ID) nahi mila — kya yeh sahi CLAIMLIST file hai?'; return $res; }

    // map header name -> column index
    $map = [];
    foreach ($rows[$headerRow] as $c => $h) {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', (string)$h)));
        $map[$key] = $c;
    }
    $col = function($names) use ($map) {
        foreach ((array)$names as $n) {
            $k = strtolower(trim($n));
            if (isset($map[$k])) return $map[$k];
        }
        return null;
    };
    $cClaim   = $col(['claim id']);
    $cRegion  = $col(['region']);
    $cHosp    = $col(['hospital name']);
    $cCard    = $col(['card id']);
    $cEsm     = $col(['name of esm']);
    $cPat     = $col(['patient name']);
    $cPType   = $col(['patient type']);
    $cAType   = $col(['admit type']);
    $cAccept  = $col(['accept date']);
    $cNet     = $col(['net claim amt','net claim amt ','net claim amount']);
    $cApp     = $col(['approved amt','approved amount']);
    $cProc    = $col(['processed on']);

    if ($cClaim === null) { $res['error'] = 'Claim ID column nahi mila.'; return $res; }

    $status = echs_status_from_title($title);
    if ($status === '') $status = str_replace('_', ' ', echs_code_from_filename($original_name ?: $path));
    $code   = echs_code_from_filename($original_name ?: $path);
    $res['status'] = $status;

    $pdo = db();
    $sql = "INSERT INTO echs_claims
        (claim_id, region, hospital_name, card_id, esm_name, patient_name, patient_type, admit_type,
         accept_date, accept_date_raw, net_claim_amt, approved_amt, status, status_code, processed_on, processed_on_raw, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
        ON DUPLICATE KEY UPDATE
         region=VALUES(region), hospital_name=VALUES(hospital_name), card_id=VALUES(card_id),
         esm_name=VALUES(esm_name), patient_name=VALUES(patient_name), patient_type=VALUES(patient_type),
         admit_type=VALUES(admit_type), accept_date=VALUES(accept_date), accept_date_raw=VALUES(accept_date_raw),
         net_claim_amt=VALUES(net_claim_amt), approved_amt=VALUES(approved_amt),
         status=VALUES(status), status_code=VALUES(status_code),
         processed_on=VALUES(processed_on), processed_on_raw=VALUES(processed_on_raw), updated_at=NOW()";
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();

    foreach ($rows as $i => $r) {
        if ($i <= $headerRow) continue;
        $claim = trim((string)($r[$cClaim] ?? ''));
        if ($claim === '' || !preg_match('/^\d{3,}$/', $claim)) continue; // skip footers/blank
        $res['read']++;

        $acceptRaw = $cAccept !== null ? trim((string)($r[$cAccept] ?? '')) : '';
        $procRaw   = $cProc   !== null ? trim((string)($r[$cProc] ?? ''))   : '';

        $stmt->execute([
            $claim,
            $cRegion!==null ? trim((string)$r[$cRegion]) : null,
            $cHosp!==null   ? trim((string)$r[$cHosp])   : null,
            $cCard!==null   ? trim((string)$r[$cCard])   : null,
            $cEsm!==null    ? trim((string)$r[$cEsm])    : null,
            $cPat!==null    ? trim((string)$r[$cPat])    : null,
            $cPType!==null  ? trim((string)$r[$cPType])  : null,
            $cAType!==null  ? trim((string)$r[$cAType])  : null,
            echs_parse_date($acceptRaw), $acceptRaw ?: null,
            $cNet!==null ? echs_amount($r[$cNet]) : 0,
            $cApp!==null ? echs_amount($r[$cApp]) : 0,
            $status, $code,
            echs_parse_date($procRaw), $procRaw ?: null,
        ]);
        // MySQL: rowCount() is 1 for a fresh INSERT, 2 for an UPDATE of an existing row.
        if ($stmt->rowCount() === 1) $res['inserted']++; else $res['updated']++;
    }
    $pdo->commit();
    $res['ok'] = true;
    return $res;
}

/**
 * Import many files: accepts an array of ['tmp'=>path,'name'=>original].
 * Handles .zip (extracts contained .xls) and .xls.
 * Returns a summary array.
 */
function echs_import_uploads(array $files) {
    $summary = ['files'=>[], 'read'=>0, 'inserted'=>0, 'updated'=>0, 'errors'=>[]];
    foreach ($files as $f) {
        $name = $f['name']; $tmp = $f['tmp'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $za = new ZipArchive();
            if ($za->open($tmp) === true) {
                $dir = sys_get_temp_dir() . '/echs_' . bin2hex(random_bytes(4));
                @mkdir($dir);
                for ($k = 0; $k < $za->numFiles; $k++) {
                    $entry = $za->getNameIndex($k);
                    if (!preg_match('/\.xls$/i', $entry)) continue;
                    $data = $za->getFromIndex($k);
                    $out = $dir . '/' . basename($entry);
                    file_put_contents($out, $data);
                    _echs_one($out, basename($entry), $summary);
                    @unlink($out);
                }
                $za->close();
                @rmdir($dir);
            } else {
                $summary['errors'][] = "$name: ZIP khul nahi paayi.";
            }
        } elseif ($ext === 'xls' || $ext === 'xlsx') {
            _echs_one($tmp, $name, $summary);
        } else {
            $summary['errors'][] = "$name: sirf .xls ya .zip files allowed hain.";
        }
    }
    return $summary;
}

function _echs_one($path, $name, &$summary) {
    $r = echs_import_xls($path, $name);
    if ($r['ok']) {
        $summary['files'][] = ['name'=>$name,'status'=>$r['status'],'read'=>$r['read'],'inserted'=>$r['inserted'],'updated'=>$r['updated']];
        $summary['read'] += $r['read'];
        $summary['inserted'] += $r['inserted'];
        $summary['updated'] += $r['updated'];
    } else {
        $summary['errors'][] = "$name: " . $r['error'];
    }
}

/** Distinct statuses currently in the table (for filters). */
function echs_status_list() {
    echs_ensure_table();
    $out = [];
    foreach (db()->query("SELECT status, COUNT(*) n FROM echs_claims GROUP BY status ORDER BY status") as $r) {
        $out[] = $r;
    }
    return $out;
}
