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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $ret = $_POST['return'] ?? (BASE_URL.'/rghs_queries.php?scheme=RGHS');
    $who = current_user()['full_name'] ?? 'staff';
    if ($act === 'add') {
        $tid = trim($_POST['tid'] ?? '');
        $q   = trim($_POST['query_text'] ?? '');
        $ro  = trim($_POST['raised_on'] ?? '');
        if ($tid !== '' && $q !== '') {
            $pdo->prepare("INSERT INTO rghs_queries (tid,query_text,raised_on,status,who) VALUES (?,?,?, 'open', ?)")
                ->execute([$tid, $q, $ro ?: null, $who]);
            rghs_log('query_add', $tid);
            flash('Query add ho gayi.');
        } else flash('TID aur query text zaroori hai.', 'error');
    } elseif ($act === 'reply') {
        $id = (int)($_POST['id'] ?? 0);
        $reply = trim($_POST['reply_text'] ?? '');
        $close = !empty($_POST['close_q']);
        $pdo->prepare("UPDATE rghs_queries SET reply_text=?, replied_on=CURDATE(), status=? WHERE id=?")
            ->execute([$reply ?: null, $close ? 'closed' : 'replied', $id]);
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
        AVG(CASE WHEN status<>'closed' AND raised_on IS NOT NULL THEN DATEDIFF(CURDATE(),raised_on) END) avg_open
    FROM rghs_queries")->fetch();

$st = $_GET['st'] ?? 'active';
$search = trim($_GET['q'] ?? '');

$where = []; $args = [];
if ($st === 'active') $where[] = "q.status IN ('open','replied')";
elseif (in_array($st, ['open','replied','closed'], true)) { $where[] = 'q.status = ?'; $args[] = $st; }
if ($search !== '') {
    $where[] = '(q.tid LIKE ? OR q.query_text LIKE ? OR c.patient_name LIKE ? OR c.card_no LIKE ?)';
    $l = "%$search%"; array_push($args, $l, $l, $l, $l);
}
$w = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$rows = $pdo->prepare("SELECT q.*, c.patient_name, c.card_no, c.status AS claim_status
    FROM rghs_queries q LEFT JOIN rghs_claims c ON c.tid = q.tid COLLATE utf8mb4_unicode_ci
    $w ORDER BY (q.status='closed') ASC, COALESCE(q.raised_on, q.created_at) ASC LIMIT 300");
$rows->execute($args); $rows = $rows->fetchAll();

// portal query/pending-with claims without a tracked query
$uncovered = $pdo->query("SELECT tid, patient_name, card_no, status, query_status,
        DATEDIFF(CURDATE(), COALESCE(submit_date, first_seen)) age
    FROM rghs_claims c
    WHERE (status LIKE '%QUER%' OR status LIKE '%Quer%' OR status LIKE '%PENDING WITH%' OR status LIKE '%Pending with%')
      AND NOT EXISTS (SELECT 1 FROM rghs_queries q WHERE q.tid = c.tid COLLATE utf8mb4_unicode_ci)
    ORDER BY age DESC LIMIT 100")->fetchAll();
$uncoveredN = (int)$pdo->query("SELECT COUNT(*) n FROM rghs_claims c
    WHERE (status LIKE '%QUER%' OR status LIKE '%Quer%' OR status LIKE '%PENDING WITH%' OR status LIKE '%Pending with%')
      AND NOT EXISTS (SELECT 1 FROM rghs_queries q WHERE q.tid = c.tid COLLATE utf8mb4_unicode_ci)")->fetch()['n'];

function qtab($k,$cur,$lbl,$n=null){ $on=$k===$cur?'on':''; $q=array_merge($_GET,['st'=>$k,'scheme'=>'RGHS']);
    return '<a class="tab '.$on.'" href="'.BASE_URL.'/rghs_queries.php?'.http_build_query($q).'">'.e($lbl).($n!==null?' <span class="muted">('.number_format($n).')</span>':'').'</a>'; }
function fdate($d){ return $d ? date('d-m-Y', strtotime($d)) : '-'; }
$curUrl = BASE_URL.'/rghs_queries.php?'.http_build_query(array_merge($_GET,['scheme'=>'RGHS']));
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>❓ RGHS Query Panel</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS&cat=query">Query claims</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card warn"><div class="stat-num"><?= number_format($k['o'] ?: 0) ?></div><div class="stat-lbl">Open</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format($k['r'] ?: 0) ?></div><div class="stat-lbl">Replied (baaki close)</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= number_format($k['c'] ?: 0) ?></div><div class="stat-lbl">Closed</div></div>
    <div class="stat-card"><div class="stat-num"><?= $k['avg_open']!==null?round($k['avg_open']).' din':'-' ?></div><div class="stat-lbl">Avg open age</div></div>
</div>

<div class="card">
    <h3 style="margin-top:0">➕ Nayi query add karein</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="act" value="add"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
        <label class="fld"><span class="muted small">TID</span><input name="tid" required placeholder="e.g. 12345678" value="<?= e($_GET['for'] ?? '') ?>"></label>
        <label class="fld" style="flex:1;min-width:220px"><span class="muted small">Query / objection</span><input name="query_text" required placeholder="RGHS ne kya poocha?"></label>
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

<form class="searchbar" method="get" style="margin-bottom:12px">
    <input type="hidden" name="scheme" value="RGHS"><input type="hidden" name="st" value="<?= e($st) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="TID, patient, card, query text…" style="flex:1;min-width:220px">
    <button class="btn btn-primary">Search</button>
    <?php if ($search): ?><a class="btn btn-light" href="<?= BASE_URL ?>/rghs_queries.php?scheme=RGHS&st=<?= e($st) ?>">Reset</a><?php endif; ?>
</form>

<div class="card">
    <?php if (!$rows): ?><p class="muted">Is filter me koi query nahi.</p><?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
    <?php foreach ($rows as $q): $age = $q['raised_on'] ? (int)floor((time()-strtotime($q['raised_on']))/86400) : null;
        $cls = $q['status']==='closed'?'settled':($q['status']==='replied'?'process':'warn'); ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                <div>
                    <a class="link" style="font-weight:700" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($q['tid']) ?>"><?= e($q['tid']) ?></a>
                    <span class="muted small"><?= e($q['patient_name']) ?> · <?= e($q['claim_status']) ?></span>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <?php if ($age!==null && $q['status']!=='closed'): ?><span class="muted small"><?= $age ?> din</span><?php endif; ?>
                    <span class="pill pill-<?= $cls ?>"><?= e($q['status']) ?></span>
                </div>
            </div>
            <p class="small" style="margin:8px 0 4px"><strong>Query:</strong> <?= nl2br(e($q['query_text'])) ?>
                <?php if ($q['raised_on']): ?><span class="muted">· <?= fdate($q['raised_on']) ?></span><?php endif; ?></p>
            <?php if (!empty($q['reply_text'])): ?>
                <p class="small" style="margin:4px 0;padding-left:10px;border-left:3px solid var(--gold)"><strong>Reply:</strong> <?= nl2br(e($q['reply_text'])) ?>
                    <?php if ($q['replied_on']): ?><span class="muted">· <?= fdate($q['replied_on']) ?></span><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($q['status'] !== 'closed'): ?>
            <form method="post" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <?= csrf_field() ?><input type="hidden" name="act" value="reply"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
                <input name="reply_text" placeholder="Reply likhein…" value="<?= e($q['reply_text']) ?>" style="flex:1;min-width:220px;padding:8px;border:1px solid var(--line);border-radius:9px">
                <label class="inline" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="close_q" value="1"> Close</label>
                <button class="btn btn-sm btn-primary">Save</button>
            </form>
            <?php else: ?>
            <form method="post" style="margin-top:6px;display:inline">
                <?= csrf_field() ?><input type="hidden" name="act" value="reopen"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
                <button class="btn btn-sm btn-light">↩ Reopen</button>
            </form>
            <?php endif; ?>
            <form method="post" style="margin-top:6px;display:inline" onsubmit="return confirm('Query delete karein?')">
                <?= csrf_field() ?><input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="return" value="<?= e($curUrl) ?>">
                <button class="btn btn-sm btn-danger">✕ Delete</button>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($uncovered): ?>
<div class="card" style="border-left:4px solid var(--warn)">
    <h2>⚠️ Query / stuck claims — abhi tak track nahi (<?= number_format($uncoveredN) ?>)</h2>
    <p class="muted small">Ye claims RGHS portal par query ya "pending with TPA/CU" me hain par inka koi query yahan log nahi hua. Track shuru karein taaki reply na chhoote.</p>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>TID</th><th>Patient</th><th>Status</th><th>Query status</th><th class="r">Age</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($uncovered as $u): $ac=$u['age']>30?'style="color:#dc3545;font-weight:600"':''; ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($u['tid']) ?>"><?= e($u['tid']) ?></a></td>
                <td><?= e($u['patient_name']) ?></td>
                <td class="small"><?= e($u['status']) ?></td>
                <td class="small"><?= e($u['query_status']) ?></td>
                <td class="r" <?= $ac ?>><?= (int)$u['age'] ?>d</td>
                <td class="r"><a class="btn btn-sm" href="<?= BASE_URL ?>/rghs_queries.php?scheme=RGHS&for=<?= urlencode($u['tid']) ?>#top">+ Query</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
