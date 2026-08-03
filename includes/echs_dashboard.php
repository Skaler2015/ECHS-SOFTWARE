<?php
/**
 * ECHS Claims dashboard (included from dashboard.php when scheme=ECHS).
 * Assumes auth already required; $meta set.
 */
require_once __DIR__ . '/echs.php';
echs_ensure_table();

$scheme = 'ECHS';
$active = 'dashboard';
$page_title = 'ECHS Dashboard';

$tot = ['n'=>0,'net'=>0,'app'=>0,'ipd'=>0,'opd'=>0];
try {
    $r = db()->query("SELECT COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app,
                      SUM(patient_type='I') ipd, SUM(patient_type='O') opd FROM echs_claims")->fetch();
    if ($r) $tot = $r;
} catch (Exception $e) {}

$byStatus = [];
try {
    foreach (db()->query("SELECT status, COUNT(*) n, COALESCE(SUM(net_claim_amt),0) net, COALESCE(SUM(approved_amt),0) app
                          FROM echs_claims GROUP BY status ORDER BY n DESC") as $r) {
        $byStatus[] = $r;
    }
} catch (Exception $e) {}

$hasData = (int)$tot['n'] > 0;

require __DIR__ . '/header.php';
?>
<div class="page-head">
    <h1>🎖️ ECHS Dashboard</h1>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">📥 Upload Excel</a>
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">View Claims</a>
    </div>
</div>

<?php if (!$hasData): ?>
<div class="card" style="text-align:center;padding:40px">
    <div style="font-size:3rem">📥</div>
    <h2>Abhi tak koi claim data nahi</h2>
    <p class="muted">ECHS / UTIITSL portal se CLAIMLIST Excel (ya poori ZIP) download karके yahan upload karein — software apne aap saara data dikhane lagega.</p>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/echs_upload.php?scheme=ECHS">Pehli Excel upload karein →</a>
</div>
<?php else: ?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($tot['n']) ?></div><div class="stat-lbl">Total Claims</div></div>
    <div class="stat-card money"><div class="stat-num"><?= money($tot['net']) ?></div><div class="stat-lbl">Net Claim Amt</div></div>
    <div class="stat-card ok"><div class="stat-num"><?= money($tot['app']) ?></div><div class="stat-lbl">Approved Amt</div></div>
    <div class="stat-card warn"><div class="stat-num"><?= money($tot['net'] - $tot['app']) ?></div><div class="stat-lbl">Difference</div></div>
    <div class="stat-card info"><div class="stat-num"><?= number_format($tot['ipd']) ?></div><div class="stat-lbl">IPD (I)</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($tot['opd']) ?></div><div class="stat-lbl">OPD (O)</div></div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Status-wise Breakdown</h2>
        <a href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All claims →</a>
    </div>
    <table class="tbl">
        <thead><tr><th>Status</th><th class="r">Claims</th><th class="r">Net Amt</th><th class="r">Approved</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byStatus as $s): ?>
            <tr>
                <td><span class="pill pill-info"><?= e($s['status']) ?></span></td>
                <td class="r"><?= number_format($s['n']) ?></td>
                <td class="r"><?= money($s['net']) ?></td>
                <td class="r"><?= money($s['app']) ?></td>
                <td class="r"><a class="link" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS&status=<?= urlencode($s['status']) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
