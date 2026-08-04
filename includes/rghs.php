<?php
/**
 * RGHS Claims module.
 *
 * Reads the "TmsBisTrack" claim report downloaded from the RGHS portal and
 * stores one row per claim in `rghs_claims` (keyed by the unique TID).
 *
 * The big Excel file is parsed in the browser (SheetJS) and uploaded to
 * api/rghs_import.php in small batches, so there is no server-side xlsx
 * parsing and no timeout on shared hosting.
 */

require_once __DIR__ . '/functions.php';

/**
 * Canonical field list. Order matters — the uploader sends each row as an
 * array in exactly this order. Each entry: [field, sql_type, [header aliases]].
 * Header matching is case-insensitive and ignores non-alphanumerics.
 */
function rghs_fields() {
    static $f = null;
    if ($f !== null) return $f;
    $f = [
        ['tid',                  "VARCHAR(40) NOT NULL",          ['TID']],
        ['sub_year',             "SMALLINT NULL",                 ['CLAIMSUBMISSIONYEAR']],
        ['sub_month',            "VARCHAR(16) NULL",              ['CLAIMSUBMISSIONMONTH']],
        ['enrollment_id',        "VARCHAR(40) NULL",              ['ENROLLMENTID']],
        ['patient_name',         "VARCHAR(160) NULL",             ['PATIENTNAME']],
        ['gender',               "VARCHAR(12) NULL",              ['GENDER']],
        ['card_no',              "VARCHAR(40) NULL",              ['RGHSCARDNO']],
        ['bis_date',             "DATE NULL",                     ['BISCREATEDDATE']],
        ['status',               "VARCHAR(90) NULL",              ['STATUS']],
        ['hospital_name',        "VARCHAR(180) NULL",             ['HOSPITALNAME']],
        ['claim_type',           "VARCHAR(24) NULL",              ['IPD_DAYCARE_OPD','IPDDAYCAREOPD']],
        ['query_status',         "VARCHAR(40) NULL",              ['QUERYSTATUS']],
        ['department',           "VARCHAR(90) NULL",              ['DEPARTMENT']],
        ['nabh',                 "VARCHAR(8) NULL",               ['NABH']],
        ['admit_date',           "DATE NULL",                     ['DATEOFADMISSION']],
        ['discharge_date',       "DATE NULL",                     ['DATEOFDISCHARGE']],
        ['los',                  "SMALLINT NULL",                 ['LENGTHOFSTAY']],
        ['claim_amt',            "DECIMAL(14,2) NOT NULL DEFAULT 0", ['HOSPITALCLAIMAMOUNT']],
        ['tpa_amt',              "DECIMAL(14,2) NOT NULL DEFAULT 0", ['TPAAPRROVEDAMOUNT','TPAAPPROVEDAMOUNT']],
        ['cu_amt',               "DECIMAL(14,2) NOT NULL DEFAULT 0", ['CUAPPROVEDAMOUNT']],
        ['submit_date',          "DATE NULL",                     ['CLAIMSUBMISSIONDATE']],
        ['tpa_action_date',      "DATE NULL",                     ['TPAFINALACTIONDATA','TPAFINALACTIONDATE']],
        ['cu_action_date',       "DATE NULL",                     ['CUFINALACTIONDATE']],
        ['discharge_submit_date',"DATE NULL",                     ['DISCHARGESUBMISSIONDATE']],
        ['tpa_remarks',          "TEXT NULL",                     ['TPAREMARKS']],
        ['cu_remarks',           "TEXT NULL",                     ['CUREMARKS']],
        ['grade',                "VARCHAR(16) NULL",              ['GRADE']],
        ['mobile',               "VARCHAR(20) NULL",              ['PATIENTMOBILENUMBER']],
        ['last_sso_id',          "VARCHAR(60) NULL",              ['LastTPAHospitalSSOID']],
        ['last_query_remark',    "TEXT NULL",                     ['LastTPAHospitalQueryRemark']],
        ['invoice_no',           "VARCHAR(60) NULL",              ['INVOICENO']],
        ['category',             "VARCHAR(90) NULL",              ['CATEGORYNAME']],
        ['package_code',         "TEXT NULL",                     ['PackageCode']],
        ['package_name',         "TEXT NULL",                     ['PackageName']],
        ['package_rate',         "TEXT NULL",                     ['PackageRate']],
        ['package_amt',          "TEXT NULL",                     ['PackageAmount']],
        ['doctor_name',          "VARCHAR(160) NULL",             ['TREATINGDOCTORNAME']],
    ];
    return $f;
}

/** Just the ordered field names. */
function rghs_field_names() {
    return array_map(function($x){ return $x[0]; }, rghs_fields());
}

/** Header aliases normalised -> field name (for the JS mapper / server checks). */
function rghs_header_map() {
    $m = [];
    foreach (rghs_fields() as $x) {
        foreach ($x[2] as $alias) {
            $m[strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $alias))] = $x[0];
        }
    }
    return $m;
}

/** Which fields are DATE / numeric (for server-side normalisation). */
function rghs_date_fields() {
    $out = [];
    foreach (rghs_fields() as $x) if (stripos($x[1], 'DATE') === 0) $out[] = $x[0];
    return $out;
}
function rghs_amount_fields() { return ['claim_amt','tpa_amt','cu_amt']; }
function rghs_int_fields()    { return ['sub_year','los']; }

/** Create / upgrade the RGHS tables (adds missing columns automatically). */
function rghs_ensure_table() {
    static $done = false;
    if ($done) return;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rghs_claims` (
        `tid` VARCHAR(40) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `rghs_claims`") as $c) $existing[strtolower($c['Field'])] = true;
    foreach (rghs_fields() as $x) {
        [$name, $type] = $x;
        if ($name === 'tid') continue;
        if (!isset($existing[strtolower($name)])) {
            $pdo->exec("ALTER TABLE `rghs_claims` ADD COLUMN `$name` $type");
        }
    }
    // bookkeeping columns
    foreach ([
        'doctor_manual' => "TINYINT(1) NOT NULL DEFAULT 0",   // reserved: was doctor set by hand
        'notes'         => "TEXT NULL",
        'followup'      => "TINYINT(1) NOT NULL DEFAULT 0",
        'first_seen'    => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at'    => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ] as $name => $def) {
        if (!isset($existing[strtolower($name)])) $pdo->exec("ALTER TABLE `rghs_claims` ADD COLUMN `$name` $def");
    }

    foreach ([
        'idx_status'=>'status','idx_type'=>'claim_type','idx_card'=>'card_no','idx_enrol'=>'enrollment_id',
        'idx_sub'=>'submit_date','idx_year'=>'sub_year','idx_doc'=>'doctor_name','idx_hosp'=>'hospital_name'
    ] as $idx => $col) {
        try { $pdo->exec("ALTER TABLE `rghs_claims` ADD INDEX `$idx` (`$col`)"); } catch (Exception $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rghs_claim_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tid` VARCHAR(40) NOT NULL,
        `from_status` VARCHAR(90) NULL,
        `to_status` VARCHAR(90) NULL,
        `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_rh_tid` (`tid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rghs_uploads` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(200) NULL,
        `rows_read` INT DEFAULT 0,
        `inserted` INT DEFAULT 0,
        `updated` INT DEFAULT 0,
        `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}

/** Parse dd/mm/yyyy or dd-mm-yyyy (RGHS uses both). Returns Y-m-d or null. */
function rghs_parse_date($v) {
    $v = trim((string)$v);
    if ($v === '' || strcasecmp($v, 'NA') === 0) return null;
    $v = str_replace('/', '-', $v);
    $d = DateTime::createFromFormat('d-m-Y', $v);
    if ($d && $d->format('d-m-Y') === $v) return $d->format('Y-m-d');
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function rghs_amount($v) {
    $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $v === '' || $v === '-' ? 0.0 : (float)$v;
}
function rghs_int($v) {
    $v = preg_replace('/[^0-9\-]/', '', (string)$v);
    return $v === '' || $v === '-' ? null : (int)$v;
}

/** Category of a status: 'approved' | 'rejected' | 'query' | 'pending'. */
function rghs_category($status) {
    $s = strtolower((string)$status);
    if (strpos($s, 'reject') !== false) return 'rejected';
    if (strpos($s, 'quer') !== false)   return 'query';
    if (strpos($s, 'approv') !== false && strpos($s, 'recommend') === false) return 'approved';
    return 'pending';   // pending / recommended / to-be-submitted / admission pending
}

/** SQL condition matching "not yet finalised" (money still to come). */
function rghs_pending_condition() {
    return "(status NOT LIKE '%APPROVED%' AND status NOT LIKE '%Approved%' AND status NOT LIKE '%REJECT%' AND status NOT LIKE '%Reject%')";
}

/** Build WHERE + args for the claims list & exports. */
function rghs_build_filter(array $g) {
    $where = []; $args = [];
    $q      = trim($g['q'] ?? '');
    $status = trim($g['status'] ?? '');
    $type   = trim($g['type'] ?? '');
    $cat    = trim($g['cat'] ?? '');
    $year   = trim($g['year'] ?? '');
    $hosp   = trim($g['hosp'] ?? '');
    $doctor = trim($g['doctor'] ?? '');
    $from   = trim($g['from'] ?? '');
    $to     = trim($g['to'] ?? '');
    $amin   = trim($g['amin'] ?? '');
    $amax   = trim($g['amax'] ?? '');

    if ($q !== '') {
        $where[] = '(tid LIKE ? OR patient_name LIKE ? OR card_no LIKE ? OR enrollment_id LIKE ? OR doctor_name LIKE ? OR mobile LIKE ?)';
        $l = "%$q%"; array_push($args, $l, $l, $l, $l, $l, $l);
    }
    if ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
    if ($type !== '')   { $where[] = 'claim_type = ?'; $args[] = $type; }
    if ($year !== '' && ctype_digit($year)) { $where[] = 'sub_year = ?'; $args[] = (int)$year; }
    if ($hosp !== '')   { $where[] = 'hospital_name = ?'; $args[] = $hosp; }
    if ($doctor !== '') { $where[] = 'doctor_name = ?'; $args[] = $doctor; }
    if ($from !== '')   { $where[] = 'submit_date >= ?'; $args[] = $from; }
    if ($to !== '')     { $where[] = 'submit_date <= ?'; $args[] = $to; }
    if ($amin !== '')   { $where[] = 'claim_amt >= ?'; $args[] = (float)$amin; }
    if ($amax !== '')   { $where[] = 'claim_amt <= ?'; $args[] = (float)$amax; }
    if ($cat === 'approved') $where[] = "(status LIKE '%APPROVED%' OR status LIKE '%Approved%')";
    elseif ($cat === 'rejected') $where[] = "(status LIKE '%REJECT%' OR status LIKE '%Reject%')";
    elseif ($cat === 'query') $where[] = "(status LIKE '%QUER%' OR status LIKE '%Quer%')";
    elseif ($cat === 'pending') $where[] = rghs_pending_condition();

    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $args];
}

/** Distinct statuses with counts (for filters). */
function rghs_status_list() {
    rghs_ensure_table();
    $out = [];
    foreach (db()->query("SELECT status, COUNT(*) n FROM rghs_claims GROUP BY status ORDER BY n DESC") as $r) $out[] = $r;
    return $out;
}
