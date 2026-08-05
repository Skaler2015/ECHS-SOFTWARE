<?php
/**
 * RGHS Medical Store (Pharmacy) module.
 *
 * Reads the "Pharmacy Invoice Tracker" report downloaded from the RGHS portal
 * and stores one row per pharmacy invoice in `store_claims` (keyed by the
 * Invoice No). Each invoice also carries the RGHS Transaction Id (TID), so a
 * store invoice can be cross-linked to the main RGHS claim.
 *
 * The Excel is parsed in the browser (SheetJS) and uploaded to
 * api/store_import.php in small batches — no server-side xlsx parsing.
 */

require_once __DIR__ . '/functions.php';

/** Canonical field list: [field, sql_type, [header aliases]]. Order matters. */
function store_fields() {
    static $f = null;
    if ($f !== null) return $f;
    $f = [
        ['invoice_no',    "VARCHAR(90) NOT NULL",             ['Invoice No.','Invoice No','InvoiceNo']],
        ['invoice_file',  "TEXT NULL",                        ['Invoice File']],
        ['sub_year',      "SMALLINT NULL",                    ['Claim Submission Year']],
        ['sub_month',     "VARCHAR(16) NULL",                 ['Claim Submission Month']],
        ['tid',           "VARCHAR(40) NULL",                 ['Transaction Id','TID']],
        ['enrollment_id', "VARCHAR(40) NULL",                 ['Enrollment Id','Enrollment ID']],
        ['patient_name',  "VARCHAR(160) NULL",                ['Patient Name']],
        ['gender',        "VARCHAR(12) NULL",                 ['Gender']],
        ['card_no',       "VARCHAR(40) NULL",                 ['RGHS Card No.','RGHS Card No']],
        ['status',        "VARCHAR(90) NULL",                 ['Status']],
        ['claim_amt',     "DECIMAL(14,2) NOT NULL DEFAULT 0", ['Pharmacy Claim Amount']],
        ['tpa_amt',       "DECIMAL(14,2) NOT NULL DEFAULT 0", ['TPA Aprroved Amount','TPA Approved Amount']],
        ['view_process',  "TEXT NULL",                        ['View Process Sheet']],
        ['cu_amt',        "DECIMAL(14,2) NOT NULL DEFAULT 0", ['CU Aprroved Amount','CU Approved Amount']],
        ['submit_date',   "DATE NULL",                        ['Claim Submission Date']],
        ['tpa_remark',    "TEXT NULL",                        ['TPA Remark']],
        ['cu_remark',     "TEXT NULL",                        ['CU Remark']],
        ['mobile',        "VARCHAR(20) NULL",                 ['Mobile No','Mobile']],
    ];
    return $f;
}

function store_field_names() { return array_map(function($x){ return $x[0]; }, store_fields()); }

function store_alias_list() {
    $out = [];
    foreach (store_fields() as $x) {
        $out[] = ['f' => $x[0], 'a' => array_map(function($s){ return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)); }, $x[2])];
    }
    return $out;
}

function store_header_map() {
    $m = [];
    foreach (store_fields() as $x) foreach ($x[2] as $a) $m[strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $a))] = $x[0];
    return $m;
}

function store_date_fields()   { $o=[]; foreach (store_fields() as $x) if (stripos($x[1],'DATE')===0) $o[]=$x[0]; return $o; }
function store_amount_fields() { return ['claim_amt','tpa_amt','cu_amt']; }
function store_int_fields()    { return ['sub_year']; }

/** Create / upgrade the store tables. */
function store_ensure_table() {
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_claims` (
        `invoice_no` VARCHAR(90) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `store_claims`") as $c) $existing[strtolower($c['Field'])] = true;
    foreach (store_fields() as $x) {
        if ($x[0] === 'invoice_no') continue;
        if (!isset($existing[strtolower($x[0])])) $pdo->exec("ALTER TABLE `store_claims` ADD COLUMN `{$x[0]}` {$x[1]}");
    }
    foreach ([
        'category'    => "VARCHAR(20) NULL",
        'notes'       => "TEXT NULL",
        'followup'    => "TINYINT(1) NOT NULL DEFAULT 0",
        'assigned_to' => "VARCHAR(120) NULL",
        'dupe_flag'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'first_seen'  => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at'  => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ] as $name => $def) {
        if (!isset($existing[strtolower($name)])) $pdo->exec("ALTER TABLE `store_claims` ADD COLUMN `$name` $def");
    }
    foreach (['idx_sc_status'=>'status','idx_sc_tid'=>'tid','idx_sc_card'=>'card_no','idx_sc_cat'=>'category','idx_sc_submit'=>'submit_date'] as $idx=>$col) {
        try { $pdo->exec("ALTER TABLE `store_claims` ADD INDEX `$idx` (`$col`)"); } catch (Exception $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_claim_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_no` VARCHAR(90) NOT NULL,
        `from_status` VARCHAR(90) NULL,
        `to_status` VARCHAR(90) NULL,
        `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sch_inv` (`invoice_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_uploads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(200) NULL,
        `rows_read` INT DEFAULT 0,
        `inserted` INT DEFAULT 0,
        `updated` INT DEFAULT 0,
        `who` VARCHAR(120) NULL,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_notes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `invoice_no` VARCHAR(90) NOT NULL,
        `note` TEXT NOT NULL,
        `who` VARCHAR(120) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sn_inv` (`invoice_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_activity` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `who` VARCHAR(120) NULL,
        `action` VARCHAR(80) NULL,
        `detail` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sa_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_backups` (
        `snap_date` DATE NOT NULL PRIMARY KEY,
        `payload` LONGBLOB NULL,
        `claims_n` INT DEFAULT 0,
        `bytes` INT DEFAULT 0,
        `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_targets` (
        `ym` CHAR(7) NOT NULL PRIMARY KEY,
        `claims_target` INT DEFAULT 0,
        `amount_target` DECIMAL(14,2) DEFAULT 0,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/** Parse dd-mm-yyyy / dd/mm/yyyy or Excel serial. */
function store_parse_date($v) {
    $v = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)$v);
    $v = trim($v);
    if ($v === '' || strcasecmp($v, 'NA') === 0) return null;
    if (ctype_digit($v)) { $n=(int)$v; if ($n>=20000 && $n<=80000){ $d=new DateTime('1899-12-30'); $d->modify("+$n days"); return $d->format('Y-m-d'); } }
    $part = preg_split('/\s+/', $v)[0]; $part = str_replace('/', '-', $part);
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $part, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $part, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    $ts = strtotime($v); return $ts ? date('Y-m-d', $ts) : null;
}
function store_amount($v) { $v = preg_replace('/[^0-9.\-]/', '', (string)$v); return $v===''||$v==='-'?0.0:(float)$v; }
function store_int($v)    { $v = preg_replace('/[^0-9\-]/', '', (string)$v); return $v===''||$v==='-'?null:(int)$v; }

/** Category: approved | pending | query | rejected | deleted. */
function store_category($status) {
    $s = strtolower((string)$status);
    if (strpos($s,'delet') !== false) return 'deleted';
    if (strpos($s,'reject') !== false) return 'rejected';
    if (strpos($s,'quer') !== false) return 'query';
    if (strpos($s,'approv') !== false) return 'approved';
    return 'pending';
}

/** SQL: still-open invoices (not approved / rejected / deleted). */
function store_pending_condition() {
    return "(status NOT LIKE '%APPROV%' AND status NOT LIKE '%Approv%' AND status NOT LIKE '%REJECT%' AND status NOT LIKE '%Reject%' AND status NOT LIKE '%DELET%' AND status NOT LIKE '%Delet%')";
}

/** Build WHERE + args for list & exports. */
function store_build_filter(array $g) {
    $where = []; $args = [];
    $q = trim($g['q'] ?? ''); $status = trim($g['status'] ?? ''); $cat = trim($g['cat'] ?? '');
    $year = trim($g['year'] ?? ''); $from = trim($g['from'] ?? ''); $to = trim($g['to'] ?? '');
    $amin = trim($g['amin'] ?? ''); $amax = trim($g['amax'] ?? '');

    if ($q !== '') {
        [$sc,$sa] = smart_search($q,
            ['invoice_no','tid','patient_name','card_no','enrollment_id','mobile','status'],
            [
                'invoice'=>['invoice_no','like'], 'inv'=>['invoice_no','like'],
                'tid'=>['tid','like'], 'card'=>['card_no','like'],
                'patient'=>['patient_name','like'], 'pt'=>['patient_name','like'],
                'enroll'=>['enrollment_id','like'], 'mobile'=>['mobile','like'], 'mob'=>['mobile','like'],
                'status'=>['status','like'], 'st'=>['status','like'], 'year'=>['sub_year','eq'],
            ], 'claim_amt', 'DATEDIFF(CURDATE(),submit_date)');
        foreach ($sc as $c) $where[] = $c; foreach ($sa as $a) $args[] = $a;
    }
    if ($status !== '') { $where[]='status = ?'; $args[]=$status; }
    if ($year !== '' && ctype_digit($year)) { $where[]='sub_year = ?'; $args[]=(int)$year; }
    if ($from !== '') { $where[]='submit_date >= ?'; $args[]=$from; }
    if ($to !== '')   { $where[]='submit_date <= ?'; $args[]=$to; }
    if ($amin !== '') { $where[]='claim_amt >= ?'; $args[]=(float)$amin; }
    if ($amax !== '') { $where[]='claim_amt <= ?'; $args[]=(float)$amax; }
    if (in_array($cat, ['approved','pending','query','rejected','deleted'], true)) { $where[]='category = ?'; $args[]=$cat; }

    $flag = trim($g['flag'] ?? '');
    if ($flag === 'followup') $where[] = "followup = 1";
    elseif ($flag === 'noteflag') $where[] = "(notes IS NOT NULL AND notes <> '')";
    elseif ($flag === 'dupe') $where[] = "dupe_flag = 1";

    $assignee = trim($g['assignee'] ?? '');
    if ($assignee === '__none') $where[] = "(assigned_to IS NULL OR assigned_to='')";
    elseif ($assignee !== '') { $where[]='assigned_to = ?'; $args[]=$assignee; }

    $age = trim($g['age'] ?? '');
    if ($age !== '' && ctype_digit($age)) { $where[]="submit_date IS NOT NULL AND DATEDIFF(CURDATE(),submit_date) > ?"; $args[]=(int)$age; }

    return [$where ? ('WHERE '.implode(' AND ', $where)) : '', $args];
}

function store_status_list() {
    store_ensure_table(); $out = [];
    foreach (db()->query("SELECT status, COUNT(*) n FROM store_claims WHERE status IS NOT NULL GROUP BY status ORDER BY n DESC") as $r) $out[] = $r;
    return $out;
}
function store_staff_list() {
    $out = [];
    try { foreach (db()->query("SELECT full_name FROM users WHERE is_active=1 ORDER BY full_name") as $r) if (!empty($r['full_name'])) $out[]=$r['full_name']; } catch (Exception $e) {}
    return $out;
}
function store_target($ym) {
    try { $s=db()->prepare("SELECT claims_target, amount_target FROM store_targets WHERE ym=?"); $s->execute([$ym]); return $s->fetch() ?: ['claims_target'=>0,'amount_target'=>0]; }
    catch (Exception $e) { return ['claims_target'=>0,'amount_target'=>0]; }
}
function store_log($action, $detail = '') {
    try { store_ensure_table(); $u=function_exists('current_user')?(current_user()['full_name']??'system'):'system';
        db()->prepare("INSERT INTO store_activity (who,action,detail) VALUES (?,?,?)")->execute([$u,$action,mb_substr((string)$detail,0,255)]); }
    catch (Exception $e) {}
}
function store_export_all() {
    store_ensure_table();
    return ['app'=>'RGHS Medical Store','exported_at'=>date('c'),'claims'=>db()->query("SELECT * FROM store_claims")->fetchAll()];
}
function store_daily_snapshot() {
    store_ensure_table(); $pdo = db();
    try {
        if ($pdo->query("SELECT 1 FROM store_backups WHERE snap_date=CURDATE()")->fetch()) return false;
        $data = store_export_all(); $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $gz = function_exists('gzencode') ? gzencode($json, 6) : $json;
        $pdo->prepare("INSERT INTO store_backups (snap_date,payload,claims_n,bytes) VALUES (CURDATE(),?,?,?)
            ON DUPLICATE KEY UPDATE payload=VALUES(payload), claims_n=VALUES(claims_n), bytes=VALUES(bytes), saved_at=NOW()")
            ->execute([$gz, count($data['claims']), strlen($gz)]);
        $pdo->prepare("DELETE FROM store_backups WHERE snap_date < (CURDATE() - INTERVAL 20 DAY)")->execute();
        return true;
    } catch (Exception $e) { return false; }
}
