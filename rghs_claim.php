<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'claims';
$pdo = db();

$tid = trim($_GET['tid'] ?? '');
$st = $pdo->prepare("SELECT * FROM rghs_claims WHERE tid = ?");
$st->execute([$tid]);
$c = $st->fetch();

if (!$c) {
    $page_title = 'Claim not found';
    if (is_panel()) panel_head($page_title); else require __DIR__ . '/includes/header.php';
    echo '<div class="card"><p class="muted">Claim (TID '.e($tid).') nahi mila.</p><p><a class="btn" href="'.BASE_URL.'/rghs_claims.php?scheme=RGHS">← Claims</a></p></div>';
    if (is_panel()) panel_foot(); else require __DIR__ . '/includes/footer.php';
    return;
}
$page_title = 'Claim ' . $c['tid'];

// save note / doctor override
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $doc = trim($_POST['doctor_name'] ?? '');
        $note = trim($_POST['notes'] ?? '');
        $assignee = trim($_POST['assigned_to'] ?? '');
        $fu = !empty($_POST['followup']) ? 1 : 0;
        $manual = ($doc !== '' && $doc !== ($c['doctor_name'] ?? '')) ? 1 : (int)$c['doctor_manual'];
        $pdo->prepare("UPDATE rghs_claims SET doctor_name=?, doctor_manual=?, notes=?, followup=?, assigned_to=?, updated_at=NOW() WHERE tid=?")
            ->execute([$doc ?: null, $doc!==''?1:$manual, $note ?: null, $fu, $assignee ?: null, $tid]);
        flash('Save ho gaya.');
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid).(is_panel()?'&panel=1':''));
    } elseif ($act === 'addnote') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            $who = current_user()['full_name'] ?? 'staff';
            $pdo->prepare("INSERT INTO rghs_notes (tid,note,who) VALUES (?,?,?)")->execute([$tid, $note, $who]);
            flash('Note add ho gaya.');
        }
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid).(is_panel()?'&panel=1':''));
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
                $dir = __DIR__ . '/uploads/rghs';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                // access guards (deploy excludes this folder, so create them on the server at runtime)
                if (!is_file($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess', "Deny from all\n");
                if (!is_file($dir.'/index.php')) @file_put_contents($dir.'/index.php', "<?php // no listing\n");
                $stored = bin2hex(random_bytes(16)) . ($ext ? '.'.$ext : '');
                if (move_uploaded_file($_FILES['doc']['tmp_name'], $dir.'/'.$stored)) {
                    $mime = function_exists('mime_content_type') ? (mime_content_type($dir.'/'.$stored) ?: null) : null;
                    $pdo->prepare("INSERT INTO rghs_docs (tid,stored_name,orig_name,mime,bytes,who) VALUES (?,?,?,?,?,?)")
                        ->execute([$tid, $stored, $orig, $mime, $size, $who]);
                    rghs_log('doc_upload', $tid.' · '.$orig);
                    flash('Document attach ho gaya.');
                } else {
                    flash('Upload fail hua.', 'error');
                }
            }
        } else {
            flash('Koi file select nahi ki.', 'error');
        }
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid).(is_panel()?'&panel=1':''));
    } elseif ($act === 'del_doc') {
        $did = (int)($_POST['doc_id'] ?? 0);
        $d = $pdo->prepare("SELECT stored_name FROM rghs_docs WHERE id=? AND tid=?");
        $d->execute([$did, $tid]); $drow = $d->fetch();
        if ($drow) {
            @unlink(__DIR__ . '/uploads/rghs/' . basename($drow['stored_name']));
            $pdo->prepare("DELETE FROM rghs_docs WHERE id=?")->execute([$did]);
            flash('Document delete ho gaya.');
        }
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid).(is_panel()?'&panel=1':''));
    } elseif ($act === 'add_query') {
        $q = trim($_POST['query_text'] ?? '');
        $ro = trim($_POST['raised_on'] ?? '');
        if ($q !== '') {
            $who = current_user()['full_name'] ?? 'staff';
            $pdo->prepare("INSERT INTO rghs_queries (tid,query_text,raised_on,status,who) VALUES (?,?,?, 'open', ?)")
                ->execute([$tid, $q, $ro ?: null, $who]);
            flash('Query add ho gayi.');
        }
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid).(is_panel()?'&panel=1':''));
    } elseif ($act === 'reply_query') {
        $qid = (int)($_POST['query_id'] ?? 0);
        $reply = trim($_POST['reply_text'] ?? '');
        $close = !empty($_POST['close_q']);
        $pdo->prepare("UPDATE rghs_queries SET reply_text=?, replied_on=CURDATE(), status=? WHERE id=? AND tid=?")
            ->execute([$reply ?: null, $close ? 'closed' : 'replied', $qid, $tid]);
        flash('Query update ho gayi.');
        redirect(BASE_URL.'/rghs_claim.php?scheme=RGHS&tid='.urlencode($tid).(is_panel()?'&panel=1':''));
    }
}

$hist = $pdo->prepare("SELECT * FROM rghs_claim_history WHERE tid=? ORDER BY changed_at DESC");
$hist->execute([$tid]); $history = $hist->fetchAll();

$notesList = $pdo->prepare("SELECT * FROM rghs_notes WHERE tid=? ORDER BY id DESC");
$notesList->execute([$tid]); $notesList = $notesList->fetchAll();
$staff = rghs_staff_list();

$docsList = $pdo->prepare("SELECT * FROM rghs_docs WHERE tid=? ORDER BY id DESC");
$docsList->execute([$tid]); $docsList = $docsList->fetchAll();

$queriesList = $pdo->prepare("SELECT * FROM rghs_queries WHERE tid=? ORDER BY id DESC");
$queriesList->execute([$tid]); $queriesList = $queriesList->fetchAll();

// payment record (from Payment Tracker upload), if any
$pst = $pdo->prepare("SELECT * FROM rghs_payments WHERE tid=?");
$pst->execute([$tid]); $pay = $pst->fetch() ?: null;

// other claims of same enrollment/card
$more = [];
if (!empty($c['enrollment_id'])) {
    $m = $pdo->prepare("SELECT tid, status, claim_amt, submit_date FROM rghs_claims WHERE enrollment_id=? AND tid<>? ORDER BY submit_date DESC LIMIT 20");
    $m->execute([$c['enrollment_id'], $tid]); $more = $m->fetchAll();
}

if (is_panel()) panel_head($page_title); else require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>Claim <?= e($c['tid']) ?></h1>
    <div class="page-actions">
        <a class="btn" target="_blank" href="<?= BASE_URL ?>/rghs_claim_print.php?scheme=RGHS&tid=<?= urlencode($tid) ?>">🖨️ Print</a>
        <a class="btn" href="<?= BASE_URL ?>/rghs_tasks.php?scheme=RGHS&tid=<?= urlencode($tid) ?>">✔ Follow-up</a>
        <a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">← All Claims</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= inr($c['claim_amt'],0) ?></div><div class="stat-lbl">Claimed</div></div>
    <div class="stat-card"><div class="stat-num"><?= inr($c['tpa_amt'],0) ?></div><div class="stat-lbl">TPA approved</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= inr($c['cu_amt'],0) ?></div><div class="stat-lbl">CU approved</div></div>
    <div class="stat-card <?= rghs_category($c['status'])==='rejected'?'danger':(rghs_category($c['status'])==='approved'?'ok':'warn') ?>">
        <div class="stat-num" style="font-size:1rem"><?= e($c['status']) ?></div><div class="stat-lbl">Status</div></div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Patient & claim</h2>
        <table class="kv">
            <tr><td>Patient</td><th><?= e($c['patient_name']) ?> <span class="muted">(<?= e($c['gender']) ?>)</span></th></tr>
            <tr><td>RGHS Card</td><th><?= e($c['card_no']) ?></th></tr>
            <tr><td>Enrollment ID</td><th><?= e($c['enrollment_id']) ?></th></tr>
            <tr><td>Mobile</td><th><?= e($c['mobile']) ?></th></tr>
            <tr><td>Type</td><th><?= e($c['claim_type']) ?> · <?= e($c['department']) ?></th></tr>
            <tr><td>Category</td><th><?= e($c['category']) ?></th></tr>
            <tr><td>Hospital</td><th><?= e($c['hospital_name']) ?> <?= $c['nabh']==='Yes'?'<span class="pill pill-info">NABH</span>':'' ?></th></tr>
            <tr><td>Grade</td><th><?= e($c['grade']) ?></th></tr>
            <tr><td>Invoice</td><th><?= e($c['invoice_no']) ?></th></tr>
        </table>
    </div>
    <div class="card">
        <h2>Dates & amounts</h2>
        <table class="kv">
            <tr><td>Admission</td><th><?= fdate($c['admit_date']) ?></th></tr>
            <tr><td>Discharge</td><th><?= fdate($c['discharge_date']) ?> <span class="muted">(<?= e($c['los']) ?> din)</span></th></tr>
            <tr><td>Claim submitted</td><th><?= fdate($c['submit_date']) ?></th></tr>
            <tr><td>TPA final action</td><th><?= fdate($c['tpa_action_date']) ?></th></tr>
            <tr><td>CU final action</td><th><?= fdate($c['cu_action_date']) ?></th></tr>
            <tr><td>Query status</td><th><?= e($c['query_status']) ?></th></tr>
        </table>
    </div>
</div>

<?php if ($pay): $psuccess = stripos((string)$pay['final_status'],'success')!==false; ?>
<div class="card">
    <h2>💰 Payment <span class="pill pill-<?= $psuccess?'settled':'process' ?>" style="margin-left:8px"><?= e($pay['final_status'] ?: '-') ?></span></h2>
    <div class="detail-grid">
        <table class="kv">
            <tr><td>Paid amount</td><th class="ok"><?= money($pay['paid_amount']) ?></th></tr>
            <tr><td>TDS deducted</td><th><?= money($pay['tds_deducted']) ?></th></tr>
            <tr><td>Payment credit date</td><th><?= fdate($pay['credit_date']) ?></th></tr>
            <tr><td>Payment initiated</td><th><?= fdate($pay['init_date']) ?></th></tr>
        </table>
        <table class="kv">
            <tr><td>UTR number</td><th><?= e($pay['utr']) ?></th></tr>
            <tr><td>Treasury voucher</td><th><?= e($pay['treasury_voucher']) ?></th></tr>
            <tr><td>Bank</td><th><?= e($pay['bank_name']) ?> <span class="muted"><?= e($pay['ifsc']) ?></span></th></tr>
            <tr><td>Account no.</td><th><?= e($pay['account_no']) ?></th></tr>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($c['tpa_remarks']) || !empty($c['cu_remarks']) || !empty($c['last_query_remark'])): ?>
<div class="card">
    <h2>Remarks</h2>
    <?php if (!empty($c['cu_remarks'])): ?><p><strong>CU:</strong> <?= nl2br(e($c['cu_remarks'])) ?></p><?php endif; ?>
    <?php if (!empty($c['tpa_remarks'])): ?><p><strong>TPA:</strong> <?= nl2br(e($c['tpa_remarks'])) ?></p><?php endif; ?>
    <?php if (!empty($c['last_query_remark']) && $c['last_query_remark']!=='NA'): ?><p><strong>Last query:</strong> <?= nl2br(e($c['last_query_remark'])) ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($c['package_name'])): ?>
<div class="card">
    <h2>Package</h2>
    <p class="small"><?= e($c['package_name']) ?></p>
    <p class="muted small">Codes: <?= e($c['package_code']) ?></p>
</div>
<?php endif; ?>

<div class="detail-grid">
    <div class="card form">
        <h2>Doctor · Assign · Flag</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="act" value="save">
            <div class="fld"><label>Treating doctor</label><input name="doctor_name" list="rdocs" value="<?= e($c['doctor_name']) ?>">
                <datalist id="rdocs"><?php foreach (rghs_doctor_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist></div>
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
                    <td><a class="link" target="_blank" href="<?= BASE_URL ?>/api/rghs_doc.php?id=<?= (int)$d['id'] ?>"><?= e($d['orig_name']) ?></a></td>
                    <td class="r small"><?= number_format($d['bytes']/1024, 0) ?> KB</td>
                    <td class="small"><?= e(date('d-m-y', strtotime($d['uploaded_at']))) ?><br><span class="muted"><?= e($d['who']) ?></span></td>
                    <td class="r">
                        <a class="btn btn-sm" href="<?= BASE_URL ?>/api/rghs_doc.php?id=<?= (int)$d['id'] ?>&dl=1">⬇</a>
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
            <div class="fld"><label>RGHS ne kya query uthaayi?</label><textarea name="query_text" rows="2" required placeholder="Query likhein…"></textarea></div>
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

<?php if ($more): ?>
<div class="card">
    <h2>Isi beneficiary ke aur claims (<?= count($more) ?>)</h2>
    <table class="tbl">
        <thead><tr><th>TID</th><th>Status</th><th>Submitted</th><th class="r">Claimed</th></tr></thead>
        <tbody>
        <?php foreach ($more as $m): ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($m['tid']) ?>"><?= e($m['tid']) ?></a></td>
                <td class="small"><?= e($m['status']) ?></td><td class="small"><?= fdate($m['submit_date']) ?></td>
                <td class="r"><?= inr($m['claim_amt'],0) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (is_panel()) panel_foot(); else require __DIR__ . '/includes/footer.php'; ?>
