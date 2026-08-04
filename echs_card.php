<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'cardhistory';
$page_title = 'Card History';

$pdo = db();
$card = trim($_GET['card'] ?? $_GET['q'] ?? '');

$claims = []; $agg = null; $esm = ''; $contact = null; $hist = [];
if ($card !== '') {
    $st = $pdo->prepare("SELECT * FROM echs_claims WHERE card_id = ? ORDER BY COALESCE(accept_date,'1900-01-01') DESC, claim_id DESC");
    $st->execute([$card]);
    $claims = $st->fetchAll();
    if ($claims) {
        $esm = '';
        foreach ($claims as $c) { if (!empty($c['esm_name'])) { $esm = $c['esm_name']; break; } }
        $a = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app, COALESCE(SUM(amt_credited),0) cr,
            SUM(status LIKE '%Settled%') settled, SUM(".echs_pending_condition().") pend FROM echs_claims WHERE card_id=?");
        $a->execute([$card]); $agg = $a->fetch();
        // contact
        $cs = $pdo->prepare("SELECT * FROM echs_contacts WHERE card_id=?"); $cs->execute([$card]); $contact = $cs->fetch() ?: null;
        // status history for these claims
        $ids = array_column($claims, 'claim_id');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $hs = $pdo->prepare("SELECT * FROM echs_claim_history WHERE claim_id IN ($in) ORDER BY changed_at DESC LIMIT 60");
            $hs->execute($ids); $hist = $hs->fetchAll();
        }
    }
}

// save contact
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    if (($_POST['act'] ?? '')==='contact') {
        $cid = trim($_POST['card_id'] ?? '');
        if ($cid !== '') {
            $pdo->prepare("INSERT INTO echs_contacts (card_id,name,phone,address) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), address=VALUES(address), updated_at=NOW()")
                ->execute([$cid, trim($_POST['name']??'')?:null, trim($_POST['phone']??'')?:null, trim($_POST['address']??'')?:null]);
            flash('Contact save ho gaya.');
        }
        redirect(BASE_URL.'/echs_card.php?scheme=ECHS&card='.urlencode($cid));
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>◉ Card History</h1>
</div>

<div class="card form">
    <form method="get" class="inline-search">
        <input type="hidden" name="scheme" value="ECHS">
        <input type="text" name="card" value="<?= e($card) ?>" placeholder="Card ID daalein (e.g. 12345678901234)" autofocus>
        <button class="btn btn-primary">Search</button>
    </form>
</div>

<?php if ($card === ''): ?>
    <p class="muted">Kisi bhi card ka poora history dekhne ke liye upar Card ID daalein.</p>
<?php elseif (!$claims): ?>
    <div class="card"><p class="muted">Card <strong><?= e($card) ?></strong> ke liye koi claim nahi mila.</p></div>
<?php else: ?>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= number_format($agg['n']) ?></div><div class="stat-lbl"><?= e($esm ?: 'ESM') ?></div></div>
        <div class="stat-card"><div class="stat-num"><?= inr($agg['net'],0) ?></div><div class="stat-lbl">Net claimed</div></div>
        <div class="stat-card ok"><div class="stat-num"><?= inr($agg['app'],0) ?></div><div class="stat-lbl">Approved</div></div>
        <div class="stat-card info"><div class="stat-num"><?= inr($agg['cr'],0) ?></div><div class="stat-lbl">Credited</div></div>
        <div class="stat-card warn"><div class="stat-num"><?= number_format($agg['pend']) ?></div><div class="stat-lbl">Pending</div></div>
    </div>

    <div class="detail-grid">
        <div class="card">
            <h2>Contact details</h2>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="act" value="contact"><input type="hidden" name="card_id" value="<?= e($card) ?>">
                <div class="fld"><label>Naam</label><input name="name" value="<?= e($contact['name'] ?? $esm) ?>"></div>
                <div class="fld"><label>Phone</label><input name="phone" value="<?= e($contact['phone'] ?? '') ?>"></div>
                <div class="fld"><label>Address</label><input name="address" value="<?= e($contact['address'] ?? '') ?>"></div>
                <div class="form-actions"><button class="btn">Save contact</button></div>
            </form>
        </div>
        <div class="card">
            <h2>Status changes</h2>
            <?php if (!$hist): ?><p class="muted">Koi status change record nahi.</p><?php else: ?>
            <ul class="timeline">
                <?php foreach ($hist as $h): ?>
                    <li><span class="tl-date"><?= e(date('d-m-y', strtotime($h['changed_at']))) ?></span>
                        <span class="small"><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($h['claim_id']) ?>"><?= e($h['claim_id']) ?></a>:
                        <?= e($h['from_status'] ?: 'नया') ?> → <strong><?= e($h['to_status']) ?></strong></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>All claims (<?= number_format($agg['n']) ?>)</h2>
        <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Claim</th><th>Patient</th><th>Type</th><th>Accept</th><th class="r">Net</th><th class="r">Approved</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($claims as $c): ?>
                <tr>
                    <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($c['claim_id']) ?>"><?= e($c['claim_id']) ?></a></td>
                    <td><?= e($c['patient_name'] ?: '-') ?></td>
                    <td><?= e($c['patient_type'] ?: '-') ?></td>
                    <td><?= $c['accept_date'] ? e(date('d-m-y', strtotime($c['accept_date']))) : '-' ?></td>
                    <td class="r"><?= inr($c['net_claim_amt'],0) ?></td>
                    <td class="r"><?= inr($c['approved_amt'],0) ?></td>
                    <td><span class="pill pill-<?= echs_category($c['status']) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
