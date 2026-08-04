<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'reports';
$page_title = 'Duplicate Claims';
$pdo = db();

// mark one TID of a duplicate group as reviewed/kept, or flag another as duplicate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tid = trim($_POST['tid'] ?? '');
    $act = $_POST['act'] ?? '';
    if ($tid !== '') {
        if ($act === 'flag_dupe') {
            $pdo->prepare("UPDATE rghs_claims SET dupe_flag=1, updated_at=NOW() WHERE tid=?")->execute([$tid]);
            rghs_log('dupe_flag', $tid);
            flash('Claim ko duplicate mark kar diya.');
        } elseif ($act === 'clear_dupe') {
            $pdo->prepare("UPDATE rghs_claims SET dupe_flag=0, updated_at=NOW() WHERE tid=?")->execute([$tid]);
            rghs_log('dupe_clear', $tid);
            flash('Duplicate mark hata diya.');
        }
    }
    redirect(BASE_URL.'/rghs_dupes.php?scheme=RGHS');
}

// duplicate groups: same card_no + claim_amt + submit_date appearing >1
$groups = $pdo->query("SELECT card_no, claim_amt, submit_date, COUNT(*) n
    FROM rghs_claims
    WHERE card_no IS NOT NULL AND card_no<>'' AND claim_amt>0 AND submit_date IS NOT NULL
    GROUP BY card_no, claim_amt, submit_date HAVING COUNT(*)>1
    ORDER BY n DESC, submit_date DESC LIMIT 200")->fetchAll();

function fdate($d){ return $d ? date('d-m-Y', strtotime($d)) : '-'; }
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>🧭 Duplicate claims — resolve</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_reports.php?scheme=RGHS">← Reports</a>
    </div>
</div>

<div class="card">
    <p class="muted small">Ek hi <strong>card + amount + submit date</strong> par ek se zyada claim = sambhavit duplicate.
    Har group me sahi claim rakhein aur galat/repeat wale ko "Duplicate mark" karein. Mark karne se data delete nahi hota — sirf flag lagta hai.</p>
</div>

<?php if (!$groups): ?>
<div class="card"><p class="muted">Koi sambhavit duplicate nahi mila 🎉</p></div>
<?php else: foreach ($groups as $g):
    $rows = $pdo->prepare("SELECT tid, patient_name, status, claim_amt, cu_amt, paid_amount, doctor_name, dupe_flag
        FROM rghs_claims WHERE card_no=? AND claim_amt=? AND submit_date=? ORDER BY tid");
    $rows->execute([$g['card_no'], $g['claim_amt'], $g['submit_date']]);
    $rows = $rows->fetchAll();
?>
<div class="card">
    <h2>Card <?= e($g['card_no']) ?> · <?= money($g['claim_amt']) ?> · <?= fdate($g['submit_date']) ?>
        <span class="pill pill-process" style="margin-left:8px"><?= (int)$g['n'] ?> claims</span></h2>
    <div class="tbl-scroll"><table class="tbl">
        <thead><tr><th>TID</th><th>Patient</th><th>Status</th><th class="r">Claimed</th><th class="r">Approved</th><th class="r">Paid</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr <?= $r['dupe_flag']?'style="opacity:.55"':'' ?>>
                <td><a class="link" href="<?= BASE_URL ?>/rghs_claim.php?scheme=RGHS&tid=<?= urlencode($r['tid']) ?>"><?= e($r['tid']) ?></a>
                    <?= $r['dupe_flag']?'<span class="pill pill-rejected">duplicate</span>':'' ?></td>
                <td><?= e($r['patient_name']) ?><br><span class="muted small"><?= e($r['doctor_name']) ?></span></td>
                <td class="small"><?= e($r['status']) ?></td>
                <td class="r"><?= inr($r['claim_amt'],0) ?></td>
                <td class="r"><?= inr($r['cu_amt'],0) ?></td>
                <td class="r"><?= inr($r['paid_amount'],0) ?></td>
                <td class="r">
                    <form method="post" style="display:inline">
                        <?= csrf_field() ?><input type="hidden" name="tid" value="<?= e($r['tid']) ?>">
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
