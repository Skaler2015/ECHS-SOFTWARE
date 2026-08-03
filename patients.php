<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$scheme = current_scheme();
$meta   = scheme_meta($scheme);
$page_title = $meta['short'] . ' Patients';
$active = 'patients';

$search = trim($_GET['q'] ?? '');
$rows = [];
try {
    if ($search !== '') {
        $like = '%' . $search . '%';
        $q = db()->prepare('SELECT * FROM patients WHERE scheme=? AND (name LIKE ? OR card_no LIKE ? OR phone LIKE ? OR service_no LIKE ?) ORDER BY name LIMIT 300');
        $q->execute([$scheme, $like, $like, $like, $like]);
    } else {
        $q = db()->prepare('SELECT * FROM patients WHERE scheme=? ORDER BY id DESC LIMIT 300');
        $q->execute([$scheme]);
    }
    $rows = $q->fetchAll();
} catch (Exception $e) {}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Patients — <?= e($meta['short']) ?></h1>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/patient_form.php?scheme=<?= $scheme ?>">+ New Patient</a>
    </div>
</div>

<form class="searchbar" method="get">
    <input type="hidden" name="scheme" value="<?= $scheme ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Naam, card no., phone se search karein...">
    <button class="btn">Search</button>
    <?php if ($search): ?><a class="btn btn-light" href="<?= BASE_URL ?>/patients.php?scheme=<?= $scheme ?>">Clear</a><?php endif; ?>
</form>

<div class="card">
<?php if (!$rows): ?>
    <p class="muted">Koi patient nahi mila.</p>
<?php else: ?>
    <table class="tbl">
        <thead><tr>
            <th><?= e($meta['card_label']) ?></th><th>Name</th><th>Relation</th>
            <th>Age/Sex</th><th>Phone</th><th>Category</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><?= e($p['card_no']) ?></td>
                <td><strong><?= e($p['name']) ?></strong><?php if($p['holder_name']): ?><br><small class="muted">of <?= e($p['holder_name']) ?></small><?php endif; ?></td>
                <td><?= e($p['relation'] ?: '-') ?></td>
                <td><?= e(($p['age']!==null?$p['age']:'-')) ?> / <?= e($p['gender'] ?: '-') ?></td>
                <td><?= e($p['phone'] ?: '-') ?></td>
                <td><?= e($p['category'] ?: '-') ?></td>
                <td class="r nowrap">
                    <a class="link" href="<?= BASE_URL ?>/bill_form.php?scheme=<?= $scheme ?>&patient_id=<?= $p['id'] ?>">New Bill</a>
                    <a class="link" href="<?= BASE_URL ?>/patient_form.php?scheme=<?= $scheme ?>&id=<?= $p['id'] ?>">Edit</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted small"><?= count($rows) ?> record(s)</p>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
