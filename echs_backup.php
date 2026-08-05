<?php
/**
 * ECHS Data Safety — download backup, daily snapshots, and restore.
 * ECHS data lives in a single table (echs_claims). Re-import only upserts
 * (never deletes), and a compressed daily snapshot is kept 20 days.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
echs_ensure_table();

$u = current_user();
$isAdmin = (($u['role'] ?? '') === 'admin');
$pdo = db();

function upsert_rows($pdo, $table, $rows) {
    if (!$rows) return 0;
    $cols = array_keys($rows[0]);
    $colSql = '`' . implode('`,`', $cols) . '`';
    $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $upd = [];
    foreach ($cols as $c) { if ($c === 'claim_id') continue; $upd[] = "`$c`=VALUES(`$c`)"; }
    $sql = "INSERT INTO `$table` ($colSql) VALUES $ph ON DUPLICATE KEY UPDATE " . implode(',', $upd);
    $stmt = $pdo->prepare($sql); $n = 0;
    foreach ($rows as $r) { $stmt->execute(array_values($r)); $n++; }
    return $n;
}
function snap_decode($payload) {
    $j = @gzdecode($payload);
    if ($j === false) $j = $payload;   // stored uncompressed fallback
    return json_decode($j, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'download') {
        $data = echs_export_all();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="echs-backup-' . date('Y-m-d_His') . '.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($act === 'snapshot') {
        // force-refresh today's snapshot with current data
        $pdo->prepare("DELETE FROM echs_backups WHERE snap_date = CURDATE()")->execute();
        echs_daily_snapshot();
        echs_log('backup_snapshot','manual'); flash('Aaj ka backup snapshot ban gaya.');
        redirect(BASE_URL . '/echs_backup.php?scheme=ECHS');
    }
    if ($act === 'restore' && $isAdmin) {
        $date = $_POST['date'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // capture current state first so the restore is reversible
            $pdo->prepare("DELETE FROM echs_backups WHERE snap_date = CURDATE()")->execute();
            echs_daily_snapshot();
            $row = $pdo->prepare("SELECT payload FROM echs_backups WHERE snap_date = ?");
            $row->execute([$date]); $r = $row->fetch();
            if ($r) {
                @set_time_limit(0);
                $data = snap_decode($r['payload']);
                $pdo->beginTransaction();
                $nc = upsert_rows($pdo, 'echs_claims', $data['claims'] ?? []);
                $pdo->commit();
                echs_log('restore',"$date (claims $nc)"); flash("$date se data restore ho gaya (claims $nc).");
            } else { flash('Us date ka snapshot nahi mila.', 'error'); }
        } else { flash('Galat date.', 'error'); }
        redirect(BASE_URL . '/echs_backup.php?scheme=ECHS');
    }
    if ($act === 'restore_upload' && $isAdmin) {
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            // capture current state first so the restore is reversible
            $pdo->prepare("DELETE FROM echs_backups WHERE snap_date = CURDATE()")->execute();
            echs_daily_snapshot();
            $raw  = file_get_contents($_FILES['file']['tmp_name']);
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['claims']) && is_array($data['claims'])) {
                @set_time_limit(0);
                $pdo->beginTransaction();
                $nc = upsert_rows($pdo, 'echs_claims', $data['claims']);
                $pdo->commit();
                echs_log('restore_upload',"uploaded (claims $nc)"); flash("Uploaded file se data restore ho gaya (claims $nc).");
            } else { flash('File galat hai — JSON me "claims" nahi mila.', 'error'); }
        } else { flash('Koi file upload nahi hui.', 'error'); }
        redirect(BASE_URL . '/echs_backup.php?scheme=ECHS');
    }
}

// ensure today's snapshot exists
$cc = (int)$pdo->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'];
if ($cc > 0) @echs_daily_snapshot();

$claimsN = $cc;
$snaps   = $pdo->query("SELECT snap_date, claims_n, bytes, saved_at FROM echs_backups ORDER BY snap_date DESC")->fetchAll();

$f = flash();
function hb($b){ $b=(int)$b; if($b<1024)return $b.' B'; if($b<1048576)return round($b/1024,1).' KB'; return round($b/1048576,2).' MB'; }
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ECHS Data Safety · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__.'/assets/css/style.css') ?: APP_VERSION ?>">
<style>.wrap{max-width:900px;margin:0 auto;padding:24px 18px}.rtop{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}.rtop h1{font-size:1.3rem;margin:0}.note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:12px;padding:12px 14px;font-size:.9rem;margin-bottom:16px}body.dark .note{background:#132036;border-color:#1f3a5f;color:#93c5fd}</style>
</head>
<body>
<script>if(localStorage.getItem('theme')==='dark')document.body.classList.add('dark');</script>
<div class="wrap">
  <div class="rtop">
    <h1>🛡️ ECHS Data Safety & Backup</h1>
    <div><a class="btn" href="<?= BASE_URL ?>/dashboard.php?scheme=ECHS">← ECHS</a> <a class="btn" href="<?= BASE_URL ?>/home.php">Home</a></div>
  </div>
  <?php if ($f): ?><div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif; ?>

  <div class="note">
    ECHS data central database me hai aur <strong>re-import kabhi delete nahi karta</strong> (sirf add/update).
    Upar se system <strong>har din ek backup snapshot</strong> bhi banata hai (20 din tak). Kabhi kuch galat ho
    to kisi bhi din par <strong>Restore</strong> kar sakte hain. Restore se pehle aaj ka backup apne aap ban jaata hai.
  </div>

  <div class="card">
    <h2>Abhi ka data</h2>
    <table class="kv">
      <tr><td>Claims</td><th><?= number_format($claimsN) ?></th></tr>
    </table>
    <div class="form-actions" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="download"><button class="btn btn-primary">⬇ Download full backup (JSON)</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="snapshot"><button class="btn">📸 Backup abhi banao</button></form>
    </div>
    <p class="muted small" style="margin-top:8px">Download ki hui file apne computer/pendrive me rakh lein — sabse pakka backup.</p>
  </div>

  <div class="card">
    <h2>Restore points (daily)</h2>
    <?php if (!$snaps): ?><p class="muted">Abhi koi snapshot nahi.</p><?php else: ?>
    <table class="tbl">
      <thead><tr><th>Date</th><th class="r">Claims</th><th class="r">Size</th><th class="r">Time</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($snaps as $s): ?>
        <tr>
          <td><strong><?= e(date('d-m-Y', strtotime($s['snap_date']))) ?></strong></td>
          <td class="r"><?= number_format($s['claims_n']) ?></td>
          <td class="r"><?= hb($s['bytes']) ?></td>
          <td class="r small"><?= e(date('H:i', strtotime($s['saved_at']))) ?></td>
          <td class="r">
            <?php if ($isAdmin): ?>
              <form method="post" onsubmit="return confirm('<?= e(date('d-m-Y', strtotime($s['snap_date']))) ?> ka data restore karein?');">
                <?= csrf_field() ?><input type="hidden" name="act" value="restore"><input type="hidden" name="date" value="<?= e($s['snap_date']) ?>">
                <button class="btn">↺ Restore</button>
              </form>
            <?php else: ?><span class="muted small">admin only</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($isAdmin): ?>
  <div class="card">
    <h2>Restore from file (JSON)</h2>
    <p class="muted small">Pehle download ki hui <strong>echs-backup-*.json</strong> file yahan upload karein. Ye sirf add/update karega (delete nahi).</p>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Uploaded file se claims restore/update karein?');">
      <?= csrf_field() ?><input type="hidden" name="act" value="restore_upload">
      <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="file" name="file" accept="application/json,.json" required>
        <button class="btn btn-primary">↺ Restore from file</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
