<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'queries';
$page_title = 'RGHS Query Panel';
$pdo = db();
$REASONS = query_reasons();
$PRIOS   = query_priorities();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $ret = $_POST['return'] ?? (BASE_URL.'/rghs_queries.php?scheme=RGHS');
    $who = current_user()['full_name'] ?? 'staff';
    if ($act === 'add') {
        $tid = trim($_POST['tid'] ?? '');
        $q   = trim($_POST['query_text'] ?? '');
        $ro  = trim($_POST['raised_on'] ?? '');
        $qt  = trim($_POST['qtype'] ?? '');
        $pr  = trim($_POST['priority'] ?? 'medium');
        if ($tid !== '' && $q !== '') {
            $pdo->prepare("INSERT INTO rghs_queries (tid,query_text,raised_on,qtype,priority,status,who) VALUES (?,?,?,?,?, 'open', ?)")
                ->execute([$tid, $q, $ro ?: null, $qt ?: null, $pr ?: 'medium', $who]);
            rghs_log('query_add', $tid);
            flash('Query add ho gayi.');
        } else flash('TID aur query text zaroori hai.', 'error');
    } elseif ($act === 'reply') {
        $id = (int)($_POST['id'] ?? 0);
        $reply = trim($_POST['reply_text'] ?? '');
        $qt  = trim($_POST['qtype'] ?? '');
        $pr  = trim($_POST['priority'] ?? 'medium');
        $asg = trim($_POST['assigned_to'] ?? '');
        $due = trim($_POST['due_date'] ?? '');
        $close = !empty($_POST['close_q']);
        $newStatus = $close ? 'closed' : ($reply !== '' ? 'replied' : 'open');
        $pdo->prepare("UPDATE rghs_queries SET reply_text=?, qtype=?, priority=?, assigned_to=?, due_date=?,
                replied_on=CASE WHEN ?<>'' THEN CURDATE() ELSE replied_on END, status=?, auto=0 WHERE id=?")
            ->execute([$reply ?: null, $qt ?: null, $pr ?: 'medium', $asg ?: null, $due ?: null, $reply, $newStatus, $id]);
        rghs_log('query_reply', 'id '.$id.($close?' (closed)':''));
        flash('Query update ho gayi.');
    } elseif ($act === 'reopen') {
        $pdo->prepare("UPDATE rghs_queries SET status='open' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Query dobara open ho gayi.');
    } elseif ($act === 'del') {
        $pdo->prepare("DELETE FROM rghs_queries WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Query delete ho gayi.');
    }
    redirect($ret);
}

$k = $pdo->query("SELECT
        SUM(status='open') o, SUM(status='replied') r, SUM(status='closed') c, COUNT(*) t,
        SUM(status<>'closed' AND due_date IS NOT NULL AND due_date < CURDATE()) overdue,
        AVG(CASE WHEN status<>'closed' AND raised_on IS NOT NULL THEN DATEDIFF(CURDATE(),raised_on) END) avg_open,
        AVG(CASE WHEN status='closed' AND replied_on IS NOT NULL AND raised_on IS NOT NULL AND replied_on>=raised_on THEN DATEDIFF(replied_on,raised_on) END) avg_res
    FROM rghs_queries")->fetch();

$st    = $_GET['st'] ?? 'active';
$search= trim($_GET['q'] ?? '');
$fType = trim($_GET['qtype'] ?? '');
$fPrio = trim($_GET['priority'] ?? '');
$fAsg  = trim($_GET['assignee'] ?? '');
$fFrom = trim($_GET['from'] ?? '');
$fTo   = trim($_GET['to'] ?? '');
$fAge  = trim($_GET['age'] ?? '');

$where = []; $args = [];
if ($st === 'active') $where[] = "q.status IN ('open','replied')";
elseif (in_array($st, ['open','replied','closed'], true)) { $where[] = 'q.status = ?'; $args[] = $st; }
if ($search !== '') { $where[] = '(q.tid LIKE ? OR q.query_text LIKE ? OR c.patient_name LIKE ? OR c.card_no LIKE ?)'; $l="%$search%"; array_push($args,$l,$l,$l,$l); }
if ($fType !== '') { $where[] = 'q.qtype = ?'; $args[] = $fType; }
if ($fPrio !== '') { $where[] = 'q.priority = ?'; $args[] = $fPrio; }
if ($fAsg === '__none') $where[] = "(q.assigned_to IS NULL OR q.assigned_to='')";
elseif ($fAsg !== '') { $where[] = 'q.assigned_to = ?'; $args[] = $fAsg; }
if ($fFrom !== '') { $where[] = 'q.raised_on >= ?'; $args[] = $fFrom; }
if ($fTo !== '')   { $where[] = 'q.raised_on <= ?'; $args[] = $fTo; }
if ($fAge !== '' && ctype_digit($fAge)) { $where[] = "q.raised_on IS NOT NULL AND DATEDIFF(CURDATE(),q.raised_on) > ?"; $args[] = (int)$fAge; }
$w = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$advOpen = ($fType||$fPrio||$fAsg||$fFrom||$fTo||$fAge);

$rows = $pdo->prepare("SELECT q.*, c.patient_name, c.card_no, c.status AS claim_status, c.doctor_name, c.department
    FROM rghs_queries q LEFT JOIN rghs_claims c ON c.tid = q.tid COLLATE utf8mb4_unicode_ci
    $w ORDER BY (q.status='closed') ASC, FIELD(q.priority,'high','medium','low'), COALESCE(q.raised_on, q.created_at) ASC LIMIT 300");
$rows->execute($args); $rows = $rows->fetchAll();

$uncovered = $pdo->query("SELECT tid, patient_name, card_no, status, query_status,
        DATEDIFF(CURDATE(), COALESCE(submit_date, first_seen)) age
    FROM rghs_claims c
    WHERE (status LIKE '%QUER%' OR status LIKE '%Quer%' OR status LIKE '%PENDING WITH%' OR status LIKE '%Pending with%')
      AND NOT EXISTS (SELECT 1 FROM rghs_queries q WHERE q.tid = c.tid COLLATE utf8mb4_unicode_ci)
    ORDER BY age DESC LIMIT 100")->fetchAll();
$uncoveredN = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims c
    WHERE (status LIKE '%QUER%' OR status LIKE '%Quer%' OR status LIKE '%PENDING WITH%' OR status LIKE '%Pending with%')
      AND NOT EXISTS (SELECT 1 FROM rghs_queries q WHERE q.tid = c.tid COLLATE utf8mb4_unicode_ci)")->fetch()['n'];

$monthly = $pdo->query("SELECT DATE_FORMAT(COALESCE(raised_on,created_at),'%Y-%m') ym, COUNT(*) n FROM rghs_queries GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
$monthly = array_reverse($monthly);
$byReason = $pdo->query("SELECT COALESCE(NULLIF(qtype,''),'(untagged)') t, COUNT(*) n FROM rghs_queries GROUP BY t ORDER BY n DESC")->fetchAll();
$byPrio   = $pdo->query("SELECT COALESCE(NULLIF(priority,''),'medium') p, COUNT(*) n FROM rghs_queries WHERE status<>'closed' GROUP BY p")->fetchAll();
$byDoctor = $pdo->query("SELECT c.doctor_name, COUNT(*) n FROM rghs_queries q JOIN rghs_claims c ON c.tid=q.tid COLLATE utf8mb4_unicode_ci
    WHERE c.doctor_name IS NOT NULL AND c.doctor_name<>'' GROUP BY c.doctor_name ORDER BY n DESC LIMIT 10")->fetchAll();
$byDept = $pdo->query("SELECT COALESCE(NULLIF(c.department,''),'—') dept, COUNT(*) n FROM rghs_queries q JOIN rghs_claims c ON c.tid=q.tid COLLATE utf8mb4_unicode_ci
    GROUP BY dept ORDER BY n DESC LIMIT 10")->fetchAll();

$staff = rghs_staff_list();
function prio_pill($p){ return $p==='high'?'rejected':($p==='low'?'info':'warn'); }
function qtab($k,$cur,$lbl,$n=null){ $on=$k===$cur?'on':''; $q=array_merge($_GET,['st'=>$k,'scheme'=>'RGHS']);
    return '<a class="tab '.$on.'" href="'.BASE_URL.'/rghs_queries.php?'.http_build_query($q).'">'.e($lbl).($n!==null?' <span class="muted">('.number_format($n).')</span>':'').'</a>'; }
function qmini($rows,$labelKey,$valKey,$max){ if(!$rows){echo '<p class="muted small">Data nahi.</p>';return;} foreach($rows as $r){ $w=$max?round($r[$valKey]/$max*100):0;
    echo '<div style="margin-bottom:6px"><div style="display:flex;justify-content:space-between;font-size:.8rem"><span>'.e($r[$labelKey]).'</span><strong>'.number_format($r[$valKey]).'</strong></div><div style="background:var(--line);border-radius:999px;height:7px;margin-top:2px"><div style="background:var(--brand);height:100%;border-radius:999px;width:'.$w.'%"></div></div></div>'; } }
$curUrl = BASE_URL.'/rghs_queries.php?'.http_build_query(array_merge($_GET,['scheme'=>'RGHS']));
require __DIR__ . '/includes/header.php';
?>
<a id="top"></a>
<div class="page-head">
    <h1>❓ RGHS Query Panel</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&cat=query">Query claims</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card warn"><div class="stat-num"><?= number_format($k['o'] ?: 0) ?></div><div class="stat-lbl">Open</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format($k['r'] ?: 0) ?></div><div class="stat-lbl">Replied (close baaki)</div></div>
    <div class="stat-card danger"><div class="stat-num"><?= number_format($k['overdue'] ?: 0) ?></div><div class="stat-lbl">Overdue</div></div>
    <div class="stat-card"><div class="stat-num"><?= $k['avg_res']!==null?round($k['avg_res']).' din':'-' ?></div><div class="stat-lbl">Avg resolve time</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= number_format($k['c'] ?: 0) ?></div><div class="stat-lbl">Closed</div></div>
</div>

<div class="card">
    <h3 style="margin-top:0">➕ Nayi query add karein</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="act" value="add"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
        <label class="fld"><span class="muted small">TID</span><input name="tid" required placeholder="e.g. 12345678" value="<?= e($_GET['for'] ?? '') ?>"></label>
        <label class="fld" style="flex:1;min-width:200px"><span class="muted small">Query / objection</span><input name="query_text" required placeholder="RGHS ne kya poocha?"></label>
        <label class="fld"><span class="muted small">Reason</span><select name="qtype"><option value="">—</option><?php foreach($REASONS as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span class="muted small">Priority</span><select name="priority"><?php foreach($PRIOS as $pk=>$pl): ?><option value="<?= $pk ?>" <?= $pk==='medium'?'selected':'' ?>><?= $pl ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span class="muted small">Raised on</span><input type="date" name="raised_on"></label>
        <button class="btn btn-primary">Add</button>
    </form>
</div>

<div class="tabs">
    <?= qtab('active',$st,'Active', ($k['o']?:0)+($k['r']?:0)) ?>
    <?= qtab('open',$st,'Open', $k['o']?:0) ?>
    <?= qtab('replied',$st,'Replied', $k['r']?:0) ?>
    <?= qtab('closed',$st,'Closed', $k['c']?:0) ?>
    <?= qtab('all',$st,'All', $k['t']?:0) ?>
</div>

<form class="searchbar" method="get" style="margin-bottom:6px">
    <input type="hidden" name="scheme" value="RGHS"><input type="hidden" name="st" value="<?= e($st) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="TID, patient, card, query text…" style="flex:1;min-width:220px">
    <button class="btn btn-primary">Search</button>
    <?php if ($search||$advOpen): ?><a class="btn btn-light" href="<?= BASE_URL ?>/rghs_queries.php?scheme=RGHS&st=<?= e($st) ?>">Reset</a><?php endif; ?>
    <details class="adv" style="width:100%;margin-top:8px" <?= $advOpen?'open':'' ?>>
        <summary class="link" style="cursor:pointer;font-size:.85rem">⚙️ Advanced filters</summary>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px">
            <label class="fld"><span class="muted small">Reason</span><select name="qtype"><option value="">Sab</option><?php foreach($REASONS as $r): ?><option value="<?= e($r) ?>" <?= $fType===$r?'selected':'' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
            <label class="fld"><span class="muted small">Priority</span><select name="priority"><option value="">Sab</option><?php foreach($PRIOS as $pk=>$pl): ?><option value="<?= $pk ?>" <?= $fPrio===$pk?'selected':'' ?>><?= $pl ?></option><?php endforeach; ?></select></label>
            <label class="fld"><span class="muted small">Assigned to</span><select name="assignee"><option value="">Sab</option><option value="__none" <?= $fAsg==='__none'?'selected':'' ?>>Bina assign</option><?php foreach($staff as $sf): ?><option value="<?= e($sf) ?>" <?= $fAsg===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?></select></label>
            <label class="fld"><span class="muted small">Raised se</span><input type="date" name="from" value="<?= e($fFrom) ?>"></label>
            <label class="fld"><span class="muted small">Raised tak</span><input type="date" name="to" value="<?= e($fTo) ?>"></label>
            <label class="fld"><span class="muted small">Age (din se purani)</span><input type="number" name="age" value="<?= e($fAge) ?>" placeholder="e.g. 15"></label>
        </div>
    </details>
</form>
<p class="muted small" style="margin:0 0 12px">🤖 Import ke waqt query-status claims ki query <strong>khud ban</strong> jaati hai (Auto tag), aur claim aage badhte hi <strong>khud close</strong> ho jaati hai.</p>

<div class="card">
    <?php if (!$rows): ?><p class="muted">Is filter me koi query nahi.</p><?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
    <?php foreach ($rows as $q): $age = $q['raised_on'] ? (int)floor((time()-strtotime($q['raised_on']))/86400) : null;
        $cls = $q['status']==='closed'?'settled':($q['status']==='replied'?'process':'warn');
        $overdue = $q['status']!=='closed' && $q['due_date'] && strtotime($q['due_date']) < strtotime(date('Y-m-d')); ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px<?= $overdue?';border-color:#dc3545':'' ?>">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                <div>
                    <a class="link" style="font-weight:700" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($q['tid']) ?>"><?= e($q['tid']) ?></a>
                    <span class="muted small"><?= e($q['patient_name']) ?> · <?= e($q['claim_status']) ?></span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <span class="pill pill-<?= prio_pill($q['priority']) ?>"><?= e(ucfirst($q['priority'] ?: 'medium')) ?></span>
                    <?php if ($q['qtype']): ?><span class="pill pill-info"><?= e($q['qtype']) ?></span><?php endif; ?>
                    <?php if ($q['auto']): ?><span class="pill pill-warn">🤖 auto</span><?php endif; ?>
                    <?php if ($age!==null && $q['status']!=='closed'): ?><span class="muted small"><?= $age ?> din</span><?php endif; ?>
                    <span class="pill pill-<?= $cls ?>"><?= e($q['status']) ?></span>
                </div>
            </div>
            <p class="small" style="margin:8px 0 4px"><strong>Query:</strong> <?= nl2br(e($q['query_text'])) ?>
                <?php if ($q['raised_on']): ?><span class="muted">· <?= fdate($q['raised_on']) ?></span><?php endif; ?>
                <?php if ($q['assigned_to']): ?><span class="muted">· 👤 <?= e($q['assigned_to']) ?></span><?php endif; ?>
                <?php if ($q['due_date']): ?><span class="<?= $overdue?'':'muted' ?>" style="<?= $overdue?'color:#dc3545;font-weight:600':'' ?>">· due <?= fdate($q['due_date']) ?></span><?php endif; ?></p>
            <?php if (!empty($q['reply_text'])): ?>
                <p class="small" style="margin:4px 0;padding-left:10px;border-left:3px solid var(--gold)"><strong>Reply:</strong> <?= nl2br(e($q['reply_text'])) ?>
                    <?php if ($q['replied_on']): ?><span class="muted">· <?= fdate($q['replied_on']) ?></span><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($q['status'] !== 'closed'): ?>
            <details style="margin-top:6px"><summary class="link small" style="cursor:pointer">Reply / update</summary>
            <form method="post" style="margin-top:8px">
                <?= csrf_field() ?><input type="hidden" name="act" value="reply"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
                <textarea name="reply_text" placeholder="Reply likhein…" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:9px"><?= e($q['reply_text']) ?></textarea>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:6px">
                    <label class="fld"><span class="muted small">Reason</span><select name="qtype"><option value="">—</option><?php foreach($REASONS as $r): ?><option value="<?= e($r) ?>" <?= $q['qtype']===$r?'selected':'' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
                    <label class="fld"><span class="muted small">Priority</span><select name="priority"><?php foreach($PRIOS as $pk=>$pl): ?><option value="<?= $pk ?>" <?= ($q['priority']?:'medium')===$pk?'selected':'' ?>><?= $pl ?></option><?php endforeach; ?></select></label>
                    <label class="fld"><span class="muted small">Assign</span><select name="assigned_to"><option value="">—</option><?php foreach($staff as $sf): ?><option value="<?= e($sf) ?>" <?= $q['assigned_to']===$sf?'selected':'' ?>><?= e($sf) ?></option><?php endforeach; ?></select></label>
                    <label class="fld"><span class="muted small">Due</span><input type="date" name="due_date" value="<?= e($q['due_date']) ?>"></label>
                    <label class="inline" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="close_q" value="1"> Close</label>
                    <button class="btn btn-sm btn-primary">Save</button>
                </div>
            </form>
            </details>
            <?php else: ?>
            <form method="post" style="margin-top:6px;display:inline">
                <?= csrf_field() ?><input type="hidden" name="act" value="reopen"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
                <button class="btn btn-sm btn-light">↩ Reopen</button>
            </form>
            <?php endif; ?>
            <form method="post" style="margin-top:6px;display:inline" onsubmit="return confirm('Query delete karein?')">
                <?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
                <button class="btn btn-sm btn-danger">✕</button>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($uncovered): ?>
<div class="card" style="border-left:4px solid var(--warn)">
    <h2>⚠️ Query / stuck claims — abhi tak track nahi (<?= number_format($uncoveredN) ?>)</h2>
    <p class="muted small">Ye claims query ya "pending with TPA/CU" me hain par inka koi query log nahi hua. "+ Query" se track shuru karein.</p>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>TID</th><th>Patient</th><th>Status</th><th>Query status</th><th class="r">Age</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($uncovered as $u): $ac=$u['age']>30?'style="color:#dc3545;font-weight:600"':''; ?>
            <tr><td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($u['tid']) ?>"><?= e($u['tid']) ?></a></td>
                <td><?= e($u['patient_name']) ?></td><td class="small"><?= e($u['status']) ?></td><td class="small"><?= e($u['query_status']) ?></td>
                <td class="r" <?= $ac ?>><?= (int)$u['age'] ?>d</td>
                <td class="r"><a class="btn btn-sm" href="<?= BASE_URL ?>/rghs_queries.php?scheme=RGHS&for=<?= urlencode($u['tid']) ?>#top">+ Query</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <h2>📊 Query Analytics</h2>
    <div class="detail-grid">
        <div>
            <h3 style="margin:0 0 8px">Monthly queries</h3>
            <?php if(!$monthly): ?><p class="muted small">Data nahi.</p><?php else: $mm=1; foreach($monthly as $m)$mm=max($mm,$m['n']); ?>
            <div class="barchart">
                <?php foreach($monthly as $m): $h=round($m['n']/$mm*120); $ts=strtotime($m['ym'].'-01'); ?>
                    <div class="bar-col" title="<?= e($m['ym']) ?>: <?= $m['n'] ?>"><div class="bar" style="height:<?= max(3,$h) ?>px"></div>
                        <div class="bar-lbl"><?= $ts?date('M y',$ts):e($m['ym']) ?></div><div class="bar-val"><?= (int)$m['n'] ?></div></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="muted small" style="margin-top:8px">Avg resolve time: <strong><?= $k['avg_res']!==null?round($k['avg_res']).' din':'-' ?></strong> · Avg open age: <strong><?= $k['avg_open']!==null?round($k['avg_open']).' din':'-' ?></strong></p>
        </div>
        <div>
            <h3 style="margin:0 0 8px">Reason-wise (repeat objection)</h3>
            <?php $rmax=1; foreach($byReason as $r)$rmax=max($rmax,$r['n']); qmini($byReason,'t','n',$rmax); ?>
        </div>
    </div>
    <div class="detail-grid" style="margin-top:8px">
        <div>
            <h3 style="margin:0 0 8px">Doctor-wise queries (top)</h3>
            <?php if(!$byDoctor): ?><p class="muted small">Doctor data nahi.</p>
            <?php else: $dmax=1; foreach($byDoctor as $d)$dmax=max($dmax,$d['n']); qmini($byDoctor,'doctor_name','n',$dmax); endif; ?>
        </div>
        <div>
            <h3 style="margin:0 0 8px">Department-wise queries (top)</h3>
            <?php if(!$byDept): ?><p class="muted small">Data nahi.</p>
            <?php else: $emax=1; foreach($byDept as $d)$emax=max($emax,$d['n']); qmini($byDept,'dept','n',$emax); endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
