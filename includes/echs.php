<?php
/**
 * ECHS Claims module (native).
 *
 * Reads the "CLAIMLIST" claim reports downloaded from the ECHS / UTI-ITSL
 * portal and stores one row per claim in `echs_claims` (keyed by the unique
 * Claim ID). The ECHS portal splits its export into ~18 files — one per
 * workflow stage (Claim Settled, Need More Information, Rejected …). The
 * *status* is not a column: it comes from each file's header line
 * ("<Status> as on <date>"). The uploader detects it and sends it per file.
 *
 * Excel is parsed in the browser (SheetJS, which also reads the old .xls
 * BIFF format) and uploaded to api/echs_import.php in small batches, so
 * there is no server-side spreadsheet parsing and no timeout on shared hosting.
 */

require_once __DIR__ . '/functions.php';

/**
 * Canonical field list. Order matters — the uploader sends each row as an
 * array in exactly this order. Each entry: [field, sql_type, [header aliases]].
 * Header matching is case-insensitive and ignores non-alphanumerics.
 */
function echs_fields() {
    static $f = null;
    if ($f !== null) return $f;
    $f = [
        ['claim_id',      "VARCHAR(40) NOT NULL",             ['Claim ID','ClaimID']],
        ['region',        "VARCHAR(80) NULL",                 ['Region']],
        ['hospital_name', "VARCHAR(180) NULL",                ['Hospital Name']],
        ['card_id',       "VARCHAR(40) NULL",                 ['Card ID','CardID']],
        ['esm_name',      "VARCHAR(160) NULL",                ['Name Of ESM','Name of ESM','ESM Name']],
        ['patient_name',  "VARCHAR(160) NULL",                ['Patient Name']],
        ['patient_type',  "VARCHAR(8) NULL",                  ['Patient Type']],   // O = OPD, I = IPD
        ['admit_type',    "VARCHAR(8) NULL",                  ['Admit Type']],     // R = Regular, E = Emergency
        ['accept_date',   "DATE NULL",                        ['Accept Date']],
        ['claim_amt',     "DECIMAL(14,2) NOT NULL DEFAULT 0", ['Net Claim Amt.','Net Claim Amt','Net Claim Amount']],
        ['approved_amt',  "DECIMAL(14,2) NOT NULL DEFAULT 0", ['Approved Amt.','Approved Amt','Approved Amount']],
        ['processed_on',  "DATE NULL",                        ['Processed On']],
    ];
    return $f;
}

/** Just the ordered field names. */
function echs_field_names() { return array_map(function($x){ return $x[0]; }, echs_fields()); }

/** Alias map (normalised alias -> field) for the JS mapper. */
function echs_alias_list() {
    $out = [];
    foreach (echs_fields() as $x) {
        $out[] = ['f' => $x[0], 'a' => array_map(function($s){ return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)); }, $x[2])];
    }
    return $out;
}

/** Header aliases normalised -> field name. */
function echs_header_map() {
    $m = [];
    foreach (echs_fields() as $x) {
        foreach ($x[2] as $alias) $m[strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $alias))] = $x[0];
    }
    return $m;
}

function echs_date_fields()   { $o=[]; foreach (echs_fields() as $x) if (stripos($x[1],'DATE')===0) $o[]=$x[0]; return $o; }
function echs_amount_fields() { return ['claim_amt','approved_amt']; }

/** Create / upgrade the ECHS tables (adds missing columns automatically). */
function echs_ensure_table() {
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_claims` (
        `claim_id` VARCHAR(40) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // add all canonical columns
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `echs_claims`") as $c) $existing[strtolower($c['Field'])] = true;
    foreach (echs_fields() as $x) {
        if ($x[0] === 'claim_id') continue;
        if (!isset($existing[strtolower($x[0])])) $pdo->exec("ALTER TABLE `echs_claims` ADD COLUMN `{$x[0]}` {$x[1]}");
    }
    // extra (non-Excel) columns
    foreach ([
        'status'        => "VARCHAR(90) NULL",              // derived from the file header line
        'stage_order'   => "SMALLINT NOT NULL DEFAULT 0",   // lifecycle position (for aging / funnel)
        'category'      => "VARCHAR(20) NULL",              // settled/inprocess/query/rejected/cancelled/pending
        'doctor_name'   => "VARCHAR(160) NULL",
        'doctor_manual' => "TINYINT(1) NOT NULL DEFAULT 0",
        'notes'         => "TEXT NULL",
        'followup'      => "TINYINT(1) NOT NULL DEFAULT 0",
        'assigned_to'   => "VARCHAR(120) NULL",
        'dupe_flag'     => "TINYINT(1) NOT NULL DEFAULT 0",
        'first_seen'    => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at'    => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ] as $name => $def) {
        if (!isset($existing[strtolower($name)])) $pdo->exec("ALTER TABLE `echs_claims` ADD COLUMN `$name` $def");
    }
    // helpful indexes (ignore if they already exist)
    foreach ([
        'idx_ec_status' => 'status', 'idx_ec_card' => 'card_id', 'idx_ec_cat' => 'category',
        'idx_ec_accept' => 'accept_date', 'idx_ec_doctor' => 'doctor_name',
    ] as $idx => $col) {
        try { $pdo->exec("ALTER TABLE `echs_claims` ADD INDEX `$idx` (`$col`)"); } catch (Exception $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_claim_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(40) NOT NULL,
        `from_status` VARCHAR(90) NULL,
        `to_status` VARCHAR(90) NULL,
        `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ech_cid` (`claim_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_uploads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(200) NULL,
        `status_label` VARCHAR(90) NULL,
        `rows` INT DEFAULT 0,
        `who` VARCHAR(120) NULL,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_doctors` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(160) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_ed_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_tasks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(40) NULL,
        `title` VARCHAR(200) NOT NULL,
        `done` TINYINT(1) NOT NULL DEFAULT 0,
        `due_date` DATE NULL,
        `who` VARCHAR(120) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_et_done` (`done`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_notes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(40) NOT NULL,
        `note` TEXT NOT NULL,
        `who` VARCHAR(120) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_en_cid` (`claim_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_docs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(40) NOT NULL,
        `stored_name` VARCHAR(80) NOT NULL,
        `orig_name` VARCHAR(200) NOT NULL,
        `mime` VARCHAR(100) NULL,
        `bytes` INT DEFAULT 0,
        `who` VARCHAR(120) NULL,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ed2_cid` (`claim_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_queries` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `claim_id` VARCHAR(40) NOT NULL,
        `query_text` TEXT NULL,
        `reply_text` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'open',
        `raised_on` DATE NULL,
        `replied_on` DATE NULL,
        `who` VARCHAR(120) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_eq_cid` (`claim_id`),
        KEY `idx_eq_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_targets` (
        `ym` CHAR(7) NOT NULL PRIMARY KEY,
        `claims_target` INT DEFAULT 0,
        `amount_target` DECIMAL(14,2) DEFAULT 0,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_activity` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `who` VARCHAR(120) NULL,
        `action` VARCHAR(80) NULL,
        `detail` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_ea_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `echs_backups` (
        `snap_date` DATE NOT NULL PRIMARY KEY,
        `payload` LONGBLOB NULL,
        `claims_n` INT DEFAULT 0,
        `bytes` INT DEFAULT 0,
        `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/** Parse dd-mm-yyyy or dd/mm/yyyy (ECHS uses dashes) or Excel serial. Returns Y-m-d or null. */
function echs_parse_date($v) {
    $v = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)$v);
    $v = trim($v);
    if ($v === '' || strcasecmp($v, 'NA') === 0 || $v === '-') return null;
    if (ctype_digit($v)) {                 // Excel date serial
        $n = (int)$v;
        if ($n >= 20000 && $n <= 80000) {
            $d = new DateTime('1899-12-30'); $d->modify("+$n days"); return $d->format('Y-m-d');
        }
    }
    $part = preg_split('/\s+/', $v)[0];     // drop any time portion
    $part = str_replace('/', '-', $part);
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $part, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $part, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function echs_amount($v) {
    $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $v === '' || $v === '-' ? 0.0 : (float)$v;
}

/** Strip the "as on <date>" suffix and surrounding whitespace from a status label. */
function echs_status_clean($s) {
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)$s);
    $s = preg_replace('/\s+as on\s+.*$/i', '', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Lifecycle order of an ECHS status (bigger = later in the workflow).
 * Rejections/cancellations get high numbers so they sort as "finished".
 */
function echs_stage_order($status) {
    $s = strtolower(echs_status_clean($status));
    $map = [
        'admission intimation pending'   => 1,
        'admission intimation submitted' => 2,
        'intimation acknowledged'        => 3,
        'emergency intimation rejected'  => 4,
        'patient referral'               => 5,
        'claim submission pending'       => 6,
        'review by validator'            => 7,
        'need more information'          => 8,
        'scrutinizer verified'           => 9,
        'claim authorized'               => 10,
        'recommended for approval'       => 11,
        'recommended for rejection'      => 12,
        'processed for settlement'       => 13,
        'rejected claims processed'      => 14,
        'cancel claim'                   => 15,
        'claim settled'                  => 16,
    ];
    foreach ($map as $key => $ord) if (strpos($s, $key) !== false) return $ord;
    return 0;
}

/** Category of a status: settled | inprocess | query | rejected | cancelled | pending. */
function echs_category($status) {
    $s = strtolower(echs_status_clean($status));
    if (strpos($s, 'settled') !== false) return 'settled';
    if (strpos($s, 'cancel') !== false) return 'cancelled';
    if (strpos($s, 'reject') !== false) return 'rejected';
    if (strpos($s, 'need more information') !== false || strpos($s, 'review by validator') !== false) return 'query';
    if (strpos($s, 'scrutinizer') !== false || strpos($s, 'authorized') !== false
        || strpos($s, 'recommended for approval') !== false || strpos($s, 'processed for settlement') !== false) return 'inprocess';
    return 'pending';
}

/** Human OPD/IPD label from patient_type code. */
function echs_ptype($code) {
    $c = strtoupper(trim((string)$code));
    if ($c === 'O') return 'OPD';
    if ($c === 'I') return 'IPD';
    return $c ?: '—';
}
/** Human admit label from admit_type code. */
function echs_atype($code) {
    $c = strtoupper(trim((string)$code));
    if ($c === 'R') return 'Regular';
    if ($c === 'E') return 'Emergency';
    return $c ?: '—';
}

/** SQL condition matching "not yet finalised" (claim still moving, no final outcome). */
function echs_pending_condition() {
    return "(category IS NULL OR category IN ('pending','inprocess','query'))";
}

/** Build WHERE + args for the claims list & exports. */
function echs_build_filter(array $g) {
    $where = []; $args = [];
    $q      = trim($g['q'] ?? '');
    $status = trim($g['status'] ?? '');
    $type   = trim($g['type'] ?? '');
    $cat    = trim($g['cat'] ?? '');
    $region = trim($g['region'] ?? '');
    $doctor = trim($g['doctor'] ?? '');
    $from   = trim($g['from'] ?? '');
    $to     = trim($g['to'] ?? '');
    $amin   = trim($g['amin'] ?? '');
    $amax   = trim($g['amax'] ?? '');

    if ($q !== '') {
        [$sc, $sa] = smart_search(
            $q,
            ['claim_id','patient_name','esm_name','card_id','doctor_name','region','status'],
            [
                'id'      => ['claim_id','like'],   'claim'  => ['claim_id','like'],
                'card'    => ['card_id','like'],
                'esm'     => ['esm_name','like'],
                'patient' => ['patient_name','like'], 'pt' => ['patient_name','like'],
                'doctor'  => ['doctor_name','like'], 'dr' => ['doctor_name','like'],
                'status'  => ['status','like'],     'st'  => ['status','like'],
                'region'  => ['region','like'],     'rg'  => ['region','like'],
                'hosp'    => ['hospital_name','like'],
                'cat'     => ['category','eq'],
                'type'    => [null, function($v){ $u=strtoupper(trim($v)); $c=($u==='OPD'||$u==='O')?'O':(($u==='IPD'||$u==='I')?'I':$u); return ['patient_type = ?', [$c]]; }],
            ],
            'claim_amt',
            'DATEDIFF(CURDATE(),accept_date)'
        );
        foreach ($sc as $c) $where[] = $c;
        foreach ($sa as $a) $args[] = $a;
    }
    if ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
    if ($type !== '')   { $where[] = 'patient_type = ?'; $args[] = $type; }
    if ($region !== '') { $where[] = 'region = ?'; $args[] = $region; }
    if ($doctor !== '') { $where[] = 'doctor_name = ?'; $args[] = $doctor; }
    if ($from !== '')   { $where[] = 'accept_date >= ?'; $args[] = $from; }
    if ($to !== '')     { $where[] = 'accept_date <= ?'; $args[] = $to; }
    if ($amin !== '')   { $where[] = 'claim_amt >= ?'; $args[] = (float)$amin; }
    if ($amax !== '')   { $where[] = 'claim_amt <= ?'; $args[] = (float)$amax; }
    if (in_array($cat, ['settled','inprocess','query','rejected','cancelled','pending'], true)) {
        $where[] = 'category = ?'; $args[] = $cat;
    }

    $flag = trim($g['flag'] ?? '');
    if ($flag === 'nodoctor') $where[] = "(doctor_name IS NULL OR doctor_name = '')";
    elseif ($flag === 'followup') $where[] = "followup = 1";
    elseif ($flag === 'dupe') $where[] = "dupe_flag = 1";
    elseif ($flag === 'hasdoc') $where[] = "EXISTS (SELECT 1 FROM echs_docs d WHERE d.claim_id = echs_claims.claim_id COLLATE utf8mb4_unicode_ci)";
    elseif ($flag === 'noteflag') $where[] = "(notes IS NOT NULL AND notes <> '')";
    elseif ($flag === 'hasquery') $where[] = "EXISTS (SELECT 1 FROM echs_queries qq WHERE qq.claim_id = echs_claims.claim_id COLLATE utf8mb4_unicode_ci AND qq.status <> 'closed')";

    $assignee = trim($g['assignee'] ?? '');
    if ($assignee === '__none') $where[] = "(assigned_to IS NULL OR assigned_to = '')";
    elseif ($assignee !== '') { $where[] = 'assigned_to = ?'; $args[] = $assignee; }

    $age = trim($g['age'] ?? '');
    if ($age !== '' && ctype_digit($age)) { $where[] = "accept_date IS NOT NULL AND DATEDIFF(CURDATE(),accept_date) > ?"; $args[] = (int)$age; }

    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $args];
}

/** Distinct statuses with counts (for filters). */
function echs_status_list() {
    echs_ensure_table();
    $out = [];
    foreach (db()->query("SELECT status, COUNT(*) n FROM echs_claims WHERE status IS NOT NULL GROUP BY status ORDER BY n DESC") as $r) $out[] = $r;
    return $out;
}

/** Staff users (for assignment dropdowns). */
function echs_staff_list() {
    $out = [];
    try { foreach (db()->query("SELECT full_name FROM users WHERE is_active=1 ORDER BY full_name") as $r) if (!empty($r['full_name'])) $out[] = $r['full_name']; } catch (Exception $e) {}
    return $out;
}

/** Get the monthly target. */
function echs_target($ym) {
    try { $s = db()->prepare("SELECT claims_target, amount_target FROM echs_targets WHERE ym=?"); $s->execute([$ym]); return $s->fetch() ?: ['claims_target'=>0,'amount_target'=>0]; }
    catch (Exception $e) { return ['claims_target'=>0,'amount_target'=>0]; }
}

/** Log an ECHS activity row (best effort). */
function echs_log($action, $detail = '') {
    try {
        echs_ensure_table();
        $u = function_exists('current_user') ? (current_user()['full_name'] ?? 'system') : 'system';
        db()->prepare("INSERT INTO echs_activity (who,action,detail) VALUES (?,?,?)")
            ->execute([$u, $action, mb_substr((string)$detail, 0, 255)]);
    } catch (Exception $e) {}
}

/** All known ECHS doctor names (master + used in claims). */
function echs_doctor_list() {
    echs_ensure_table();
    $set = [];
    try {
        foreach (db()->query("SELECT name FROM echs_doctors ORDER BY name") as $r) $set[$r['name']] = true;
        foreach (db()->query("SELECT DISTINCT doctor_name FROM echs_claims WHERE doctor_name IS NOT NULL AND doctor_name<>''") as $r) $set[$r['doctor_name']] = true;
    } catch (Exception $e) {}
    $out = array_keys($set); sort($out); return $out;
}

/** Build a full export array for backup/download. */
function echs_export_all() {
    echs_ensure_table();
    $pdo = db();
    $claims = $pdo->query("SELECT * FROM echs_claims")->fetchAll();
    return ['app'=>'ECHS Tracker', 'exported_at'=>date('c'), 'claims'=>$claims];
}

/** Ensure today's backup snapshot exists (best-effort). */
function echs_daily_snapshot() {
    echs_ensure_table();
    $pdo = db();
    try {
        $has = $pdo->query("SELECT 1 FROM echs_backups WHERE snap_date = CURDATE()")->fetch();
        if ($has) return false;
        $data = echs_export_all();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $gz = function_exists('gzencode') ? gzencode($json, 6) : $json;
        $pdo->prepare("INSERT INTO echs_backups (snap_date, payload, claims_n, bytes)
            VALUES (CURDATE(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE payload=VALUES(payload), claims_n=VALUES(claims_n), bytes=VALUES(bytes), saved_at=NOW()")
            ->execute([$gz, count($data['claims']), strlen($gz)]);
        $pdo->prepare("DELETE FROM echs_backups WHERE snap_date < (CURDATE() - INTERVAL 20 DAY)")->execute();
        return true;
    } catch (Exception $e) { return false; }
}
