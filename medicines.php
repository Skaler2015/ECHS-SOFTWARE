<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$page_title = 'Medicines';
$active = 'medicines';

// add / edit / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $mid  = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $hsn  = trim($_POST['hsn'] ?? '');
        $mrp  = (float)($_POST['mrp'] ?? 0);
        if ($name !== '') {
            if ($mid > 0) {
                db()->prepare('UPDATE medicines SET name=?,unit=?,hsn=?,mrp=? WHERE id=?')->execute([$name,$unit,$hsn,$mrp,$mid]);
                flash('Medicine update ho gayi.');
            } else {
                db()->prepare('INSERT INTO medicines (name,unit,hsn,mrp) VALUES (?,?,?,?)')->execute([$name,$unit,$hsn,$mrp]);
                flash('Medicine add ho gayi.');
            }
        }
    } elseif ($act === 'del') {
        db()->prepare('DELETE FROM medicines WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Medicine delete ho gayi.');
    }
    redirect(BASE_URL . '/medicines.php?scheme=' . $scheme);
}

$search = trim($_GET['q'] ?? '');
$rows = [];
try {
    if ($search !== '') {
        $q = db()->prepare('SELECT * FROM medicines WHERE name LIKE ? ORDER BY name LIMIT 500');
        $q->execute(['%'.$search.'%']);
    } else {
        $q = db()->query('SELECT * FROM medicines ORDER BY name LIMIT 500');
    }
    $rows = $q->fetchAll();
} catch (Exception $e) {}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>Medicine Master</h1></div>
<p class="muted small">Yeh list saari schemes me common hai. Bill banate waqt yahan se naam auto-suggest honge.</p>

<form method="post" class="card form inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="">
    <div class="grid4">
        <div class="fld"><label>Medicine Name *</label><input name="name" required></div>
        <div class="fld"><label>Unit</label><input name="unit" placeholder="Tab / Cap / Syrup"></div>
        <div class="fld"><label>HSN</label><input name="hsn"></div>
        <div class="fld"><label>MRP</label><input type="number" step="0.01" name="mrp" value="0"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">+ Add Medicine</button></div>
</form>

<form class="searchbar" method="get">
    <input type="hidden" name="scheme" value="<?= $scheme ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Medicine search...">
    <button class="btn">Search</button>
    <?php if ($search): ?><a class="btn btn-light" href="<?= BASE_URL ?>/medicines.php?scheme=<?= $scheme ?>">Clear</a><?php endif; ?>
</form>

<div class="card">
<?php if (!$rows): ?>
    <p class="muted">Koi medicine nahi. Upar se add karein.</p>
<?php else: ?>
    <table class="tbl">
        <thead><tr><th>Name</th><th>Unit</th><th>HSN</th><th class="r">MRP</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
            <tr>
                <td><?= e($m['name']) ?></td>
                <td><?= e($m['unit'] ?: '-') ?></td>
                <td><?= e($m['hsn'] ?: '-') ?></td>
                <td class="r"><?= number_format($m['mrp'],2) ?></td>
                <td class="r">
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="act" value="del">
                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <button class="btn-x">✕</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
