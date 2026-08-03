<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$active = 'patients';

$id = (int)($_GET['id'] ?? 0);
$p  = [
    'id'=>0,'scheme'=>$scheme,'card_no'=>'','name'=>'','relation'=>'','holder_name'=>'',
    'age'=>'','gender'=>'','phone'=>'','address'=>'','service_no'=>'','rank_desig'=>'',
    'category'=>'','notes'=>''
];

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = [
        'scheme'      => $scheme,
        'card_no'     => trim($_POST['card_no'] ?? ''),
        'name'        => trim($_POST['name'] ?? ''),
        'relation'    => trim($_POST['relation'] ?? ''),
        'holder_name' => trim($_POST['holder_name'] ?? ''),
        'age'         => ($_POST['age'] ?? '') === '' ? null : (int)$_POST['age'],
        'gender'      => $_POST['gender'] ?? null,
        'phone'       => trim($_POST['phone'] ?? ''),
        'address'     => trim($_POST['address'] ?? ''),
        'service_no'  => trim($_POST['service_no'] ?? ''),
        'rank_desig'  => trim($_POST['rank_desig'] ?? ''),
        'category'    => trim($_POST['category'] ?? ''),
        'notes'       => trim($_POST['notes'] ?? ''),
    ];
    $errors = [];
    if ($data['name'] === '')    $errors[] = 'Patient ka naam zaroori hai.';
    if ($data['card_no'] === '') $errors[] = ($meta['card_label']) . ' zaroori hai.';

    if (!$errors) {
        if ($id > 0) {
            $sql = 'UPDATE patients SET card_no=?,name=?,relation=?,holder_name=?,age=?,gender=?,phone=?,address=?,service_no=?,rank_desig=?,category=?,notes=? WHERE id=? AND scheme=?';
            db()->prepare($sql)->execute([
                $data['card_no'],$data['name'],$data['relation'],$data['holder_name'],$data['age'],$data['gender'],
                $data['phone'],$data['address'],$data['service_no'],$data['rank_desig'],$data['category'],$data['notes'],
                $id,$scheme
            ]);
            flash('Patient update ho gaya.');
        } else {
            $sql = 'INSERT INTO patients (scheme,card_no,name,relation,holder_name,age,gender,phone,address,service_no,rank_desig,category,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
            db()->prepare($sql)->execute([
                $data['scheme'],$data['card_no'],$data['name'],$data['relation'],$data['holder_name'],$data['age'],$data['gender'],
                $data['phone'],$data['address'],$data['service_no'],$data['rank_desig'],$data['category'],$data['notes']
            ]);
            flash('Naya patient add ho gaya.');
        }
        redirect(BASE_URL . '/patients.php?scheme=' . $scheme);
    }
    $p = array_merge($p, $data); $p['id'] = $id;
}

// Load for edit
if ($id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = db()->prepare('SELECT * FROM patients WHERE id=? AND scheme=?');
    $q->execute([$id, $scheme]);
    $row = $q->fetch();
    if ($row) $p = $row; else { flash('Patient nahi mila.', 'error'); redirect(BASE_URL.'/patients.php?scheme='.$scheme); }
}

$page_title = ($id ? 'Edit' : 'New') . ' Patient';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1><?= $id ? 'Edit' : 'New' ?> Patient — <?= e($meta['short']) ?></h1></div>

<?php if (!empty($errors)): ?>
<div class="flash flash-error"><?php foreach($errors as $er) echo e($er).'<br>'; ?></div>
<?php endif; ?>

<form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="scheme" value="<?= $scheme ?>">
    <div class="grid2">
        <div class="fld"><label><?= e($meta['card_label']) ?> *</label><input name="card_no" value="<?= e($p['card_no']) ?>" required></div>
        <div class="fld"><label>Patient Name *</label><input name="name" value="<?= e($p['name']) ?>" required></div>
        <div class="fld"><label>Relation</label>
            <select name="relation">
                <?php foreach (['','Self','Spouse','Son','Daughter','Father','Mother','Dependent'] as $r): ?>
                    <option value="<?= e($r) ?>" <?= $p['relation']===$r?'selected':'' ?>><?= $r?:'-- select --' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fld"><label>Card Holder Name</label><input name="holder_name" value="<?= e($p['holder_name']) ?>"></div>
        <div class="fld"><label>Age</label><input type="number" name="age" value="<?= e($p['age']) ?>" min="0" max="130"></div>
        <div class="fld"><label>Gender</label>
            <select name="gender">
                <?php foreach (['','Male','Female','Other'] as $g): ?>
                    <option value="<?= e($g) ?>" <?= $p['gender']===$g?'selected':'' ?>><?= $g?:'-- select --' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fld"><label>Phone</label><input name="phone" value="<?= e($p['phone']) ?>"></div>
        <div class="fld"><label><?= $scheme==='ECHS'?'Service No.':'Employee ID' ?></label><input name="service_no" value="<?= e($p['service_no']) ?>"></div>
        <div class="fld"><label><?= $scheme==='ECHS'?'Rank':'Designation' ?></label><input name="rank_desig" value="<?= e($p['rank_desig']) ?>"></div>
        <div class="fld"><label>Category</label>
            <select name="category">
                <?php
                $cats = $scheme==='ECHS' ? ['','Pensioner','Serving','War Widow','Dependent'] : ['','Employee','Pensioner','Dependent'];
                foreach ($cats as $c): ?>
                    <option value="<?= e($c) ?>" <?= $p['category']===$c?'selected':'' ?>><?= $c?:'-- select --' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fld col2"><label>Address</label><input name="address" value="<?= e($p['address']) ?>"></div>
        <div class="fld col2"><label>Notes</label><textarea name="notes" rows="2"><?= e($p['notes']) ?></textarea></div>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary">Save Patient</button>
        <a class="btn btn-light" href="<?= BASE_URL ?>/patients.php?scheme=<?= $scheme ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
