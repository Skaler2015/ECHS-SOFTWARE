<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'claims';
$pdo = db();

$id = trim($_GET['id'] ?? '');
$st = $pdo->prepare("SELECT * FROM echs_claims WHERE claim_id = ?");
$st->execute([$id]);
$c = $st->fetch();

if (!$c) {
    $page_title = 'Claim not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><p class="muted">Claim ('.e($id).') nahi mila.</p><p><a class="btn" href="'.BASE_URL.'/echs_claims.php?scheme=ECHS">← Claims</a></p></div>';
    require __DIR__ . '/includes/footer.php';
    return;
}
$page_title = 'Claim ' . $c['claim_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $doc = trim($_POST['doctor_name'] ?? '');
        $note = trim($_POST['notes'] ?? '');
        $assignee = trim($_POST['assigned_to'] ?? '');
        $fu = !empty($_POST['followup']) ? 1 : 0;
        $manual = ($doc !== '' && $doc !== ($c['doctor_name'] ?? '')) ? 1 : (int)$c['doctor_manual'];
        $pdo->prepare("UPDATE echs_claims SET doctor_name=?, doctor_manual=?, notes=?, followup=?, assigned_to=?, updated_at=NOW() WHERE claim_id=?")
            ->execute([$doc ?: null, $doc!==''?1:$manual, $note ?: null, $fu, $assignee ?: null, $id]);
        if ($doc !== '') { try { $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)")->execute([$doc]); } catch (Exception $e) {} }
        flash('Save ho gaya.');
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id));
    } elseif ($act === 'addnote') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            $who = current_user()['full_name'] ?? 'staff';
            $pdo->prepare("INSERT INTO echs_notes (claim_id,note,who) VALUES (?,?,?)")->execute([$id, $note, $who]);
            flash('Note add ho gaya.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id));
    } elseif ($act === 'upload_doc') {
        $who = current_user()['full_name'] ?? 'staff';
        if (!empty($_FILES['doc']['name']) && $_FILES['doc']['error'] === UPLOAD_ERR_OK) {
            $orig = (string)$_FILES['doc']['name'];
            $size = (int)$_FILES['doc']['size'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png','webp','gif','doc','docx','xls','xlsx','csv','txt'];
            if ($size > 15 * 1024 * 1024) {
                flash('File 15MB se badi hai.', 'error');
            } elseif (!in_array($ext, $allowed, true)) {
                flash('Is type ki file allowed nahi ('.e($ext).').', 'error');
            } else {
                $dir = __DIR__ . '/uploads/echs';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                if (!is_file($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess', "Deny from all\n");
                if (!is_file($dir.'/index.php')) @file_put_contents($dir.'/index.php', "<?php // no listing\n");
                $stored = bin2hex(random_bytes(16)) . ($ext ? '.'.$ext : '');
                if (move_uploaded_file($_FILES['doc']['tmp_name'], $dir.'/'.$stored)) {
                    $mime = function_exists('mime_content_type') ? (mime_content_type($dir.'/'.$stored) ?: null) : null;
                    $pdo->prepare("INSERT INTO echs_docs (claim_id,stored_name,orig_name,mime,bytes,who) VALUES (?,?,?,?,?,?)")
                        ->execute([$id, $stored, $orig, $mime, $size, $who]);
                    echs_log('doc_upload', $id.' · '.$orig);
                    flash('Document attach ho gaya.');
                } else { flash('Upload fail hua.', 'error'); }
            }
        } else { flash('Koi file select nahi ki.', 'error'); }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id));
    } elseif ($act === 'del_doc') {
        $did = (int)($_POST['doc_id'] ?? 0);
        $d = $pdo->prepare("SELECT stored_name FROM echs_docs WHERE id=? AND claim_id=?");
        $d->execute([$did, $id]); $drow = $d->fetch();
        if ($drow) {
            @unlink(__DIR__ . '/uploads/echs/' . basename($drow['stored_name']));
            $pdo->prepare("DELETE FROM echs_docs WHERE id=?")->execute([$did]);
            flash('Document delete ho gaya.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id));
    } elseif ($act === 'add_query') {
        $q = trim($_POST['query_text'] ?? '');
        $ro = trim($_POST['raised_on'] ?? '');
        if ($q !== '') {
            $who = current_user()['full_name'] ?? 'staff';
            $pdo->prepare("INSERT INTO echs_queries (claim_id,query_text,raised_on,status,who) VALUES (?,?,?, 'open', ?)")
                ->execute([$id, $q, $ro ?: null, $who]);
            flash('Query add ho gayi.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id));
    } elseif ($act === 'reply_query') {
        $qid = (int)($_POST['query_id'] ?? 0);
        $reply = trim($_POST['reply_text'] ?? '');
        $close = !empty($_POST['close_q']);
        $pdo->prepare("UPDATE echs_queries SET reply_text=?, replied_on=CURDATE(), status=? WHERE id=? AND claim_id=?")
            ->execute([$reply ?: null, $close ? 'closed' : 'replied', $qid, $id]);
        flash('Query update ho gayi.');
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id));
    }
}

$hist = $pdo->prepare("SELECT * FROM echs_claim_history WHERE claim_id=? ORDER BY changed_at DESC");
$hist->execute([$id]); $history = $hist->fetchAll();

$notesList = $pdo->prepare("SELECT * FROM echs_notes WHERE claim_id=? ORDER BY id DESC");
$notesList->execute([$id]); $notesList = $notesList->fetchAll();
$staff = echs_staff_list();

$docsList = $pdo->prepare("SELECT * FROM echs_docs WHERE claim_id=? ORDER BY id DESC");
$docsList->execute([$id]); $docsList = $docsList->fetchAll();

$queriesList = $pdo->prepare("SELECT * FROM echs_queries WHERE claim_id=? ORDER BY id DESC");
$queriesList->execute([$id]); $queriesList = $queriesList->fetchAll();

// other claims of same card
$more = [];
if (!empty($c['card_id'])) {
    $m = $pdo->prepare("SELECT claim_id, status, category, claim_amt, accept_date FROM echs_claims WHERE card_id=? AND claim_id<>? ORDER BY accept_date DESC LIMIT 30");
    $m->execute([$c['card_id'], $id]); $more = $m->fetchAll();
}

$cat = $c['category'] ?: echs_category($c['status']);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Claim <?= e($c['claim_id']) ?></h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_tasks.php?scheme=ECHS&id=<?= urlencode($id) ?>">✔ Follow-up</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">← All Claims</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= inr($c['claim_amt'],0) ?></div><div class="stat-lbl">Net claim</div></div>
    <div class="stat-card <?= $cat==='settled'?'ok':'' ?>"><div class="stat-num"><?= inr($c['approved_amt'],0) ?></div><div class="stat-lbl"><?= $cat==='settled'?'Settled amount':'Approved' ?></div></div>
    <div class="stat-card <?= $cat==='rejected'||$cat==='cancelled'?'danger':($cat==='settled'?'ok':'warn') ?>">
        <div class="stat-num" style="font-size:1rem"><?= e($c['status']) ?></div><div class="stat-lbl">Status</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Patient & claim</h2>
        <table class="kv">
            <tr><td>ESM (card holder)</td><th><?= e($c['esm_name']) ?></th></tr>
            <tr><td>Patient</td><th><?= e($c['patient_name']) ?></th></tr>
            <tr><td>Card ID</td><th><?= e($c['card_id']) ?></th></tr>
            <tr><td>Type</td><th><?= e(echs_ptype($c['patient_type'])) ?> · <?= e(echs_atype($c['admit_type'])) ?></th></tr>
            <tr><td>Region</td><th><?= e($c['region']) ?></th></tr>
            <tr><td>Hospital</td><th><?= e($c['hospital_name']) ?></th></tr>
        </table>
    </div>
    <div class="card">
        <h2>Dates & amounts</h2>
        <table class="kv">
            <tr><td>Accept date</td><th><?= fdate($c['accept_date']) ?></th></tr>
            <tr><td>Processed on</td><th><?= fdate($c['processed_on']) ?></th></tr>
            <tr><td>Net claim amount</td><th><?= money($c['claim_amt']) ?></th></tr>
            <tr><td>Approved / settled</td><th class="<?= $cat==='settled'?'ok':'' ?>"><?= money($c['approved_amt']) ?></th></tr>
            <tr><td>Category</td><th><?= e(ucfirst($cat)) ?></th></tr>
        </table>
    </div>
</div>

<div class="detail-grid">
    <div class="card form">
        <h2>Doctor · Assign · Flag</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="act" value="save">
            <div class="fld"><label>Treating doctor</label><input name="doctor_name" list="rdocs" value="<?= e($c['doctor_name']) ?>">
                <datalist id="rdocs"><?php foreach (echs_doctor_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist></div>
            <div class="fld"><label>Assigned to (staff)</label>
                <select name="assigned_to"><option value="">— koi nahi —</option>
                <?php foreach ($staff as $sf): ?><option value="<?= e($sf) ?>" <?= ($c['assigned_to']??'')===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?></select></div>
            <div class="fld"><label>Quick note (single)</label><textarea name="notes" rows="2"><?= e($c['notes']) ?></textarea></div>
            <label class="inline" style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" name="followup" value="1" <?= $c['followup']?'checked':'' ?>> Follow-up flag</label>
            <div class="form-actions"><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
    <div class="card">
        <h2>📝 Notes timeline</h2>
        <form method="post" style="display:flex;gap:8px;margin-bottom:12px">
            <?= csrf_field() ?><input type="hidden" name="act" value="addnote">
            <input name="note" placeholder="Naya note likhein…" required style="flex:1;padding:9px 11px;border:1px solid var(--line);border-radius:9px">
            <button class="btn btn-primary">Add</button>
        </form>
        <?php if (!$notesList): ?><p class="muted small">Abhi koi note nahi.</p><?php else: ?>
        <ul class="timeline">
            <?php foreach ($notesList as $nt): ?>
                <li><span class="tl-date"><?= e(date('d-m-y', strtotime($nt['created_at']))) ?></span>
                    <span class="small"><?= e($nt['note']) ?> <span class="muted">— <?= e($nt['who']) ?></span></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>📎 Documents <span class="muted small">(<?= count($docsList) ?>)</span></h2>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
            <?= csrf_field() ?><input type="hidden" name="act" value="upload_doc">
            <input type="file" name="doc" required style="flex:1;min-width:180px">
            <button class="btn btn-primary">Upload</button>
        </form>
        <p class="muted small" style="margin-top:-6px">PDF, image, Word, Excel · max 15MB</p>
        <?php if (!$docsList): ?><p class="muted small">Abhi koi document attach nahi.</p><?php else: ?>
        <table class="tbl">
            <thead><tr><th>File</th><th class="r">Size</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($docsList as $d): ?>
                <tr>
                    <td><a class="link" target="_blank" href="<?= BASE_URL ?>/api/echs_doc.php?id=<?= (int)$d['id'] ?>"><?= e($d['orig_name']) ?></a></td>
                    <td class="r small"><?= number_format($d['bytes']/1024, 0) ?> KB</td>
                    <td class="small"><?= e(date('d-m-y', strtotime($d['uploaded_at']))) ?><br><span class="muted"><?= e($d['who']) ?></span></td>
                    <td class="r">
                        <a class="btn btn-sm" href="<?= BASE_URL ?>/api/echs_doc.php?id=<?= (int)$d['id'] ?>&dl=1">⬇</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete document?')">
                            <?= csrf_field() ?><input type="hidden" name="act" value="del_doc"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                            <button class="btn btn-sm btn-danger">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>❓ Query / Reply tracker</h2>
        <form method="post" style="margin-bottom:12px">
            <?= csrf_field() ?><input type="hidden" name="act" value="add_query">
            <div class="fld"><label>ECHS ne kya query/objection uthaayi?</label><textarea name="query_text" rows="2" required placeholder="Query likhein…"></textarea></div>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="date" name="raised_on" style="padding:9px 11px;border:1px solid var(--line);border-radius:9px">
                <button class="btn btn-primary">Add query</button>
            </div>
        </form>
        <?php if (!$queriesList): ?><p class="muted small">Koi query nahi.</p><?php else: foreach ($queriesList as $q):
            $qcls = $q['status']==='closed'?'settled':($q['status']==='replied'?'process':'warn'); ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;gap:8px">
                <strong class="small">Query</strong>
                <span class="pill pill-<?= $qcls ?>"><?= e($q['status']) ?></span>
            </div>
            <p class="small" style="margin:6px 0"><?= nl2br(e($q['query_text'])) ?>
                <?php if ($q['raised_on']): ?><span class="muted">· raised <?= fdate($q['raised_on']) ?></span><?php endif; ?></p>
            <?php if (!empty($q['reply_text'])): ?>
                <p class="small" style="margin:6px 0;padding-left:10px;border-left:3px solid var(--gold)"><strong>Reply:</strong> <?= nl2br(e($q['reply_text'])) ?>
                    <?php if ($q['replied_on']): ?><span class="muted">· <?= fdate($q['replied_on']) ?></span><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($q['status'] !== 'closed'): ?>
            <form method="post" style="margin-top:6px">
                <?= csrf_field() ?><input type="hidden" name="act" value="reply_query"><input type="hidden" name="query_id" value="<?= (int)$q['id'] ?>">
                <textarea name="reply_text" rows="2" placeholder="Reply likhein…" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:9px"><?= e($q['reply_text']) ?></textarea>
                <label class="inline" style="display:flex;gap:6px;align-items:center;margin:6px 0"><input type="checkbox" name="close_q" value="1"> Query close karein</label>
                <button class="btn btn-sm btn-primary">Save reply</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="card">
    <h2>Status history</h2>
    <?php if (!$history): ?><p class="muted">Koi change record nahi.</p><?php else: ?>
    <ul class="timeline">
        <?php foreach ($history as $h): ?>
            <li><span class="tl-date"><?= e(date('d-m-y', strtotime($h['changed_at']))) ?></span>
                <span class="small"><?= e($h['from_status'] ?: 'नया') ?> → <strong><?= e($h['to_status']) ?></strong></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if ($more): ?>
<div class="card">
    <h2>Isi card ke aur claims (<?= count($more) ?>)</h2>
    <table class="tbl">
        <thead><tr><th>Claim ID</th><th>Status</th><th>Accept</th><th class="r">Claimed</th></tr></thead>
        <tbody>
        <?php foreach ($more as $m): ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($m['claim_id']) ?>"><?= e($m['claim_id']) ?></a></td>
                <td class="small"><?= e($m['status']) ?></td><td class="small"><?= fdate($m['accept_date']) ?></td>
                <td class="r"><?= inr($m['claim_amt'],0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
