<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'claims';
$page_title = 'ECHS Claims';

// ---- filters ----
$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$ptype  = trim($_GET['ptype'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$amin   = trim($_GET['amin'] ?? '');
$amax   = trim($_GET['amax'] ?? '');
$sort   = $_GET['sort'] ?? 'accept';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;

$where = []; $args = [];
if ($q !== '') {
    $where[] = '(claim_id LIKE ? OR card_id LIKE ? OR esm_name LIKE ? OR patient_name LIKE ?)';
    $like = "%$q%"; array_push($args, $like, $like, $like, $like);
}
if ($status !== '') { $where[] = 'status = ?'; $args[] = $status; }
if ($ptype !== '')  { $where[] = 'patient_type = ?'; $args[] = $ptype; }
if ($from !== '')   { $where[] = 'accept_date >= ?'; $args[] = $from; }
if ($to !== '')     { $where[] = 'accept_date <= ?'; $args[] = $to; }
if ($amin !== '')   { $where[] = 'net_claim_amt >= ?'; $args[] = (float)$amin; }
if ($amax !== '')   { $where[] = 'net_claim_amt <= ?'; $args[] = (float)$amax; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderMap = [
    'accept' => '(accept_date IS NULL), accept_date DESC, claim_id DESC',
    'net'    => 'net_claim_amt DESC',
    'app'    => 'approved_amt DESC',
    'claim'  => 'claim_id DESC',
];
$orderBy = $orderMap[$sort] ?? $orderMap['accept'];

// totals for current filter
$tot = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app FROM echs_claims $wsql");
$tot->execute($args);
$T = $tot->fetch();
$totalRows = (int)$T['n'];
$totalPages = max(1, (int)ceil($totalRows / $per));
$page = min($page, $totalPages);
$offset = ($page - 1) * $per;

$listSql = "SELECT * FROM echs_claims $wsql ORDER BY $orderBy LIMIT $per OFFSET $offset";
$st = db()->prepare($listSql);
$st->execute($args);
$rows = $st->fetchAll();

$qs = http_build_query(['scheme'=>'ECHS','q'=>$q,'status'=>$status,'ptype'=>$ptype,'from'=>$from,'to'=>$to,'amin'=>$amin,'amax'=>$amax,'sort'=>$sort]);

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>ECHS Claims</h1>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">📥 Upload</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claim_edit.php?scheme=ECHS">➕ New</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_xls.php?<?= e($qs) ?>">⬇ Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/api/echs_export_csv.php?<?= e($qs) ?>">⬇ CSV</a>
    </div>
</div>

<form class="searchbar" method="get">
    <input type="hidden" name="scheme" value="ECHS">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Claim ID, Card ID, ESM ya Patient...">
    <select name="status">
        <option value="">All status</option>
        <?php foreach (echs_status_list() as $s): ?>
            <option value="<?= e($s['status']) ?>" <?= $status===$s['status']?'selected':'' ?>><?= e($s['status']) ?> (<?= $s['n'] ?>)</option>
        <?php endforeach; ?>
    </select>
    <select name="ptype">
        <option value="">Type</option>
        <option value="I" <?= $ptype==='I'?'selected':'' ?>>IPD (I)</option>
        <option value="O" <?= $ptype==='O'?'selected':'' ?>>OPD (O)</option>
    </select>
    <label class="inline">From <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="inline">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="inline">₹ <input type="number" name="amin" value="<?= e($amin) ?>" placeholder="min" style="width:90px"></label>
    <label class="inline">– <input type="number" name="amax" value="<?= e($amax) ?>" placeholder="max" style="width:90px"></label>
    <select name="sort">
        <option value="accept" <?= $sort==='accept'?'selected':'' ?>>Newest</option>
        <option value="net" <?= $sort==='net'?'selected':'' ?>>Highest Net</option>
        <option value="app" <?= $sort==='app'?'selected':'' ?>>Highest Approved</option>
        <option value="claim" <?= $sort==='claim'?'selected':'' ?>>Claim ID</option>
    </select>
    <button class="btn btn-primary">Filter</button>
    <?php if ($q||$status||$ptype||$from||$to): ?><a class="btn btn-light" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">Clear</a><?php endif; ?>
</form>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($totalRows) ?></div><div class="stat-lbl">Claims (filter)</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($T['net']) ?></div><div class="stat-lbl">Net Claim Amt</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= money($T['app']) ?></div><div class="stat-lbl">Approved Amt</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= money($T['net'] - $T['app']) ?></div><div class="stat-lbl">Difference</div></div>
</div>

<div class="card">
<?php if (!$rows): ?>
    <p class="muted">Koi claim nahi mila. <a href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">Excel upload karein →</a></p>
<?php else: ?>
    <div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr>
            <th>Claim ID</th><th>Card ID</th><th>ESM</th><th>Patient</th><th>Type</th>
            <th>Accept</th><th class="r">Net Amt</th><th class="r">Approved</th><th>Status</th><th>Processed</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>"><?= e($c['claim_id']) ?></a><?php if (!empty($c['followup'])): ?> <span title="Follow-up flagged">🚩</span><?php endif; ?></td>
                <td><?= e($c['card_id']) ?></td>
                <td><?= e($c['esm_name']) ?></td>
                <td><?= e($c['patient_name']) ?></td>
                <td><?= e($c['patient_type']) ?>/<?= e($c['admit_type']) ?></td>
                <td class="nowrap"><?= e($c['accept_date_raw'] ?: '-') ?></td>
                <td class="r"><?= number_format($c['net_claim_amt'],0) ?></td>
                <td class="r"><?= number_format($c['approved_amt'],0) ?></td>
                <td><span class="pill pill-info"><?= e($c['status']) ?></span></td>
                <td class="nowrap"><?= e($c['processed_on_raw'] ?: '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pager">
        <span class="muted small">Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($totalRows) ?> claims</span>
        <span class="pager-links">
            <?php if ($page > 1): ?><a class="btn btn-light" href="?<?= e($qs) ?>&page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a class="btn btn-light" href="?<?= e($qs) ?>&page=<?= $page+1 ?>">Next →</a><?php endif; ?>
        </span>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
