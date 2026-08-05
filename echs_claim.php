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
    if (is_panel()) panel_head($page_title); else require __DIR__ . '/includes/header.php';
    echo '<div class="card"><p class="muted">Claim ('.e($id).') nahi mila.</p><p><a class="btn" href="'.BASE_URL.'/echs_claims.php?scheme=ECHS">← Claims</a></p></div>';
    if (is_panel()) panel_foot(); else require __DIR__ . '/includes/footer.php';
    return;
}
$page_title = 'Claim ' . $c['claim_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $note = trim($_POST['notes'] ?? '');
        $assignee = trim($_POST['assigned_to'] ?? '');
        $fu = !empty($_POST['followup']) ? 1 : 0;
        $pdo->prepare("UPDATE echs_claims SET notes=?, followup=?, assigned_to=?, updated_at=NOW() WHERE claim_id=?")
            ->execute([$note ?: null, $fu, $assignee ?: null, $id]);
        flash('Save ho gaya.');
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':''));
    } elseif ($act === 'save_doctors') {
        // multiple doctors on one claim, each with an amount; sum must equal the bill
        $names = (array)($_POST['doc_name'] ?? []);
        $amts  = (array)($_POST['doc_amount'] ?? []);
        $target = ($_POST['target'] ?? 'claim') === 'approved' ? 'approved' : 'claim';
        $base = $target === 'approved' ? (float)$c['approved_amt'] : (float)$c['claim_amt'];
        $rows = []; $sum = 0.0; $bad = false;
        foreach ($names as $i => $nm) {
            $nm  = trim((string)$nm);
            $amt = (float)preg_replace('/[^0-9.]/', '', (string)($amts[$i] ?? '0'));
            if ($nm === '' && $amt == 0) continue;
            if ($nm === '') { $bad = true; continue; }
            $rows[] = [$nm, $amt]; $sum += $amt;
        }
        if ($bad) {
            flash('Har row me doctor ka naam zaroori hai.', 'error');
        } elseif (!$rows) {
            $pdo->prepare("DELETE FROM echs_claim_doctors WHERE claim_id=?")->execute([$id]);
            $pdo->prepare("UPDATE echs_claims SET doctor_name=NULL, updated_at=NOW() WHERE claim_id=?")->execute([$id]);
            flash('Doctors hata diye.');
        } elseif ($base > 0 && abs($sum - $base) > 1.0) {
            flash('Doctor amounts ka total '.money($sum).' hai, par bill '.money($base).' hai. Farq: '.money(abs($sum-$base)).' — dono barabar karein.', 'error');
        } else {
            $pdo->prepare("DELETE FROM echs_claim_doctors WHERE claim_id=?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO echs_claim_doctors (claim_id,doctor_name,amount) VALUES (?,?,?)");
            $ddoc = $pdo->prepare("INSERT IGNORE INTO echs_doctors (name) VALUES (?)");
            $uniq = [];
            foreach ($rows as $r) { $ins->execute([$id, $r[0], $r[1]]); try { $ddoc->execute([$r[0]]); } catch (Exception $e) {} $uniq[$r[0]] = true; }
            $pdo->prepare("UPDATE echs_claims SET doctor_name=?, doctor_manual=1, updated_at=NOW() WHERE claim_id=?")
                ->execute([implode(', ', array_keys($uniq)), $id]);
            echs_log('doctor_split', $id.' · '.count($rows).' doctors · '.money($sum));
            flash('Doctor split save ho gaya.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':'').'#doctors');
    } elseif ($act === 'addnote') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            $who = current_user()['full_name'] ?? 'staff';
            $pdo->prepare("INSERT INTO echs_notes (claim_id,note,who) VALUES (?,?,?)")->execute([$id, $note, $who]);
            flash('Note add ho gaya.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':''));
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
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':''));
    } elseif ($act === 'del_doc') {
        $did = (int)($_POST['doc_id'] ?? 0);
        $d = $pdo->prepare("SELECT stored_name FROM echs_docs WHERE id=? AND claim_id=?");
        $d->execute([$did, $id]); $drow = $d->fetch();
        if ($drow) {
            @unlink(__DIR__ . '/uploads/echs/' . basename($drow['stored_name']));
            $pdo->prepare("DELETE FROM echs_docs WHERE id=?")->execute([$did]);
            flash('Document delete ho gaya.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':''));
    } elseif ($act === 'add_query') {
        $q = trim($_POST['query_text'] ?? '');
        $ro = trim($_POST['raised_on'] ?? '');
        if ($q !== '') {
            $who = current_user()['full_name'] ?? 'staff';
            $pdo->prepare("INSERT INTO echs_queries (claim_id,query_text,raised_on,status,who) VALUES (?,?,?, 'open', ?)")
                ->execute([$id, $q, $ro ?: null, $who]);
            flash('Query add ho gayi.');
        }
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':''));
    } elseif ($act === 'reply_query') {
        $qid = (int)($_POST['query_id'] ?? 0);
        $reply = trim($_POST['reply_text'] ?? '');
        $close = !empty($_POST['close_q']);
        $pdo->prepare("UPDATE echs_queries SET reply_text=?, replied_on=CURDATE(), status=? WHERE id=? AND claim_id=?")
            ->execute([$reply ?: null, $close ? 'closed' : 'replied', $qid, $id]);
        flash('Query update ho gayi.');
        redirect(BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($id).(is_panel()?'&panel=1':''));
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

$cdoc = $pdo->prepare("SELECT * FROM echs_claim_doctors WHERE claim_id=? ORDER BY id");
$cdoc->execute([$id]); $claimDoctors = $cdoc->fetchAll();
$cdocSum = 0; foreach ($claimDoctors as $cd) $cdocSum += (float)$cd['amount'];

// other claims of same card
$more = [];
if (!empty($c['card_id'])) {
    $m = $pdo->prepare("SELECT claim_id, status, category, claim_amt, accept_date FROM echs_claims WHERE card_id=? AND claim_id<>? ORDER BY accept_date DESC LIMIT 30");
    $m->execute([$c['card_id'], $id]); $more = $m->fetchAll();
}

$cat = $c['category'] ?: echs_category($c['status']);
if (is_panel()) panel_head($page_title); else require __DIR__ . '/includes/header.php';
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

<?php
$billClaim = (float)$c['claim_amt']; $billAppr = (float)$c['approved_amt'];
$editorRows = $claimDoctors ?: [['doctor_name'=>'','amount'=>'']];
?>
<a id="doctors"></a>
<div class="card">
    <h2>🩺 Doctors & amount split</h2>
    <p class="muted small">Ek claim par kai doctor ho sakte hain — har doctor ki alag amount likhein. Sab amounts ka <strong>total = bill</strong> hona chahiye (tabhi save hoga).</p>
    <form method="post" id="docsplitForm">
        <?= csrf_field() ?><input type="hidden" name="act" value="save_doctors">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
            <label class="fld" style="margin:0"><span class="muted small">Bill (match target)</span>
                <select name="target" id="splitTarget">
                    <option value="claim" data-amt="<?= $billClaim ?>">Net claim — <?= money($billClaim) ?></option>
                    <option value="approved" data-amt="<?= $billAppr ?>" <?= ($cat==='settled')?'selected':'' ?>>Approved/Settled — <?= money($billAppr) ?></option>
                </select>
            </label>
            <div class="split-meter" style="flex:1;min-width:200px">
                <div class="muted small">Doctors total: <strong id="splitSum">₹0</strong> / Bill: <strong id="splitBase">₹0</strong></div>
                <div style="background:var(--line);border-radius:999px;height:10px;overflow:hidden;margin-top:4px"><div id="splitBar" style="height:100%;width:0;background:var(--brand);transition:width .2s"></div></div>
                <div class="small" id="splitMsg" style="margin-top:4px"></div>
            </div>
        </div>
        <datalist id="rdocs"><?php foreach (echs_doctor_list() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
        <div id="docRows">
        <?php foreach ($editorRows as $r): ?>
            <div class="docrow" style="display:flex;gap:8px;margin-bottom:8px;align-items:center">
                <input name="doc_name[]" list="rdocs" placeholder="Doctor naam" value="<?= e($r['doctor_name']) ?>" style="flex:1;min-width:150px;padding:8px;border:1px solid var(--line);border-radius:9px">
                <input name="doc_amount[]" class="docamt" inputmode="decimal" placeholder="Amount ₹" value="<?= $r['amount']!==''?e(rtrim(rtrim(number_format((float)$r['amount'],2,'.',''),'0'),'.')):'' ?>" style="width:130px;padding:8px;border:1px solid var(--line);border-radius:9px;text-align:right">
                <button type="button" class="btn btn-sm btn-danger docdel" title="Hatao">✕</button>
            </div>
        <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
            <button type="button" class="btn btn-sm" id="addDocRow">+ Doctor add</button>
            <button type="button" class="btn btn-sm" id="fillRemain">Baaki amount bhar do</button>
            <button type="button" class="btn btn-sm" id="splitEqual">Barabar baant do</button>
            <span style="flex:1"></span>
            <button class="btn btn-primary" id="splitSave">Save doctors</button>
        </div>
    </form>
</div>

<div class="detail-grid">
    <div class="card form">
        <h2>Assign · Flag · Note</h2>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="act" value="save">
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

<script>
(function(){
    var form = document.getElementById('docsplitForm');
    if (!form) return;
    var rows = document.getElementById('docRows');
    var targetSel = document.getElementById('splitTarget');
    var sumEl = document.getElementById('splitSum'), baseEl = document.getElementById('splitBase');
    var barEl = document.getElementById('splitBar'), msgEl = document.getElementById('splitMsg');
    function inr(n){ return '₹' + (Math.round(n*100)/100).toLocaleString('en-IN'); }
    function base(){ var o = targetSel.options[targetSel.selectedIndex]; return parseFloat(o.getAttribute('data-amt')||'0')||0; }
    function sum(){ var s=0; rows.querySelectorAll('.docamt').forEach(function(a){ s += parseFloat(String(a.value).replace(/[^0-9.]/g,''))||0; }); return s; }
    function refresh(){
        var s=sum(), b=base();
        sumEl.textContent=inr(s); baseEl.textContent=inr(b);
        var pct = b>0 ? Math.min(100, s/b*100) : (s>0?100:0);
        barEl.style.width = pct+'%';
        var diff = Math.round((s-b)*100)/100;
        if (b<=0){ barEl.style.background='var(--brand)'; msgEl.textContent='Is claim ka bill 0 hai — koi bhi amount chalega.'; msgEl.style.color='var(--muted)'; }
        else if (Math.abs(diff)<=1){ barEl.style.background='#16A34A'; msgEl.textContent='✅ Total bill ke barabar hai.'; msgEl.style.color='#16A34A'; }
        else if (diff<0){ barEl.style.background='#f59e0b'; msgEl.textContent='Abhi '+inr(-diff)+' baaki hai.'; msgEl.style.color='#b45309'; }
        else { barEl.style.background='#dc3545'; msgEl.textContent='⚠️ '+inr(diff)+' zyada hai — kam karein.'; msgEl.style.color='#dc3545'; }
    }
    function newRow(name, amt){
        var div=document.createElement('div'); div.className='docrow';
        div.style.cssText='display:flex;gap:8px;margin-bottom:8px;align-items:center';
        div.innerHTML='<input name="doc_name[]" list="rdocs" placeholder="Doctor naam" style="flex:1;min-width:150px;padding:8px;border:1px solid var(--line);border-radius:9px">'
            +'<input name="doc_amount[]" class="docamt" inputmode="decimal" placeholder="Amount ₹" style="width:130px;padding:8px;border:1px solid var(--line);border-radius:9px;text-align:right">'
            +'<button type="button" class="btn btn-sm btn-danger docdel" title="Hatao">✕</button>';
        rows.appendChild(div);
        if(name) div.querySelector('input[name="doc_name[]"]').value=name;
        if(amt!=null) div.querySelector('.docamt').value=amt;
        return div;
    }
    document.getElementById('addDocRow').addEventListener('click', function(){ newRow(); refresh(); });
    document.getElementById('fillRemain').addEventListener('click', function(){
        var b=base(), s=sum(), rem=Math.round((b-s)*100)/100;
        if (rem<=0){ return; }
        var empty=null; rows.querySelectorAll('.docrow').forEach(function(r){ var a=r.querySelector('.docamt'); if(!empty && (!a.value || parseFloat(a.value)===0)) empty=a; });
        if (empty) empty.value=rem; else newRow('', rem);
        refresh();
    });
    document.getElementById('splitEqual').addEventListener('click', function(){
        var b=base(); var rs=rows.querySelectorAll('.docrow'); if(!b||!rs.length) return;
        var each=Math.floor(b/rs.length*100)/100; var used=0;
        rs.forEach(function(r,i){ var a=r.querySelector('.docamt'); var v=(i===rs.length-1)?Math.round((b-used)*100)/100:each; a.value=v; used+=v; });
        refresh();
    });
    rows.addEventListener('click', function(e){ if(e.target.classList.contains('docdel')){ if(rows.querySelectorAll('.docrow').length>1) e.target.closest('.docrow').remove(); else { e.target.closest('.docrow').querySelectorAll('input').forEach(function(i){i.value='';}); } refresh(); } });
    rows.addEventListener('input', function(e){ if(e.target.classList.contains('docamt')) refresh(); });
    targetSel.addEventListener('change', refresh);
    form.addEventListener('submit', function(e){
        var b=base(), s=sum();
        var hasRow=false; rows.querySelectorAll('.docrow').forEach(function(r){ if(r.querySelector('input[name="doc_name[]"]').value.trim()!=='') hasRow=true; });
        if (hasRow && b>0 && Math.abs(s-b)>1){ e.preventDefault(); alert('Doctor amounts ka total '+inr(s)+' hai, par bill '+inr(b)+' hai. Pehle dono barabar karein (ya "Baaki amount bhar do" dabayein).'); }
    });
    refresh();
})();
</script>

<?php if (is_panel()) panel_foot(); else require __DIR__ . '/includes/footer.php'; ?>
