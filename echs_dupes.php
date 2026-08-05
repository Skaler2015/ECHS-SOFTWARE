<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'reports';
$page_title = 'Duplicate Claims';
$pdo = db();

// mark one claim of a duplicate group as a duplicate, or clear the flag
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = trim($_POST['claim_id'] ?? '');
    $act = $_POST['act'] ?? '';
    if ($cid !== '') {
        if ($act === 'flag_dupe') {
            $pdo->prepare("UPDATE echs_claims SET dupe_flag=1, updated_at=NOW() WHERE claim_id=?")->execute([$cid]);
            echs_log('dupe_flag', $cid);
            flash('Claim ko duplicate mark kar diya.');
        } elseif ($act === 'clear_dupe') {
            $pdo->prepare("UPDATE echs_claims SET dupe_flag=0, updated_at=NOW() WHERE claim_id=?")->execute([$cid]);
            echs_log('dupe_clear', $cid);
            flash('Duplicate mark hata diya.');
        }
    }
    redirect(BASE_URL.'/echs_dupes.php?scheme=ECHS');
}

// duplicate groups: same card_id + claim_amt + accept_date appearing >1
$groups = $pdo->query("SELECT card_id, claim_amt, accept_date, COUNT(*) n
    FROM echs_claims
    WHERE card_id IS NOT NULL AND card_id<>'' AND claim_amt>0 AND accept_date IS NOT NULL
    GROUP BY card_id, claim_amt, accept_date HAVING COUNT(*)>1
    ORDER BY n DESC, accept_date DESC LIMIT 200")->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🧭 Duplicate claims — resolve</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_reports.php?scheme=ECHS">← Reports</a>
    </div>
</div>

<div class="card">
    <p class="muted small">Ek hi <strong>card + amount + accept date</strong> par ek se zyada claim = sambhavit duplicate.
    Har group me sahi claim rakhein aur galat/repeat wale ko "Duplicate mark" karein. Mark karne se data delete nahi hota — sirf flag lagta hai.</p>
</div>

<?php if (!$groups): ?>
<div class="card"><p class="muted">Koi sambhavit duplicate nahi mila 🎉</p></div>
<?php else: foreach ($groups as $g):
    $rows = $pdo->prepare("SELECT claim_id, patient_name, status, category, claim_amt, approved_amt, doctor_name, dupe_flag
        FROM echs_claims WHERE card_id=? AND claim_amt=? AND accept_date=? ORDER BY claim_id");
    $rows->execute([$g['card_id'], $g['claim_amt'], $g['accept_date']]);
    $rows = $rows->fetchAll();
?>
<div class="card">
    <h2>Card <?= e($g['card_id']) ?> · <?= money($g['claim_amt']) ?> · <?= fdate($g['accept_date']) ?>
        <span class="pill pill-process" style="margin-left:8px"><?= (int)$g['n'] ?> claims</span></h2>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>Claim ID</th><th>Patient</th><th>Status</th><th class="r">Claimed</th><th class="r">Approved</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr <?= $r['dupe_flag']?'style="opacity:.55"':'' ?>>
                <td><a class="link" href="<?= BASE_URL ?>/echs_claim.php?scheme=ECHS&id=<?= urlencode($r['claim_id']) ?>"><?= e($r['claim_id']) ?></a>
                    <?= $r['dupe_flag']?'<span class="pill pill-rejected">duplicate</span>':'' ?></td>
                <td><?= e($r['patient_name']) ?><br><span class="muted small"><?= e($r['doctor_name']) ?></span></td>
                <td class="small"><?= e($r['status']) ?></td>
                <td class="r"><?= inr($r['claim_amt'],0) ?></td>
                <td class="r"><?= inr($r['approved_amt'],0) ?></td>
                <td class="r">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?><input type="hidden" name="claim_id" value="<?= e($r['claim_id']) ?>">
                        <?php if ($r['dupe_flag']): ?>
                            <input type="hidden" name="act" value="clear_dupe"><button class="btn btn-sm">↩ Hatao</button>
                        <?php else: ?>
                            <input type="hidden" name="act" value="flag_dupe"><button class="btn btn-sm btn-danger">Duplicate mark</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endforeach; endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
