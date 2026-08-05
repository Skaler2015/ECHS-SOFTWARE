<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'queries';
$page_title = 'ECHS Query Panel';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $ret = $_POST['return'] ?? (BASE_URL.'/echs_queries.php?scheme=ECHS');
    $who = current_user()['full_name'] ?? 'staff';
    if ($act === 'add') {
        $cid = trim($_POST['claim_id'] ?? '');
        $q   = trim($_POST['query_text'] ?? '');
        $ro  = trim($_POST['raised_on'] ?? '');
        if ($cid !== '' && $q !== '') {
            $pdo->prepare("INSERT INTO echs_queries (claim_id,query_text,raised_on,status,who) VALUES (?,?,?, 'open', ?)")
                ->execute([$cid, $q, $ro ?: null, $who]);
            echs_log('query_add', $cid);
            flash('Query add ho gayi.');
        } else flash('Claim ID aur query text zaroori hai.', 'error');
    } elseif ($act === 'reply') {
        $id = (int)($_POST['id'] ?? 0);
        $reply = trim($_POST['reply_text'] ?? '');
        $close = !empty($_POST['close_q']);
        $pdo->prepare("UPDATE echs_queries SET reply_text=?, replied_on=CURDATE(), status=? WHERE id=?")
            ->execute([$reply ?: null, $close ? 'closed' : 'replied', $id]);
        echs_log('query_reply', 'id '.$id.($close?' (closed)':''));
        flash('Query update ho gayi.');
    } elseif ($act === 'reopen') {
        $pdo->prepare("UPDATE echs_queries SET status='open' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Query dobara open ho gayi.');
    } elseif ($act === 'del') {
        $pdo->prepare("DELETE FROM echs_queries WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Query delete ho gayi.');
    }
    redirect($ret);
}

// KPIs
$k = $pdo->query("SELECT
        SUM(status='open') o, SUM(status='replied') r, SUM(status='closed') c, COUNT(*) t,
        AVG(CASE WHEN status<>'closed' AND raised_on IS NOT NULL THEN DATEDIFF(CURDATE(),raised_on) END) avg_open
    FROM echs_queries")->fetch();

$st = $_GET['st'] ?? 'active';   // active (open+replied) | open | replied | closed | all
$search = trim($_GET['q'] ?? '');

$where = []; $args = [];
if ($st === 'active') $where[] = "q.status IN ('open','replied')";
elseif (in_array($st, ['open','replied','closed'], true)) { $where[] = 'q.status = ?'; $args[] = $st; }
if ($search !== '') {
    $where[] = '(q.claim_id LIKE ? OR q.query_text LIKE ? OR c.patient_name LIKE ? OR c.esm_name LIKE ?)';
    $l = "%$search%"; array_push($args, $l, $l, $l, $l);
}
$w = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$rows = $pdo->prepare("SELECT q.*, c.patient_name, c.esm_name, c.status AS claim_status, c.category
    FROM echs_queries q LEFT JOIN echs_claims c ON c.claim_id = q.claim_id COLLATE utf8mb4_unicode_ci
    $w ORDER BY (q.status='closed') ASC, COALESCE(q.raised_on, q.created_at) ASC LIMIT 300");
$rows->execute($args); $rows = $rows->fetchAll();

// portal Need-More-Info claims that have NO tracked query yet (nothing slips)
$uncovered = $pdo->query("SELECT claim_id, patient_name, esm_name, status,
        DATEDIFF(CURDATE(), COALESCE(accept_date, first_seen)) age
    FROM echs_claims c
    WHERE category='query'
      AND NOT EXISTS (SELECT 1 FROM echs_queries q WHERE q.claim_id = c.claim_id COLLATE utf8mb4_unicode_ci)
    ORDER BY age DESC LIMIT 100")->fetchAll();
$uncoveredN = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims c WHERE category='query' AND NOT EXISTS (SELECT 1 FROM echs_queries q WHERE q.claim_id = c.claim_id COLLATE utf8mb4_unicode_ci)")->fetch()['n'];

function qtab($k,$cur,$lbl,$n=null){ $on=$k===$cur?'on':''; $q=array_merge($_GET,['st'=>$k,'scheme'=>'ECHS']);
    return '<a class="tab '.$on.'" href="'.BASE_URL.'/echs_queries.php?'.http_build_query($q).'">'.e($lbl).($n!==null?' <span class="muted">('.number_format($n).')</span>':'').'</a>'; }
function fdate($d){ return $d ? date('d-m-Y', strtotime($d)) : '-'; }
$curUrl = BASE_URL.'/echs_queries.php?'.http_build_query(array_merge($_GET,['scheme'=>'ECHS']));
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>❓ ECHS Query Panel</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&cat=query">Query claims</a>
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
        <label class="fld"><span class="muted small">Claim ID</span><input name="claim_id" required placeholder="e.g. 22613845" value="<?= e($_GET['for'] ?? '') ?>"></label>
        <label class="fld" style="flex:1;min-width:220px"><span class="muted small">Query / objection</span><input name="query_text" required placeholder="ECHS ne kya poocha?"></label>
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
    <input type="hidden" name="scheme" value="ECHS"><input type="hidden" name="st" value="<?= e($st) ?>">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Claim ID, patient, ESM, query text…" style="flex:1;min-width:220px">
    <button class="btn btn-primary">Search</button>
    <?php if ($search): ?><a class="btn btn-light" href="<?= BASE_URL ?>/echs_queries.php?scheme=ECHS&st=<?= e($st) ?>">Reset</a><?php endif; ?>
</form>

<div class="card">
    <?php if (!$rows): ?><p class="muted">Is filter me koi query nahi.</p><?php else: ?>
    <div style="display:flex;flex-direction:column;gap:10px">
    <?php foreach ($rows as $q): $age = $q['raised_on'] ? (int)floor((time()-strtotime($q['raised_on']))/86400) : null;
        $cls = $q['status']==='closed'?'settled':($q['status']==='replied'?'process':'warn'); ?>
        <div style="border:1px solid var(--line);border-radius:10px;padding:12px">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
                <div>
                    <a class="link" style="font-weight:700" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($q['claim_id']) ?>"><?= e($q['claim_id']) ?></a>
                    <span class="muted small"><?= e($q['patient_name'] ?: $q['esm_name']) ?> · <?= e($q['claim_status']) ?></span>
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
    <h2>⚠️ Portal "Need More Information" claims — abhi tak query track nahi (<?= number_format($uncoveredN) ?>)</h2>
    <p class="muted small">Ye claims ECHS portal par query/need-info me hain par inka koi query yahan log nahi hua. "Query add karein" se track shuru karein taaki reply na chhoote.</p>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Status</th><th class="r">Age</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($uncovered as $u): $ac=$u['age']>30?'style="color:#dc3545;font-weight:600"':''; ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($u['claim_id']) ?>"><?= e($u['claim_id']) ?></a></td>
                <td><?= e($u['patient_name'] ?: $u['esm_name']) ?></td>
                <td class="small"><?= e($u['status']) ?></td>
                <td class="r" <?= $ac ?>><?= (int)$u['age'] ?>d</td>
                <td class="r"><a class="btn btn-sm" href="<?= BASE_URL ?>/echs_queries.php?scheme=ECHS&for=<?= urlencode($u['claim_id']) ?>#top">+ Query</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
