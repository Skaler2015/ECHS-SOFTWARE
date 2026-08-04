<?php
/**
 * ECHS Data Safety — backups & restore.
 *
 * The tracker saves everything to the central store (echs_kv). The API also
 * keeps an automatic daily snapshot of every key for 30 days
 * (echs_kv_backup). This page lets an admin:
 *   - download a full backup file any time,
 *   - see the daily restore points,
 *   - restore all data back to a chosen day if something ever goes wrong.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$u = current_user();
$isAdmin = (($u['role'] ?? '') === 'admin');

$pdo = db();
// make sure tables exist even if the API hasn't run yet
$pdo->exec("CREATE TABLE IF NOT EXISTS `echs_kv` (`kkey` VARCHAR(80) NOT NULL PRIMARY KEY, `kval` LONGTEXT NULL, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS `echs_kv_backup` (`kkey` VARCHAR(80) NOT NULL, `snap_date` DATE NOT NULL, `kval` LONGTEXT NULL, `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`kkey`,`snap_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$CLAIMS_KEY = 'echs_echs_claims';   // the app stores claims under echs_ + 'echs_claims'

function claims_count($json) {
    if (!$json) return null;
    $d = json_decode($json, true);
    if (is_array($d) && isset($d['claims']) && is_array($d['claims'])) return count($d['claims']);
    return null;
}

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'download') {
        // stream a full backup of the live data as one JSON file
        $rows = $pdo->query("SELECT kkey, kval FROM echs_kv ORDER BY kkey")->fetchAll();
        $dump = ['app' => 'ECHS Claims Tracker', 'exported_at' => date('c'), 'data' => []];
        foreach ($rows as $r) $dump['data'][$r['kkey']] = $r['kval'];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="echs-backup-' . date('Y-m-d_His') . '.json"');
        echo json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($act === 'snapshot') {
        // force a snapshot of all current keys into today's backup
        $rows = $pdo->query("SELECT kkey, kval FROM echs_kv")->fetchAll();
        $bk = $pdo->prepare("INSERT INTO echs_kv_backup (kkey, snap_date, kval) VALUES (?, CURDATE(), ?)
            ON DUPLICATE KEY UPDATE kval=VALUES(kval), saved_at=NOW()");
        foreach ($rows as $r) $bk->execute([$r['kkey'], $r['kval']]);
        flash('Aaj ka backup snapshot ban gaya (' . count($rows) . ' keys).');
        redirect(BASE_URL . '/echs_restore.php');
    }

    if ($act === 'restore' && $isAdmin) {
        $date = $_POST['date'] ?? '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // safety: snapshot the CURRENT state first, so a restore is itself reversible
            $cur = $pdo->query("SELECT kkey, kval FROM echs_kv")->fetchAll();
            $snap = $pdo->prepare("INSERT INTO echs_kv_backup (kkey, snap_date, kval) VALUES (?, CURDATE(), ?)
                ON DUPLICATE KEY UPDATE kval=VALUES(kval), saved_at=NOW()");
            foreach ($cur as $r) $snap->execute([$r['kkey'], $r['kval']]);

            // restore every key that has a snapshot on that date
            $brows = $pdo->prepare("SELECT kkey, kval FROM echs_kv_backup WHERE snap_date = ?");
            $brows->execute([$date]);
            $put = $pdo->prepare("INSERT INTO echs_kv (kkey, kval) VALUES (?, ?) ON DUPLICATE KEY UPDATE kval=VALUES(kval)");
            $n = 0;
            foreach ($brows as $b) { $put->execute([$b['kkey'], $b['kval']]); $n++; }
            flash("$date ka data restore ho gaya ($n keys). ECHS app kholein — data wapas aa gaya.");
        } else {
            flash('Galat date.', 'error');
        }
        redirect(BASE_URL . '/echs_restore.php');
    }
}

// ---------- gather view data ----------
$live = $pdo->query("SELECT kkey, LENGTH(kval) len, updated_at FROM echs_kv ORDER BY kkey")->fetchAll();
$liveClaims = null; $liveUpdated = null; $liveBytes = 0;
foreach ($live as $r) {
    $liveBytes += (int)$r['len'];
    if ($r['kkey'] === $CLAIMS_KEY) { $liveUpdated = $r['updated_at']; }
}
$cj = $pdo->prepare("SELECT kval FROM echs_kv WHERE kkey = ?"); $cj->execute([$CLAIMS_KEY]);
$row = $cj->fetch(); $liveClaims = $row ? claims_count($row['kval']) : null;

// snapshot dates (grouped), with claim count of that day's claims snapshot
$snapDates = $pdo->query("SELECT snap_date, COUNT(*) keys_n, SUM(LENGTH(kval)) bytes, MAX(saved_at) saved_at
    FROM echs_kv_backup GROUP BY snap_date ORDER BY snap_date DESC")->fetchAll();
$claimSnaps = [];
foreach ($pdo->query("SELECT snap_date, kval FROM echs_kv_backup WHERE kkey = " . $pdo->quote($CLAIMS_KEY)) as $s) {
    $claimSnaps[$s['snap_date']] = claims_count($s['kval']);
}

$f = flash();
function human_bytes($b){ $b=(int)$b; if($b<1024)return $b.' B'; if($b<1048576)return round($b/1024,1).' KB'; return round($b/1048576,2).' MB'; }
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Safety · ECHS · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__.'/assets/css/style.css') ?: APP_VERSION ?>">
<style>
.wrap{max-width:920px;margin:0 auto;padding:24px 18px}
.rtop{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.rtop h1{font-size:1.3rem;margin:0}
.tbl td,.tbl th{padding:11px 12px}
.note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:12px;padding:12px 14px;font-size:.9rem;margin-bottom:16px}
body.dark .note{background:#132036;border-color:#1f3a5f;color:#93c5fd}
</style>
</head>
<body>
<script>if(localStorage.getItem('theme')==='dark')document.body.classList.add('dark');</script>
<div class="wrap">
  <div class="rtop">
    <h1>🛡️ ECHS Data Safety & Backup</h1>
    <div>
      <a class="btn" href="<?= BASE_URL ?>/echs.php">← ECHS App</a>
      <a class="btn" href="<?= BASE_URL ?>/home.php">Home</a>
    </div>
  </div>

  <?php if ($f): ?><div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif; ?>

  <div class="note">
    Aapka data central database me safe hai. System <strong>har din apne aap ek backup</strong>
    (snapshot) bhi banata hai — 30 din tak. Kabhi kuch galat ho jaaye to neeche kisi bhi din par
    <strong>Restore</strong> karke data wapas laa sakte hain. Restore se pehle aaj ka backup
    apne aap ban jaata hai, is liye restore bhi safe hai.
  </div>

  <div class="card">
    <h2>Abhi ka data (live)</h2>
    <table class="kv">
      <tr><td>Total claims</td><th><?= $liveClaims!==null ? number_format($liveClaims) : '—' ?></th></tr>
      <tr><td>Last updated</td><th><?= $liveUpdated ? e(date('d-m-Y H:i', strtotime($liveUpdated))) : '—' ?></th></tr>
      <tr><td>Data size</td><th><?= human_bytes($liveBytes) ?></th></tr>
    </table>
    <div class="form-actions" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="download">
        <button class="btn btn-primary">⬇ Download full backup (JSON)</button></form>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="snapshot">
        <button class="btn">📸 Backup abhi banao</button></form>
    </div>
    <p class="muted small" style="margin-top:8px">Download ki hui JSON file apne computer/pendrive me rakh lein — sabse pakka backup.</p>
  </div>

  <div class="card">
    <h2>Restore points (daily backups)</h2>
    <?php if (!$snapDates): ?>
      <p class="muted">Abhi koi backup nahi. App me data aate hi backup apne aap banne lagega.</p>
    <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Date</th><th class="r">Claims us din</th><th class="r">Keys</th><th class="r">Size</th><th class="r">Time</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($snapDates as $d): $cc = $claimSnaps[$d['snap_date']] ?? null; ?>
        <tr>
          <td><strong><?= e(date('d-m-Y', strtotime($d['snap_date']))) ?></strong></td>
          <td class="r"><?= $cc!==null ? number_format($cc) : '—' ?></td>
          <td class="r"><?= (int)$d['keys_n'] ?></td>
          <td class="r"><?= human_bytes($d['bytes']) ?></td>
          <td class="r small"><?= e(date('H:i', strtotime($d['saved_at']))) ?></td>
          <td class="r">
            <?php if ($isAdmin): ?>
              <form method="post" onsubmit="return confirm('<?= e(date('d-m-Y', strtotime($d['snap_date']))) ?> ka data restore karein? Abhi ka data pehle backup ho jaayega.');" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="act" value="restore"><input type="hidden" name="date" value="<?= e($d['snap_date']) ?>">
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
</div>
</body>
</html>
