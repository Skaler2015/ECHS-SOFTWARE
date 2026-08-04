<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'upload';
$page_title = 'ECHS Upload';

$summary = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    @set_time_limit(300);
    $files = [];
    if (!empty($_FILES['claimfiles']) && is_array($_FILES['claimfiles']['name'])) {
        foreach ($_FILES['claimfiles']['name'] as $i => $nm) {
            if (($_FILES['claimfiles']['error'][$i] ?? 1) === UPLOAD_ERR_OK && $nm !== '') {
                $files[] = ['name'=>$nm, 'tmp'=>$_FILES['claimfiles']['tmp_name'][$i]];
            }
        }
    }
    if (!$files) {
        flash('Koi file select nahi hui.', 'error');
    } else {
        try {
            $summary = echs_import_uploads($files);
            $msg = "Import complete: {$summary['read']} rows padhe, {$summary['inserted']} naye, {$summary['updated']} update, {$summary['changed']} status badle.";
            flash($msg, $summary['errors'] ? 'error' : 'success');
        } catch (Exception $e) {
            flash('Import me error: ' . $e->getMessage(), 'error');
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>📥 Upload ECHS Claim Excel</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">View Claims →</a>
    </div>
</div>

<div class="card">
    <p>ECHS / UTIITSL portal se download ki hui <strong>CLAIMLIST</strong> Excel files (<code>.xls</code>) yahan upload karein.
       Aap ek saath <strong>kai files</strong> ya poori <strong>.zip</strong> (jaise portal deta hai) upload kar sakte hain.</p>
    <p class="muted small">Har claim uske <strong>Claim ID</strong> se track hota hai. Dobara upload karne par status apne aap update ho jata hai (jaise "Review by Validator" → "Claim Settled").</p>

    <form method="post" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <div class="upload-drop" id="dropZone">
            <div style="font-size:2rem">📎</div>
            <label for="claimfiles"><strong>Files yahan khींchकर chhodें</strong> — ya click karके chunें (.xls / .zip)</label>
            <input type="file" name="claimfiles[]" id="claimfiles" multiple accept=".xls,.zip" style="margin-top:10px">
            <div id="fileList" class="filelist"></div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">⬆ Upload &amp; Update</button>
        </div>
    </form>
    <script>
    (function(){
        var dz = document.getElementById('dropZone'),
            inp = document.getElementById('claimfiles'),
            list = document.getElementById('fileList');
        function show(){
            if(!inp.files.length){ list.innerHTML=''; return; }
            var names=[]; for(var i=0;i<inp.files.length;i++) names.push('📄 '+inp.files[i].name);
            list.innerHTML = names.join('<br>');
        }
        inp.addEventListener('change', show);
        ['dragenter','dragover'].forEach(function(ev){ dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.add('drag');}); });
        ['dragleave','drop'].forEach(function(ev){ dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.remove('drag');}); });
        dz.addEventListener('drop', function(e){
            if(e.dataTransfer && e.dataTransfer.files.length){ inp.files = e.dataTransfer.files; show(); }
        });
    })();
    </script>
</div>

<?php if ($summary): ?>
<div class="card">
    <h2>Import Summary</h2>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= count($summary['files']) ?></div><div class="stat-lbl">Files processed</div></div>
        <div class="stat-card"><div class="stat-num"><?= $summary['read'] ?></div><div class="stat-lbl">Rows read</div></div>
        <div class="stat-card ok"><div class="stat-num"><?= $summary['inserted'] ?></div><div class="stat-lbl">New claims</div></div>
        <div class="stat-card info"><div class="stat-num"><?= $summary['updated'] ?></div><div class="stat-lbl">Updated</div></div>
        <div class="stat-card warn"><div class="stat-num"><?= $summary['changed'] ?></div><div class="stat-lbl">Status changed</div></div>
    </div>

    <?php if ($summary['files']): ?>
    <table class="tbl">
        <thead><tr><th>File</th><th>Detected Status</th><th class="r">Rows</th><th class="r">New</th><th class="r">Updated</th></tr></thead>
        <tbody>
        <?php foreach ($summary['files'] as $ff): ?>
            <tr>
                <td><?= e($ff['name']) ?></td>
                <td><span class="pill pill-info"><?= e($ff['status']) ?></span></td>
                <td class="r"><?= $ff['read'] ?></td>
                <td class="r"><?= $ff['inserted'] ?></td>
                <td class="r"><?= $ff['updated'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($summary['errors']): ?>
        <div class="flash flash-error" style="margin-top:14px">
            <strong>Kuch dikkatein:</strong><br>
            <?php foreach ($summary['errors'] as $er) echo '• ' . e($er) . '<br>'; ?>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">Ab Claims dekhein →</a>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3>ℹ️ Kaunsi files upload kar sakte hain?</h3>
    <p class="muted small">Portal se milne wali koi bhi CLAIMLIST file — jaise:
    Review By Validator, Claim Settled, Patient Referral, Need More Information,
    Rejected Claims, Cancel Claim, Admission Intimation, aur stage codes (1P, 2S, 3P, 5S, 6S, 7S, 13P) — sab support hain.</p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
