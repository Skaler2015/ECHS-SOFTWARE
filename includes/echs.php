<?php
/**
 * ECHS Claims module helpers.
 *
 * Reads the "CLAIMLIST_*.xls" files downloaded from the ECHS / UTIITSL
 * portal and stores/updates them in `echs_claims`, tracks status history,
 * per-claim notes, and an upload log.
 *
 * Uses the bundled SimpleXLS reader (lib/SimpleXLS.php) — no Composer.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../lib/SimpleXLS.php';

use Shuchkin\SimpleXLS;

/** Create/upgrade all ECHS tables. Adds missing columns automatically. */
function echs_ensure_table() {
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_claims` (
        `claim_id` VARCHAR(30) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        'notes'           => "TEXT NULL",
        'followup'        => "TINYINT(1) NOT NULL DEFAULT 0",
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
    foreach ([
        'idx_status'=>'status','idx_scode'=>'status_code','idx_card'=>'card_id',
        'idx_ptype'=>'patient_type','idx_acc'=>'accept_date','idx_fu'=>'followup'
    ] as $idx => $col) {
        try { $pdo->exec("ALTER TABLE `echs_claims` ADD INDEX `$idx` (`$col`)"); } catch (Exception $e) {}
    }

    // status change history
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_claim_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(30) NOT NULL,
        `from_status` VARCHAR(90) NULL,
        `to_status` VARCHAR(90) NULL,
        `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_h_claim` (`claim_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // upload log
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_uploads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(200) NULL,
        `status` VARCHAR(90) NULL,
        `rows_read` INT DEFAULT 0,
        `inserted` INT DEFAULT 0,
        `updated` INT DEFAULT 0,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}

function echs_parse_date($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace('/', '-', $v);
    $d = DateTime::createFromFormat('d-m-Y', $v);
    if ($d && $d->format('d-m-Y') === $v) return $d->format('Y-m-d');
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function echs_amount($v) {
    $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $v === '' ? 0.0 : (float)$v;
}

function echs_status_from_title($title) {
    $t = trim((string)$title);
    $t = preg_replace('/\s+as on\s+.*$/i', '', $t);
    return trim($t);
}

function echs_code_from_filename($name) {
    $base = preg_replace('/\.xls[x]?$/i', '', basename($name));
    $base = preg_replace('/^CLAIMLIST[_\-]?/i', '', $base);
    return substr(trim($base), 0, 30);
}

/** Category of a status: 'settled' | 'rejected' | 'process' */
function echs_category($status) {
    $s = strtolower((string)$status);
    if (strpos($s, 'settled') !== false) return 'settled';
    if (strpos($s, 'reject') !== false || strpos($s, 'cancel') !== false) return 'rejected';
    return 'process';
}

/** SQL condition (for WHERE) that matches "in-process / pending money" claims. */
function echs_pending_condition() {
    return "(status NOT LIKE '%Settled%' AND status NOT LIKE '%Reject%' AND status NOT LIKE '%Cancel%')";
}

/**
 * Import a single .xls claim-list file.
 * $prev is a reference map claim_id => current status (for history + counts).
 */
function echs_import_xls($path, $original_name, array &$prev, array &$history) {
    echs_ensure_table();
    $res = ['ok'=>false,'status'=>'','read'=>0,'inserted'=>0,'updated'=>0,'error'=>''];

    $xls = SimpleXLS::parse($path);
    if (!$xls) { $res['error'] = 'File padhi nahi ja saki: ' . SimpleXLS::parseError(); return $res; }
    $rows = $xls->rows();
    if (!$rows) { $res['error'] = 'File khali hai.'; return $res; }

    $title = ''; $headerRow = -1;
    foreach ($rows as $i => $r) {
        if ($title === '' && stripos(implode(' ', array_map('strval', $r)), 'as on') !== false) {
            $title = trim((string)($r[0] ?? ''));
        }
        if (strcasecmp(trim((string)($r[0] ?? '')), 'Claim ID') === 0) { $headerRow = $i; break; }
    }
    if ($headerRow < 0) { $res['error'] = 'Header (Claim ID) nahi mila — kya yeh sahi CLAIMLIST file hai?'; return $res; }

    $map = [];
    foreach ($rows[$headerRow] as $c => $h) {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', (string)$h)));
        $map[$key] = $c;
    }
    $col = function($names) use ($map) {
        foreach ((array)$names as $n) { $k = strtolower(trim($n)); if (isset($map[$k])) return $map[$k]; }
        return null;
    };
    $cClaim=$col('claim id'); $cRegion=$col('region'); $cHosp=$col('hospital name');
    $cCard=$col('card id'); $cEsm=$col('name of esm'); $cPat=$col('patient name');
    $cPType=$col('patient type'); $cAType=$col('admit type'); $cAccept=$col('accept date');
    $cNet=$col(['net claim amt','net claim amount']); $cApp=$col(['approved amt','approved amount']);
    $cProc=$col('processed on');
    if ($cClaim === null) { $res['error'] = 'Claim ID column nahi mila.'; return $res; }

    $status = echs_status_from_title($title);
    if ($status === '') $status = str_replace('_', ' ', echs_code_from_filename($original_name));
    $code = echs_code_from_filename($original_name);
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
        if ($claim === '' || !preg_match('/^\d{3,}$/', $claim)) continue;
        $res['read']++;

        $acceptRaw = $cAccept !== null ? trim((string)($r[$cAccept] ?? '')) : '';
        $procRaw   = $cProc   !== null ? trim((string)($r[$cProc] ?? ''))   : '';

        $stmt->execute([
            $claim,
            $cRegion!==null?trim((string)$r[$cRegion]):null,
            $cHosp!==null?trim((string)$r[$cHosp]):null,
            $cCard!==null?trim((string)$r[$cCard]):null,
            $cEsm!==null?trim((string)$r[$cEsm]):null,
            $cPat!==null?trim((string)$r[$cPat]):null,
            $cPType!==null?trim((string)$r[$cPType]):null,
            $cAType!==null?trim((string)$r[$cAType]):null,
            echs_parse_date($acceptRaw), $acceptRaw ?: null,
            $cNet!==null?echs_amount($r[$cNet]):0,
            $cApp!==null?echs_amount($r[$cApp]):0,
            $status, $code,
            echs_parse_date($procRaw), $procRaw ?: null,
        ]);

        if (array_key_exists($claim, $prev)) {
            $res['updated']++;
            if ($prev[$claim] !== $status) {
                $history[] = [$claim, $prev[$claim], $status];
            }
        } else {
            $res['inserted']++;
            $history[] = [$claim, null, $status];
        }
        $prev[$claim] = $status;
    }
    $pdo->commit();
    $res['ok'] = true;
    return $res;
}

/** Import many uploaded files (.xls or .zip). Returns summary. */
function echs_import_uploads(array $files) {
    echs_ensure_table();
    $pdo = db();

    // load existing statuses once (for history + insert/update counts)
    $prev = [];
    foreach ($pdo->query("SELECT claim_id, status FROM echs_claims") as $r) {
        $prev[$r['claim_id']] = $r['status'];
    }

    $summary = ['files'=>[], 'read'=>0, 'inserted'=>0, 'updated'=>0, 'changed'=>0, 'errors'=>[]];
    $history = [];

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
                    $out = $dir . '/' . basename($entry);
                    file_put_contents($out, $za->getFromIndex($k));
                    _echs_one($out, basename($entry), $prev, $history, $summary);
                    @unlink($out);
                }
                $za->close(); @rmdir($dir);
            } else {
                $summary['errors'][] = "$name: ZIP khul nahi paayi.";
            }
        } elseif ($ext === 'xls' || $ext === 'xlsx') {
            _echs_one($tmp, $name, $prev, $history, $summary);
        } else {
            $summary['errors'][] = "$name: sirf .xls ya .zip allowed hain.";
        }
    }

    // write history in batches
    if ($history) {
        $h = $pdo->prepare("INSERT INTO echs_claim_history (claim_id, from_status, to_status) VALUES (?,?,?)");
        $pdo->beginTransaction();
        foreach ($history as $row) { $h->execute($row); }
        $pdo->commit();
        $summary['changed'] = count($history);
    }
    return $summary;
}

function _echs_one($path, $name, array &$prev, array &$history, array &$summary) {
    $r = echs_import_xls($path, $name, $prev, $history);
    if ($r['ok']) {
        $summary['files'][] = ['name'=>$name,'status'=>$r['status'],'read'=>$r['read'],'inserted'=>$r['inserted'],'updated'=>$r['updated']];
        $summary['read'] += $r['read'];
        $summary['inserted'] += $r['inserted'];
        $summary['updated'] += $r['updated'];
        // log
        try {
            db()->prepare("INSERT INTO echs_uploads (filename,status,rows_read,inserted,updated) VALUES (?,?,?,?,?)")
                ->execute([$name, $r['status'], $r['read'], $r['inserted'], $r['updated']]);
        } catch (Exception $e) {}
    } else {
        $summary['errors'][] = "$name: " . $r['error'];
    }
}

/** Distinct statuses with counts (for filters). */
function echs_status_list() {
    echs_ensure_table();
    $out = [];
    foreach (db()->query("SELECT status, COUNT(*) n FROM echs_claims GROUP BY status ORDER BY status") as $r) {
        $out[] = $r;
    }
    return $out;
}
