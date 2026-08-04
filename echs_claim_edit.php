<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'claims';

$id = trim($_GET['id'] ?? '');
$c = [
    'claim_id'=>'','card_id'=>'','esm_name'=>'','patient_name'=>'','patient_type'=>'',
    'admit_type'=>'','region'=>'','hospital_name'=>'','doctor_name'=>'','accept_date_raw'=>'','processed_on_raw'=>'',
    'net_claim_amt'=>0,'approved_amt'=>0,'status'=>''
];
$isEdit = false;

if ($id !== '') {
    $q = db()->prepare("SELECT * FROM echs_claims WHERE claim_id=?"); $q->execute([$id]);
    if ($row = $q->fetch()) { $c = $row; $isEdit = true; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = trim($_POST['claim_id'] ?? '');
    $errors = [];
    if ($cid === '') $errors[] = 'Claim ID zaroori hai.';
    if (!$errors) {
        $data = [
            'card_id'=>trim($_POST['card_id']??''), 'esm_name'=>trim($_POST['esm_name']??''),
            'patient_name'=>trim($_POST['patient_name']??''), 'patient_type'=>trim($_POST['patient_type']??''),
            'admit_type'=>trim($_POST['admit_type']??''), 'region'=>trim($_POST['region']??''),
            'hospital_name'=>trim($_POST['hospital_name']??''), 'doctor_name'=>trim($_POST['doctor_name']??''),
            'accept_raw'=>trim($_POST['accept_date_raw']??''), 'proc_raw'=>trim($_POST['processed_on_raw']??''),
            'net'=>(float)($_POST['net_claim_amt']??0), 'app'=>(float)($_POST['approved_amt']??0),
            'status'=>trim($_POST['status']??''),
        ];
        db()->prepare("INSERT INTO echs_claims
            (claim_id,card_id,esm_name,patient_name,patient_type,admit_type,region,hospital_name,doctor_name,
             accept_date,accept_date_raw,processed_on,processed_on_raw,net_claim_amt,approved_amt,status,status_code,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE card_id=VALUES(card_id),esm_name=VALUES(esm_name),patient_name=VALUES(patient_name),
             patient_type=VALUES(patient_type),admit_type=VALUES(admit_type),region=VALUES(region),
             hospital_name=VALUES(hospital_name),doctor_name=VALUES(doctor_name),accept_date=VALUES(accept_date),accept_date_raw=VALUES(accept_date_raw),
             processed_on=VALUES(processed_on),processed_on_raw=VALUES(processed_on_raw),
             net_claim_amt=VALUES(net_claim_amt),approved_amt=VALUES(approved_amt),status=VALUES(status),updated_at=NOW()")
          ->execute([$cid,$data['card_id'],$data['esm_name'],$data['patient_name'],$data['patient_type'],
             $data['admit_type'],$data['region'],$data['hospital_name'],$data['doctor_name'],
             echs_parse_date($data['accept_raw']),$data['accept_raw']?:null,
             echs_parse_date($data['proc_raw']),$data['proc_raw']?:null,
             $data['net'],$data['app'],$data['status'],'MANUAL']);
        echs_log('claim_manual', $cid);
        flash('Claim save ho gaya.');
        redirect(BASE_URL . '/echs_claim.php?scheme=ECHS&id=' . urlencode($cid));
    }
}

$page_title = ($isEdit?'Edit':'New') . ' Claim';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1><?= $isEdit?'Edit':'Naya (Manual)' ?> Claim</h1></div>
<?php if (!empty($errors)): ?><div class="flash flash-error"><?php foreach($errors as $e) echo e($e).'<br>'; ?></div><?php endif; ?>

<form method="post" class="card form">
    <?= csrf_field() ?>
    <div class="grid3">
        <div class="fld"><label>Claim ID *</label><input name="claim_id" value="<?= e($c['claim_id']) ?>" <?= $isEdit?'readonly':'' ?> required></div>
        <div class="fld"><label>Card ID</label><input name="card_id" value="<?= e($c['card_id']) ?>"></div>
        <div class="fld"><label>Status</label><input name="status" value="<?= e($c['status']) ?>" placeholder="jaise Claim Settled"></div>
        <div class="fld"><label>Name of ESM</label><input name="esm_name" value="<?= e($c['esm_name']) ?>"></div>
        <div class="fld"><label>Patient Name</label><input name="patient_name" value="<?= e($c['patient_name']) ?>"></div>
        <div class="fld"><label>Patient Type</label><select name="patient_type"><?php foreach(['','I','O'] as $t):?><option <?= $c['patient_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach;?></select></div>
        <div class="fld"><label>Admit Type</label><input name="admit_type" value="<?= e($c['admit_type']) ?>"></div>
        <div class="fld"><label>Region</label><input name="region" value="<?= e($c['region']) ?>"></div>
        <div class="fld"><label>Hospital</label><input name="hospital_name" value="<?= e($c['hospital_name']) ?>"></div>
        <div class="fld"><label>Doctor</label><input name="doctor_name" list="doclist" value="<?= e($c['doctor_name'] ?? '') ?>" autocomplete="off"><datalist id="doclist"><?php foreach (echs_doctor_list() as $dn): ?><option value="<?= e($dn) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="fld"><label>Accept Date (dd-mm-yyyy)</label><input name="accept_date_raw" value="<?= e($c['accept_date_raw']) ?>"></div>
        <div class="fld"><label>Processed On (dd-mm-yyyy)</label><input name="processed_on_raw" value="<?= e($c['processed_on_raw']) ?>"></div>
        <div class="fld"><label>Net Claim Amt</label><input type="number" step="0.01" name="net_claim_amt" value="<?= e($c['net_claim_amt']) ?>"></div>
        <div class="fld"><label>Approved Amt</label><input type="number" step="0.01" name="approved_amt" value="<?= e($c['approved_amt']) ?>"></div>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary">Save Claim</button>
        <a class="btn btn-light" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
