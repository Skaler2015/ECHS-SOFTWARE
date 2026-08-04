<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
rghs_ensure_table();
$pdo = db();

$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') ? $_GET['ym'] : date('Y-m');
$label = date('F Y', strtotime($ym.'-01'));
$ymn = (int)str_replace('-','',$ym);

$s = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(claim_amt),0) claim, COALESCE(SUM(cu_amt),0) cu,
    SUM(status LIKE '%APPROVED%' OR status LIKE '%Approved%') appn,
    SUM(status LIKE '%REJECT%' OR status LIKE '%Reject%') rejn
    FROM rghs_claims WHERE (YEAR(submit_date)*100+MONTH(submit_date))=?");
$s->execute([$ymn]); $sum = $s->fetch();
$recv = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) s, COUNT(*) n FROM rghs_claims WHERE (YEAR(payment_date)*100+MONTH(payment_date))=?");
$recv->execute([$ymn]); $r = $recv->fetch();
$byType = $pdo->prepare("SELECT COALESCE(NULLIF(claim_type,''),'—') t, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE (YEAR(submit_date)*100+MONTH(submit_date))=? GROUP BY t ORDER BY n DESC");
$byType->execute([$ymn]); $byType = $byType->fetchAll();
$byDoc = $pdo->prepare("SELECT doctor_name, COUNT(*) n, COALESCE(SUM(claim_amt),0) amt FROM rghs_claims WHERE (YEAR(submit_date)*100+MONTH(submit_date))=? AND doctor_name IS NOT NULL AND doctor_name<>'' GROUP BY doctor_name ORDER BY n DESC LIMIT 10");
$byDoc->execute([$ymn]); $byDoc = $byDoc->fetchAll();
$hosp = APP_OWNER;
?>
<!DOCTYPE html><html lang="hi"><head><meta charset="utf-8"><title>RGHS Report <?= e($label) ?></title>
<style>
*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:0;padding:24px;font-size:13px}
.head{text-align:center;border-bottom:2px solid #0F1E3D;padding-bottom:10px;margin-bottom:16px}
.head h1{margin:0;font-size:19px;color:#0F1E3D}.head .sub{color:#555;font-size:12px}
h2{font-size:13px;background:#EEF2F8;padding:6px 8px;margin:16px 0 8px;border-left:3px solid #C9A227}
table{width:100%;border-collapse:collapse;margin-bottom:8px}
td,th{border:1px solid #ccc;padding:6px 8px;text-align:left}th{background:#fafafa}
.r{text-align:right}
.kpis{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.kpi{flex:1;min-width:130px;border:1px solid #ddd;border-radius:8px;padding:10px}
.kpi .v{font-size:1.2rem;font-weight:800;color:#0F1E3D}.kpi .l{color:#666;font-size:11px}
.noprint button,.noprint a{padding:8px 16px;background:#1B2F5E;color:#fff;border:none;border-radius:6px;text-decoration:none;cursor:pointer;margin-left:6px}
@media print{.noprint{display:none}body{padding:6px}}
</style></head><body>
<div class="noprint" style="text-align:right;margin-bottom:10px">
    <form style="display:inline" method="get"><input type="hidden" name="scheme" value="RGHS"><input type="month" name="ym" value="<?= e($ym) ?>" onchange="this.form.submit()" style="padding:6px"></form>
    <button onclick="window.print()">🖨️ Print / PDF</button>
    <a href="<?= BASE_URL ?>/rghs_reports.php?scheme=RGHS">← Reports</a>
</div>
<div class="head"><h1><?= e($hosp) ?></h1><div class="sub">RGHS Monthly Report — <?= e($label) ?> · Generated <?= date('d-m-Y H:i') ?></div></div>

<div class="kpis">
    <div class="kpi"><div class="v"><?= number_format($sum['n']) ?></div><div class="l">Claims submitted</div></div>
    <div class="kpi"><div class="v"><?= number_format($sum['appn']) ?></div><div class="l">Approved</div></div>
    <div class="kpi"><div class="v"><?= number_format($sum['rejn']) ?></div><div class="l">Rejected</div></div>
    <div class="kpi"><div class="v"><?= money($sum['claim']) ?></div><div class="l">Claimed</div></div>
    <div class="kpi"><div class="v"><?= money($r['s']) ?></div><div class="l">Received (<?= number_format($r['n']) ?>)</div></div>
</div>

<h2>Type-wise</h2>
<table><thead><tr><th>Type</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead><tbody>
<?php foreach ($byType as $t): ?><tr><td><?= e($t['t']) ?></td><td class="r"><?= number_format($t['n']) ?></td><td class="r"><?= money($t['amt']) ?></td></tr><?php endforeach; ?>
<?php if(!$byType): ?><tr><td colspan="3">Is mahine koi claim nahi.</td></tr><?php endif; ?>
</tbody></table>

<?php if ($byDoc): ?>
<h2>Top doctors</h2>
<table><thead><tr><th>Doctor</th><th class="r">Claims</th><th class="r">Claimed</th></tr></thead><tbody>
<?php foreach ($byDoc as $d): ?><tr><td><?= e($d['doctor_name']) ?></td><td class="r"><?= number_format($d['n']) ?></td><td class="r"><?= money($d['amt']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<div style="margin-top:24px;color:#777;font-size:11px;text-align:center;border-top:1px solid #ccc;padding-top:8px">Computer-generated — <?= e(APP_NAME) ?></div>
</body></html>
