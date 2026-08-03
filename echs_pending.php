<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'pending';
$page_title = 'Pending & Aging';

$pend = echs_pending_condition();

// outstanding totals
$o = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net FROM echs_claims WHERE $pend")->fetch();

// aging buckets (on accept_date) for pending claims
$aging = db()->query("SELECT
    SUM(CASE WHEN accept_date IS NULL THEN 1 ELSE 0 END) nadate,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) BETWEEN 0 AND 30 THEN 1 ELSE 0 END) b30,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) b60,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) b90,
    SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) > 90 THEN 1 ELSE 0 END) b90p,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(),accept_date) > 90 THEN net_claim_amt ELSE 0 END),0) amt90p
    FROM echs_claims WHERE $pend")->fetch();

// status-wise outstanding
$byStatus = db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net
    FROM echs_claims WHERE $pend GROUP BY status ORDER BY net DESC")->fetchAll();

// deduction on settled claims
$ded = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app,
    COALESCE(SUM(net_claim_amt-approved_amt),0) ded
    FROM echs_claims WHERE status LIKE '%Settled%'")->fetch();

// top deductions
$topDed = db()->query("SELECT claim_id, patient_name, card_id, net_claim_amt, approved_amt, (net_claim_amt-approved_amt) d
    FROM echs_claims WHERE status LIKE '%Settled%' AND (net_claim_amt-approved_amt) > 0
    ORDER BY d DESC LIMIT 20")->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>💰 Pending, Aging &amp; Deduction</h1>
    <div class="page-actions"><a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All Claims</a></div>
</div>

<h3 class="section-title">Outstanding (jo abhi settle nahi hua)</h3>
<div class="stat-grid">
    <div class="stat-card warn"><div class="stat-num"><?= number_format($o['n']) ?></div><div class="stat-lbl">Pending Claims</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($o['net']) ?></div><div class="stat-lbl">Outstanding Amount</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($aging['b90p']) ?></div><div class="stat-lbl">90+ din purane</div></div>
    <div class="stat-card"><div class="stat-num"><?= money($aging['amt90p']) ?></div><div class="stat-lbl">90+ din ki rakam</div></div>
</div>

<div class="card">
    <h2>Aging (Accept date ke hisaab se)</h2>
    <table class="tbl">
        <thead><tr><th>Umar</th><th class="r">Claims</th></tr></thead>
        <tbody>
            <tr><td>0–30 din</td><td class="r"><?= number_format($aging['b30']) ?></td></tr>
            <tr><td>31–60 din</td><td class="r"><?= number_format($aging['b60']) ?></td></tr>
            <tr><td>61–90 din</td><td class="r"><?= number_format($aging['b90']) ?></td></tr>
            <tr><td><strong>90+ din</strong></td><td class="r"><strong><?= number_format($aging['b90p']) ?></strong></td></tr>
            <tr><td class="muted">Bina date</td><td class="r muted"><?= number_format($aging['nadate']) ?></td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <div class="card-head"><h2>Status-wise Outstanding</h2></div>
    <table class="tbl">
        <thead><tr><th>Status</th><th class="r">Claims</th><th class="r">Amount</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byStatus as $s): ?>
            <tr>
                <td><span class="pill pill-process"><?= e($s['status']) ?></span></td>
                <td class="r"><?= number_format($s['n']) ?></td>
                <td class="r"><?= money($s['net']) ?></td>
                <td class="r"><a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&status=<?= urlencode($s['status']) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$byStatus): ?><tr><td colspan="4" class="muted">Koi pending claim nahi.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<h3 class="section-title">Deduction (Settled claims par katौti)</h3>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($ded['n']) ?></div><div class="stat-lbl">Settled Claims</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($ded['net']) ?></div><div class="stat-lbl">Claimed</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= money($ded['app']) ?></div><div class="stat-lbl">Approved (mila)</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= money($ded['ded']) ?></div><div class="stat-lbl">Total Deduction</div></div>
</div>

<div class="card">
    <h2>Sabse zyada deduction wale claims</h2>
    <table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Card</th><th class="r">Net</th><th class="r">Approved</th><th class="r">Deduction</th></tr></thead>
        <tbody>
        <?php foreach ($topDed as $d): ?>
            <tr>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($d['claim_id']) ?>"><?= e($d['claim_id']) ?></a></td>
                <td><?= e($d['patient_name']) ?></td>
                <td><?= e($d['card_id']) ?></td>
                <td class="r"><?= number_format($d['net_claim_amt'],0) ?></td>
                <td class="r"><?= number_format($d['approved_amt'],0) ?></td>
                <td class="r"><strong><?= number_format($d['d'],0) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$topDed): ?><tr><td colspan="6" class="muted">Koi deduction data nahi.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
