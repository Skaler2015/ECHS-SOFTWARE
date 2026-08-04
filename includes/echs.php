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
        'nmi_date'        => "DATE NULL",
        'nmi_remarks'     => "TEXT NULL",
        'settlement_id'   => "VARCHAR(30) NULL",
        'settle_date'     => "DATE NULL",
        'echs_disc'       => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'tds_amt'         => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'bpa_fees'        => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'recovery_amt'    => "DECIMAL(14,2) NOT NULL DEFAULT 0",
        'amt_credited'    => "DECIMAL(14,2) NOT NULL DEFAULT 0",
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

    // payments received against claims
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_payments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(30) NULL,
        `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `pay_date` DATE NULL,
        `utr` VARCHAR(60) NULL,
        `mode` VARCHAR(30) NULL,
        `remarks` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_p_claim` (`claim_id`), KEY `idx_p_date` (`pay_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // document attachments
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_docs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(30) NULL,
        `stored_name` VARCHAR(160) NULL,
        `orig_name` VARCHAR(200) NULL,
        `size` INT DEFAULT 0,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_d_claim` (`claim_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // follow-up tasks
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_tasks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(30) NULL,
        `title` VARCHAR(255) NOT NULL,
        `due_date` DATE NULL,
        `done` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `done_at` DATETIME NULL,
        KEY `idx_t_done` (`done`), KEY `idx_t_due` (`due_date`), KEY `idx_t_claim` (`claim_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // contacts per card
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_contacts` (
        `card_id` VARCHAR(40) NOT NULL PRIMARY KEY,
        `name` VARCHAR(180) NULL,
        `phone` VARCHAR(40) NULL,
        `address` VARCHAR(255) NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // activity log
    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_activity` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `who` VARCHAR(120) NULL,
        `action` VARCHAR(80) NULL,
        `detail` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}

/** Log an activity row (best effort). */
function echs_log($action, $detail = '') {
    try {
        echs_ensure_table();
        $u = function_exists('current_user') ? (current_user()['full_name'] ?? 'system') : 'system';
        db()->prepare("INSERT INTO echs_activity (who,action,detail) VALUES (?,?,?)")
            ->execute([$u, $action, mb_substr((string)$detail, 0, 255)]);
    } catch (Exception $e) {}
}

/** Get (or create) the cron key used to authorise the weekly email URL. */
function echs_cron_key() {
    $k = setting('cron_key', '');
    if ($k === '') {
        $k = bin2hex(random_bytes(8));
        db()->prepare("INSERT INTO settings (skey,svalue) VALUES ('cron_key',?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)")->execute([$k]);
    }
    return $k;
}

/** Build the weekly summary text (used by cron + preview). */
function echs_weekly_summary_text() {
    echs_ensure_table();
    $tot = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims")->fetch();
    $pend = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE " . echs_pending_condition())->fetch();
    $chg = db()->query("SELECT COUNT(*) n FROM echs_claim_history WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND from_status IS NOT NULL")->fetch()['n'];
    $recv = db()->query("SELECT COALESCE(SUM(amount),0) s FROM echs_payments")->fetch()['s'];
    $overdue = db()->query("SELECT COUNT(*) n FROM echs_tasks WHERE done=0 AND due_date IS NOT NULL AND due_date < CURDATE()")->fetch()['n'];
    $old90 = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE " . echs_pending_condition() . " AND accept_date IS NOT NULL AND DATEDIFF(CURDATE(),accept_date) > 90")->fetch();

    $rs = number_format((float)$tot['net']);
    return "ECHS Weekly Summary — " . date('d-m-Y') . "\n"
        . "-----------------------------------\n"
        . "Total claims: " . number_format($tot['n']) . "\n"
        . "Net claimed:  Rs " . number_format($tot['net']) . "\n"
        . "Approved:     Rs " . number_format($tot['app']) . "\n"
        . "Received:     Rs " . number_format($recv) . "\n"
        . "Outstanding:  Rs " . number_format($pend['net']) . " (" . number_format($pend['n']) . " claims)\n"
        . "90+ days old: " . number_format($old90['n']) . " claims (Rs " . number_format($old90['net']) . ")\n"
        . "Status changes (7 days): " . number_format($chg) . "\n"
        . "Overdue tasks: " . number_format($overdue) . "\n";
}

/** Current user's role ('admin' or 'staff'); admins can manage users/data. */
function echs_role() {
    $u = function_exists('current_user') ? current_user() : null;
    return $u['role'] ?? 'admin';
}
function echs_is_admin() { return echs_role() === 'admin'; }

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

    // ---- auto-detect report type ----
    $head10 = '';
    foreach (array_slice($rows, 0, 12) as $rr) { $head10 .= ' ' . implode(' ', array_map('strval', $rr)); }
    if (stripos($head10, 'NeedMoreInfo') !== false || stripos($head10, 'Latest User Remarks') !== false) {
        return echs_import_nmi_rows($rows, $original_name, $prev, $history);
    }

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

/** Import a NeedMoreInfo Report (.xls): adds NMI date + portal remarks to claims. */
function echs_import_nmi_rows(array $rows, $original_name, array &$prev, array &$history) {
    echs_ensure_table();
    $res = ['ok'=>false,'status'=>'NeedMoreInfo Report','read'=>0,'inserted'=>0,'updated'=>0,'error'=>''];
    $hr = -1;
    foreach ($rows as $i => $r) {
        $j = strtolower(implode(' ', array_map('strval', $r)));
        if (strpos($j, 'claim id') !== false && strpos($j, 'remarks') !== false) { $hr = $i; break; }
    }
    if ($hr < 0) { $res['error'] = 'NeedMoreInfo header nahi mila.'; return $res; }
    $map = [];
    foreach ($rows[$hr] as $c => $h) { $map[strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ',(string)$h)))] = $c; }
    $col = function($names) use ($map) { foreach ((array)$names as $n){ $k=strtolower(trim($n)); if(isset($map[$k]))return $map[$k]; } return null; };
    $cClaim=$col('claim id'); $cRegion=$col('echs region'); $cHosp=$col('hospital name');
    $cIO=$col('i o'); $cCard=$col('card id'); $cBen=$col('beneficiary name');
    $cPat=$col('patient name'); $cAmt=$col('claim amount'); $cNmiDate=$col('nmi date'); $cRem=$col(['latest user remarks','remarks']);
    if ($cClaim === null) { $res['error']='Claim Id column nahi mila (NMI).'; return $res; }

    $pdo = db();
    $sql = "INSERT INTO echs_claims (claim_id,region,hospital_name,card_id,esm_name,patient_name,patient_type,net_claim_amt,status,status_code,nmi_date,nmi_remarks,updated_at)
        VALUES (?,?,?,?,?,?,?,?, 'Need More Information [Portal]','NMI', ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          nmi_date=VALUES(nmi_date), nmi_remarks=VALUES(nmi_remarks),
          region=IF(region IS NULL OR region='',VALUES(region),region),
          hospital_name=IF(hospital_name IS NULL OR hospital_name='',VALUES(hospital_name),hospital_name),
          card_id=IF(card_id IS NULL OR card_id='',VALUES(card_id),card_id),
          esm_name=IF(esm_name IS NULL OR esm_name='',VALUES(esm_name),esm_name),
          patient_name=IF(patient_name IS NULL OR patient_name='',VALUES(patient_name),patient_name),
          patient_type=IF(patient_type IS NULL OR patient_type='',VALUES(patient_type),patient_type),
          net_claim_amt=IF(net_claim_amt=0,VALUES(net_claim_amt),net_claim_amt),
          updated_at=NOW()";
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();
    foreach ($rows as $i => $r) {
        if ($i <= $hr) continue;
        $claim = trim((string)($r[$cClaim] ?? ''));
        if (!preg_match('/^\d{3,}$/', $claim)) continue;
        $res['read']++;
        $nd = $cNmiDate!==null ? trim((string)$r[$cNmiDate]) : '';
        $ndYmd = ($nd!=='' && ($ts=strtotime($nd))) ? date('Y-m-d',$ts) : null;
        $stmt->execute([
            $claim,
            $cRegion!==null?trim((string)$r[$cRegion]):null,
            $cHosp!==null?trim((string)$r[$cHosp]):null,
            $cCard!==null?trim((string)$r[$cCard]):null,
            $cBen!==null?trim((string)$r[$cBen]):null,
            $cPat!==null?trim((string)$r[$cPat]):null,
            $cIO!==null?trim((string)$r[$cIO]):null,
            $cAmt!==null?echs_amount($r[$cAmt]):0,
            $ndYmd,
            $cRem!==null?trim((string)$r[$cRem]):null,
        ]);
        if (array_key_exists($claim,$prev)) $res['updated']++; else { $res['inserted']++; $prev[$claim]='Need More Information [Portal]'; }
    }
    $pdo->commit();
    $res['ok']=true;
    return $res;
}

/** Import a Claim Settlement Report (.pdf): per-claim credited amount, TDS, BPA fees, settlement id/date. */
function echs_import_settlement_pdf($path, $original_name, array &$prev, array &$history) {
    echs_ensure_table();
    $res = ['ok'=>false,'status'=>'Claim Settlement Report','read'=>0,'inserted'=>0,'updated'=>0,'error'=>''];
    $autoload = __DIR__ . '/../lib/pdf/autoload.php';
    if (!is_file($autoload)) { $res['error']='PDF library nahi mili (lib/pdf).'; return $res; }
    require_once $autoload;
    try {
        $text = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();
    } catch (Exception $e) { $res['error']='PDF padhi nahi ja saki: '.$e->getMessage(); return $res; }
    if (!$text) { $res['error']='PDF khali/scanned lag rahi hai.'; return $res; }

    $lines = preg_split('/\R/', $text);
    $sid = '';
    $pdo = db();
    $sql = "INSERT INTO echs_claims (claim_id,settlement_id,settle_date,processed_on,processed_on_raw,accept_date,accept_date_raw,net_claim_amt,approved_amt,echs_disc,tds_amt,bpa_fees,recovery_amt,amt_credited,status,status_code,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Claim Settled','SETTLE', NOW())
        ON DUPLICATE KEY UPDATE
          settlement_id=VALUES(settlement_id), settle_date=VALUES(settle_date),
          echs_disc=VALUES(echs_disc), tds_amt=VALUES(tds_amt), bpa_fees=VALUES(bpa_fees),
          recovery_amt=VALUES(recovery_amt), amt_credited=VALUES(amt_credited),
          approved_amt=VALUES(approved_amt),
          net_claim_amt=IF(net_claim_amt=0,VALUES(net_claim_amt),net_claim_amt),
          processed_on=IF(processed_on IS NULL,VALUES(processed_on),processed_on),
          processed_on_raw=IF(processed_on_raw IS NULL OR processed_on_raw='',VALUES(processed_on_raw),processed_on_raw),
          accept_date=IF(accept_date IS NULL,VALUES(accept_date),accept_date),
          accept_date_raw=IF(accept_date_raw IS NULL OR accept_date_raw='',VALUES(accept_date_raw),accept_date_raw),
          status='Claim Settled', status_code='SETTLE', updated_at=NOW()";
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if (preg_match('/Settlement ID\s*:\s*(\d+)/i', $ln, $m)) { $sid = $m[1]; continue; }
        if (preg_match('/^(\d{2}-\d{2}-\d{4})\s+(\d{7,9})(\d{2}-\d{2}-\d{4})\s+(.+)$/', $ln, $m)) {
            $settleRaw = $m[1]; $claim = $m[2]; $acceptRaw = $m[3];
            preg_match_all('/\d+\.\d{2}/', $m[4], $am);
            $amts = $am[0];
            if (count($amts) < 7) continue;
            [$claimAmt,$appAmt,$disc,$tds,$bpa,$recov,$credit] = array_slice($amts, 0, 7);
            $res['read']++;
            $sd = DateTime::createFromFormat('d-m-Y', $settleRaw); $settleYmd = $sd?$sd->format('Y-m-d'):null;
            $ad = DateTime::createFromFormat('d-m-Y', $acceptRaw); $acceptYmd = $ad?$ad->format('Y-m-d'):null;
            $wasSettled = isset($prev[$claim]) && stripos($prev[$claim],'settled')!==false;
            $stmt->execute([$claim,$sid,$settleYmd,$settleYmd,$settleRaw,$acceptYmd,$acceptRaw,
                (float)$claimAmt,(float)$appAmt,(float)$disc,(float)$tds,(float)$bpa,(float)$recov,(float)$credit]);
            if (array_key_exists($claim,$prev)) { $res['updated']++; if(!$wasSettled) $history[]=[$claim,$prev[$claim],'Claim Settled']; }
            else { $res['inserted']++; $history[]=[$claim,null,'Claim Settled']; }
            $prev[$claim] = 'Claim Settled';
        }
    }
    $pdo->commit();
    $res['ok'] = true;
    return $res;
}

/** Import many uploaded files (.xls / .zip / .pdf). Returns summary. */
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
        } elseif ($ext === 'pdf') {
            $r = echs_import_settlement_pdf($tmp, $name, $prev, $history);
            _echs_merge($r, $name, $summary);
        } else {
            $summary['errors'][] = "$name: sirf .xls, .zip ya .pdf allowed hain.";
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
    _echs_merge($r, $name, $summary);
}

function _echs_merge(array $r, $name, array &$summary) {
    if ($r['ok']) {
        $summary['files'][] = ['name'=>$name,'status'=>$r['status'],'read'=>$r['read'],'inserted'=>$r['inserted'],'updated'=>$r['updated']];
        $summary['read'] += $r['read'];
        $summary['inserted'] += $r['inserted'];
        $summary['updated'] += $r['updated'];
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
