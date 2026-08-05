<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'patients';
$page_title = 'ECHS Patient / Card';
$pdo = db();

$q = trim($_GET['q'] ?? $_GET['card'] ?? '');
$claims = []; $agg = null; $name = ''; $card = ''; $esm = '';
$multi = [];   // distinct beneficiaries when more than one matches

if ($q !== '') {
    $st = $pdo->prepare("SELECT * FROM echs_claims
        WHERE card_id = ? OR esm_name LIKE ? OR patient_name LIKE ?
        ORDER BY COALESCE(accept_date,'1900-01-01') DESC, claim_id DESC LIMIT 500");
    $st->execute([$q, "%$q%", "%$q%"]);
    $claims = $st->fetchAll();

    // group by beneficiary (card_id) to see if the search hit more than one
    $byCard = [];
    foreach ($claims as $c) {
        $key = $c['card_id'] ?: ('esm:'.$c['esm_name']);
        if (!isset($byCard[$key])) $byCard[$key] = ['card'=>$c['card_id'], 'esm'=>$c['esm_name'], 'name'=>$c['patient_name'], 'n'=>0];
        $byCard[$key]['n']++;
        if (empty($byCard[$key]['esm']) && !empty($c['esm_name'])) $byCard[$key]['esm'] = $c['esm_name'];
    }

    if (count($byCard) > 1) {
        // ambiguous: show a picker unless the query exactly matches one card_id
        $exact = $pdo->prepare("SELECT * FROM echs_claims WHERE card_id = ? ORDER BY COALESCE(accept_date,'1900-01-01') DESC, claim_id DESC");
        $exact->execute([$q]);
        $exactRows = $exact->fetchAll();
        if ($exactRows) {
            $claims = $exactRows;   // treat as the picked beneficiary
        } else {
            $multi = array_values($byCard);
            $claims = [];           // suppress detail; show the list instead
        }
    }
}

if ($claims) {
    foreach ($claims as $c) {
        if ($name === '' && !empty($c['patient_name'])) $name = $c['patient_name'];
        if ($card === '' && !empty($c['card_id']))      $card = $c['card_id'];
        if ($esm  === '' && !empty($c['esm_name']))     $esm  = $c['esm_name'];
    }
    $settledAmt = 0.0;
    foreach ($claims as $c) if (echs_category($c['status']) === 'settled') $settledAmt += (float)$c['approved_amt'];
    $agg = [
        'n'       => count($claims),
        'claim'   => array_sum(array_map(function($c){ return (float)$c['claim_amt']; }, $claims)),
        'appr'    => array_sum(array_map(function($c){ return (float)$c['approved_amt']; }, $claims)),
        'settled' => $settledAmt,
    ];
}

function ecat_pill($cat){
    switch ($cat) {
        case 'settled':   return 'settled';
        case 'rejected':  return 'rejected';
        case 'cancelled': return 'rejected';
        case 'query':     return 'info';
        case 'inprocess': return 'process';
        default:          return 'warn';
    }
}
function pdate($d){ return $d ? date('d-m-y', strtotime($d)) : '-'; }

require __DIR__ . '/includes/header.php';
?>
<div class="page-head"><h1>⊕ Patient / Card</h1></div>

<div class="card form">
    <form method="get" class="inline-search">
        <input type="hidden" name="scheme" value="ECHS">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Card ID, ESM naam, ya Patient naam…" autofocus>
        <button class="btn btn-primary">Search</button>
    </form>
    <p class="muted small">Card / ESM se us beneficiary ke saare claims ek jagah.</p>
</div>

<?php if ($q === ''): ?>
    <p class="muted">Upar Card ID / ESM / Patient naam daalein.</p>

<?php elseif ($multi): ?>
    <div class="card">
        <h2>"<?= e($q) ?>" ke liye <?= count($multi) ?> beneficiary mile</h2>
        <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>ESM / Patient</th><th>Card ID</th><th class="r">Claims</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($multi as $m): ?>
                <tr>
                    <td><strong><?= e($m['esm'] ?: ($m['name'] ?: '—')) ?></strong><?php if ($m['name'] && $m['name']!==$m['esm']): ?><div class="muted small"><?= e($m['name']) ?></div><?php endif; ?></td>
                    <td><?= e($m['card'] ?: '—') ?></td>
                    <td class="r"><?= number_format($m['n']) ?></td>
                    <td class="r"><?php if ($m['card']): ?><a class="link" href="<?= BASE_URL ?>/echs_patient.php?scheme=ECHS&q=<?= urlencode($m['card']) ?>">Open →</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php elseif (!$claims): ?>
    <div class="card"><p class="muted">"<strong><?= e($q) ?></strong>" ke liye kuch nahi mila.</p></div>

<?php else: ?>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= number_format($agg['n']) ?></div><div class="stat-lbl"><?= e($esm ?: ($name ?: 'Beneficiary')) ?></div></div>
        <div class="stat-card"><div class="stat-num"><?= inr($agg['claim'],0) ?></div><div class="stat-lbl">Total claimed</div></div>
        <div class="stat-card ok"><div class="stat-num"><?= inr($agg['settled'],0) ?></div><div class="stat-lbl">Settled (approved)</div></div>
        <div class="stat-card info"><div class="stat-num"><?= inr($agg['appr'],0) ?></div><div class="stat-lbl">Approved total</div></div>
    </div>
    <?php if ($card || $esm): ?><p class="muted small">ESM: <strong><?= e($esm ?: '—') ?></strong> · Card ID: <strong><?= e($card ?: '—') ?></strong></p><?php endif; ?>

    <div class="card">
        <h2>Claims (<?= number_format($agg['n']) ?>)</h2>
        <div class="tbl-scroll">
        <table class="tbl">
            <thead><tr><th>Claim ID</th><th>Type</th><th>Status</th><th>Accept</th><th class="r">Claimed</th><th class="r">Approved</th></tr></thead>
            <tbody>
            <?php foreach ($claims as $c): $openUrl = BASE_URL.'/echs_claim.php?scheme=ECHS&id='.urlencode($c['claim_id']); ?>
                <tr>
                    <td><a class="link" href="<?= e($openUrl) ?>"><?= e($c['claim_id']) ?></a></td>
                    <td class="small"><?= e(echs_ptype($c['patient_type'])) ?><?= strtoupper((string)$c['admit_type'])==='E'?' <span class="pill pill-warn">E</span>':'' ?></td>
                    <td><span class="pill pill-<?= ecat_pill(echs_category($c['status'])) ?>"><?= e($c['status'] ?: '-') ?></span></td>
                    <td class="small"><?= pdate($c['accept_date']) ?></td>
                    <td class="r"><?= inr($c['claim_amt'],0) ?></td>
                    <td class="r"><?= $c['approved_amt']>0 ? inr($c['approved_amt'],0) : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
