<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$active = 'bills';

$id = (int)($_GET['id'] ?? 0);
$preselect_patient = (int)($_GET['patient_id'] ?? 0);

$bill = [
    'id'=>0,'scheme'=>$scheme,'bill_no'=>next_bill_no($scheme),'patient_id'=>$preselect_patient,
    'patient_name'=>'','card_no'=>'','doctor_name'=>'','hospital'=>'','prescription_date'=>'',
    'bill_date'=>date('Y-m-d'),'referral_no'=>'','discount'=>0,'status'=>'Pending','remarks'=>''
];
$items = [];

// Preselect patient data
if ($preselect_patient && !$id) {
    $q = db()->prepare('SELECT * FROM patients WHERE id=? AND scheme=?');
    $q->execute([$preselect_patient, $scheme]);
    if ($row = $q->fetch()) { $bill['patient_name']=$row['name']; $bill['card_no']=$row['card_no']; }
}

// ---- Save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $bill['patient_id']       = (int)($_POST['patient_id'] ?? 0) ?: null;
    $bill['bill_no']          = trim($_POST['bill_no'] ?? '');
    $bill['patient_name']     = trim($_POST['patient_name'] ?? '');
    $bill['card_no']          = trim($_POST['card_no'] ?? '');
    $bill['doctor_name']      = trim($_POST['doctor_name'] ?? '');
    $bill['hospital']         = trim($_POST['hospital'] ?? '');
    $bill['prescription_date']= $_POST['prescription_date'] ?: null;
    $bill['bill_date']        = $_POST['bill_date'] ?: date('Y-m-d');
    $bill['referral_no']      = trim($_POST['referral_no'] ?? '');
    $bill['discount']         = (float)($_POST['discount'] ?? 0);
    $bill['status']           = $_POST['status'] ?? 'Pending';
    $bill['remarks']          = trim($_POST['remarks'] ?? '');

    // items
    $meds = $_POST['medicine'] ?? [];
    $sub  = 0;
    foreach ($meds as $i => $mname) {
        $mname = trim($mname);
        if ($mname === '') continue;
        $qty  = (float)($_POST['qty'][$i] ?? 0);
        $rate = (float)($_POST['rate'][$i] ?? 0);
        $amt  = round($qty * $rate, 2);
        $sub += $amt;
        $items[] = [
            'medicine'=>$mname,'batch'=>trim($_POST['batch'][$i] ?? ''),'expiry'=>trim($_POST['expiry'][$i] ?? ''),
            'qty'=>$qty,'rate'=>$rate,'amount'=>$amt
        ];
    }
    $total = max(0, $sub - $bill['discount']);

    $errors = [];
    if ($bill['patient_name']==='') $errors[] = 'Patient name zaroori hai.';
    if (!$items)                    $errors[] = 'Kam se kam ek medicine add karein.';
    if ($bill['bill_no']==='')      $errors[] = 'Bill number zaroori hai.';

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare('UPDATE bills SET bill_no=?,patient_id=?,patient_name=?,card_no=?,doctor_name=?,hospital=?,prescription_date=?,bill_date=?,referral_no=?,sub_total=?,discount=?,total_amount=?,status=?,remarks=? WHERE id=? AND scheme=?')
                    ->execute([$bill['bill_no'],$bill['patient_id'],$bill['patient_name'],$bill['card_no'],$bill['doctor_name'],$bill['hospital'],$bill['prescription_date'],$bill['bill_date'],$bill['referral_no'],$sub,$bill['discount'],$total,$bill['status'],$bill['remarks'],$id,$scheme]);
                $pdo->prepare('DELETE FROM bill_items WHERE bill_id=?')->execute([$id]);
                $bill_id = $id;
            } else {
                $pdo->prepare('INSERT INTO bills (scheme,bill_no,patient_id,patient_name,card_no,doctor_name,hospital,prescription_date,bill_date,referral_no,sub_total,discount,total_amount,status,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$scheme,$bill['bill_no'],$bill['patient_id'],$bill['patient_name'],$bill['card_no'],$bill['doctor_name'],$bill['hospital'],$bill['prescription_date'],$bill['bill_date'],$bill['referral_no'],$sub,$bill['discount'],$total,$bill['status'],$bill['remarks'],current_user()['id']]);
                $bill_id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO bill_items (bill_id,medicine,batch,expiry,qty,rate,amount) VALUES (?,?,?,?,?,?,?)');
            foreach ($items as $it) {
                $ins->execute([$bill_id,$it['medicine'],$it['batch'],$it['expiry'],$it['qty'],$it['rate'],$it['amount']]);
                // add to medicine master if new
                $chk = $pdo->prepare('SELECT id FROM medicines WHERE name=? LIMIT 1');
                $chk->execute([$it['medicine']]);
                if (!$chk->fetch()) {
                    $pdo->prepare('INSERT INTO medicines (name,mrp) VALUES (?,?)')->execute([$it['medicine'],$it['rate']]);
                }
            }
            $pdo->commit();
            flash('Bill save ho gaya (' . $bill['bill_no'] . ').');
            redirect(BASE_URL . '/bill_print.php?id=' . $bill_id);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Save karne me error: duplicate bill number ho sakta hai.';
        }
    }
}

// ---- Load for edit ----
if ($id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = db()->prepare('SELECT * FROM bills WHERE id=? AND scheme=?');
    $q->execute([$id, $scheme]);
    $row = $q->fetch();
    if (!$row) { flash('Bill nahi mila.', 'error'); redirect(BASE_URL.'/bills.php?scheme='.$scheme); }
    $bill = $row;
    $qi = db()->prepare('SELECT * FROM bill_items WHERE bill_id=? ORDER BY id');
    $qi->execute([$id]);
    $items = $qi->fetchAll();
}
if (empty($items)) $items = [['medicine'=>'','batch'=>'','expiry'=>'','qty'=>1,'rate'=>0,'amount'=>0]];

// patients list for dropdown
$plist = [];
try { $pq = db()->prepare('SELECT id,name,card_no FROM patients WHERE scheme=? ORDER BY name LIMIT 500'); $pq->execute([$scheme]); $plist = $pq->fetchAll(); } catch (Exception $e) {}
// medicine names for datalist
$mnames = [];
try { foreach (db()->query('SELECT name FROM medicines ORDER BY name LIMIT 1000') as $m) $mnames[] = $m['name']; } catch (Exception $e) {}

$page_title = ($id ? 'Edit' : 'New') . ' Bill';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1><?= $id ? 'Edit' : 'New' ?> Bill / Claim — <?= e($meta['short']) ?></h1></div>

<?php if (!empty($errors)): ?>
<div class="flash flash-error"><?php foreach($errors as $er) echo e($er).'<br>'; ?></div>
<?php endif; ?>

<form method="post" class="card form" id="billForm">
    <?= csrf_field() ?>
    <input type="hidden" name="scheme" value="<?= $scheme ?>">

    <div class="grid3">
        <div class="fld"><label>Bill No *</label><input name="bill_no" value="<?= e($bill['bill_no']) ?>" required></div>
        <div class="fld"><label>Bill Date *</label><input type="date" name="bill_date" value="<?= e($bill['bill_date']) ?>" required></div>
        <div class="fld"><label>Status</label>
            <select name="status">
                <?php foreach (['Pending','Submitted','Paid','Rejected'] as $s): ?>
                    <option value="<?= $s ?>" <?= $bill['status']===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fld col3">
            <label>Select existing patient (optional)</label>
            <select id="patientPicker">
                <option value="">-- naya / manual bharein --</option>
                <?php foreach ($plist as $pp): ?>
                    <option value="<?= $pp['id'] ?>" data-name="<?= e($pp['name']) ?>" data-card="<?= e($pp['card_no']) ?>" <?= (int)$bill['patient_id']===(int)$pp['id']?'selected':'' ?>>
                        <?= e($pp['name']) ?> — <?= e($pp['card_no']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="patient_id" id="patient_id" value="<?= e($bill['patient_id']) ?>">
        <div class="fld"><label>Patient Name *</label><input name="patient_name" id="patient_name" value="<?= e($bill['patient_name']) ?>" required></div>
        <div class="fld"><label><?= e($meta['card_label']) ?></label><input name="card_no" id="card_no" value="<?= e($bill['card_no']) ?>"></div>
        <div class="fld"><label>Doctor Name</label><input name="doctor_name" value="<?= e($bill['doctor_name']) ?>"></div>
        <div class="fld"><label>Hospital / Polyclinic</label><input name="hospital" value="<?= e($bill['hospital']) ?>"></div>
        <div class="fld"><label>Prescription Date</label><input type="date" name="prescription_date" value="<?= e($bill['prescription_date']) ?>"></div>
        <div class="fld"><label><?= $scheme==='ECHS'?'Referral No.':'Approval / Ref No.' ?></label><input name="referral_no" value="<?= e($bill['referral_no']) ?>"></div>
    </div>

    <h3 class="section-title">Medicines / Items</h3>
    <table class="tbl items-tbl" id="itemsTable">
        <thead><tr>
            <th style="width:34%">Medicine</th><th>Batch</th><th>Expiry</th>
            <th style="width:80px">Qty</th><th style="width:110px">Rate</th><th style="width:120px" class="r">Amount</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr class="item-row">
                <td><input name="medicine[]" list="medlist" value="<?= e($it['medicine']) ?>" autocomplete="off"></td>
                <td><input name="batch[]" value="<?= e($it['batch']) ?>"></td>
                <td><input name="expiry[]" value="<?= e($it['expiry']) ?>" placeholder="MM/YY"></td>
                <td><input class="qty r" name="qty[]" type="number" step="0.01" value="<?= e($it['qty']) ?>"></td>
                <td><input class="rate r" name="rate[]" type="number" step="0.01" value="<?= e($it['rate']) ?>"></td>
                <td class="r amount"><?= number_format((float)$it['amount'],2) ?></td>
                <td class="r"><button type="button" class="btn-x" onclick="removeRow(this)">✕</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <datalist id="medlist"><?php foreach ($mnames as $mn): ?><option value="<?= e($mn) ?>"></option><?php endforeach; ?></datalist>
    <button type="button" class="btn btn-light" onclick="addRow()">+ Add Row</button>

    <div class="totals">
        <div class="totrow"><span>Sub Total</span><strong id="subTotal">0.00</strong></div>
        <div class="totrow"><span>Discount</span><input type="number" step="0.01" name="discount" id="discount" value="<?= e($bill['discount']) ?>" class="r"></div>
        <div class="totrow grand"><span>Total</span><strong id="grandTotal">0.00</strong></div>
    </div>

    <div class="fld col3"><label>Remarks</label><textarea name="remarks" rows="2"><?= e($bill['remarks']) ?></textarea></div>

    <div class="form-actions">
        <button class="btn btn-primary">Save Bill</button>
        <a class="btn btn-light" href="<?= BASE_URL ?>/bills.php?scheme=<?= $scheme ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
