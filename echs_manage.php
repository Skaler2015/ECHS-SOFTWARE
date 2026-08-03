<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'manage';
$page_title = 'ECHS Manage';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'clear_all') {
        db()->exec("DELETE FROM echs_claims");
        db()->exec("DELETE FROM echs_claim_history");
        flash('Saara claim data delete ho gaya.');
    } elseif ($act === 'clear_status') {
        $s = trim($_POST['status'] ?? '');
        if ($s !== '') {
            $st = db()->prepare("DELETE FROM echs_claims WHERE status=?");
            $st->execute([$s]);
            flash("'" . $s . "' status ke " . $st->rowCount() . " claims delete ho gaye.");
        }
    } elseif ($act === 'clear_uploads') {
        db()->exec("DELETE FROM echs_uploads");
        flash('Upload history clear ho gayi.');
    }
    redirect(BASE_URL . '/echs_manage.php?scheme=ECHS');
}

$uploads = db()->query("SELECT * FROM echs_uploads ORDER BY id DESC LIMIT 100")->fetchAll();
$total = db()->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>⚙️ ECHS Data Management</h1></div>

<div class="card">
    <h2>Upload History</h2>
    <?php if (!$uploads): ?>
        <p class="muted">Abhi tak koi upload nahi.</p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Date/Time</th><th>File</th><th>Status</th><th class="r">Rows</th><th class="r">New</th><th class="r">Updated</th></tr></thead>
        <tbody>
        <?php foreach ($uploads as $u): ?>
            <tr>
                <td class="nowrap"><?= fdate($u['uploaded_at']) ?> <?= e(date('H:i', strtotime($u['uploaded_at']))) ?></td>
                <td><?= e($u['filename']) ?></td>
                <td><span class="pill pill-info"><?= e($u['status']) ?></span></td>
                <td class="r"><?= $u['rows_read'] ?></td>
                <td class="r"><?= $u['inserted'] ?></td>
                <td class="r"><?= $u['updated'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" onsubmit="return confirm('Upload history clear karein? (claims safe rahenge)')" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="act" value="clear_uploads">
        <button class="btn btn-light">Clear upload history</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>⚠️ Data Clear (Danger Zone)</h2>
    <p class="muted small">Total abhi <strong><?= number_format($total) ?></strong> claims hain. Delete ki hui cheez wapas nahi aati — sirf tabhi karein jab pakka ho.</p>

    <div class="grid2">
        <form method="post" onsubmit="return confirm('Sirf is status ke claims delete honge. Pakka?')">
            <?= csrf_field() ?><input type="hidden" name="act" value="clear_status">
            <label>Kisi ek status ke claims hataao</label>
            <select name="status" required>
                <option value="">-- status chunein --</option>
                <?php foreach (echs_status_list() as $s): ?>
                    <option value="<?= e($s['status']) ?>"><?= e($s['status']) ?> (<?= $s['n'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <div class="form-actions"><button class="btn">Delete is status ke claims</button></div>
        </form>

        <form method="post" onsubmit="return confirm('SAARA claim data delete ho jayega! Pakka?')">
            <?= csrf_field() ?><input type="hidden" name="act" value="clear_all">
            <label>Sab kuch hataao</label>
            <p class="muted small">Saare claims + history delete honge. Phir se Excel upload karके naya data laa sakte ho.</p>
            <div class="form-actions"><button class="btn" style="background:#dc3545;color:#fff;border-color:#dc3545">Delete ALL claims</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
