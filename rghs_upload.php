<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/rghs.php';
require_once __DIR__ . '/includes/icons.php';
rghs_ensure_table();

$scheme = 'RGHS';
$meta   = scheme_meta('RGHS');
$active = 'upload';
$page_title = 'RGHS Upload';

// field order + header aliases for the client-side mapper (both report types)
$CLAIM_FIELDS = rghs_alias_list('claims');
$PAY_FIELDS   = rghs_alias_list('payments');
$CSRF = csrf_token();

// recent uploads + counts
$recent   = db()->query("SELECT * FROM rghs_uploads ORDER BY id DESC LIMIT 8")->fetchAll();
$total    = (int)db()->query("SELECT COUNT(*) n FROM rghs_claims")->fetch()['n'];
$totalPay = (int)db()->query("SELECT COUNT(*) n FROM rghs_payments")->fetch()['n'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>⬆ RGHS Upload</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">All Claims (<?= number_format($total) ?>)</a>
    </div>
</div>

<div class="card">
    <h2>Excel upload karein — Claim Report ya Payment Tracker</h2>
    <p class="muted small">Dono me se koi bhi file daal dijiye — software <strong>khud pehchaan</strong> lega ki ye
       <strong>Claim Report (TmsBisTrack)</strong> hai ya <strong>Payment Tracker</strong>, aur sahi jagah update kar dega.
       Dono <strong>TID</strong> se aapas me jud jaate hain — kaunsa claim, kitna paisa mila, kab, kaunse UTR se.
       File browser me hi padhi jaati hai aur hisso me chadh ti hai; dubara upload par purana data safe rehta hai (sirf naya/badla update hota hai).</p>
    <div class="stat-grid" style="margin-top:10px">
        <div class="stat-card"><div class="stat-num"><?= number_format($total) ?></div><div class="stat-lbl">Claims stored</div></div>
        <div class="stat-card ok"><div class="stat-num"><?= number_format($totalPay) ?></div><div class="stat-lbl">Payment records</div></div>
    </div>

    <div class="upload-drop" id="drop">
        <input type="file" id="file" accept=".xlsx,.xls,.csv" style="margin-top:8px">
        <label for="file">📄 File chunein</label>
    </div>

    <div class="form-actions" style="margin-top:14px">
        <button class="btn btn-primary" id="go" disabled>▶ Import shuru karein</button>
        <span id="fileinfo" class="muted small"></span>
    </div>

    <div id="progwrap" style="display:none;margin-top:18px">
        <div style="background:var(--line);border-radius:999px;height:12px;overflow:hidden">
            <div id="bar" style="background:var(--brand);height:100%;width:0;transition:width .2s"></div>
        </div>
        <div id="progtext" class="muted small" style="margin-top:8px">Taiyari…</div>
    </div>

    <div id="result" style="display:none;margin-top:16px" class="flash flash-success"></div>
    <div id="errbox" style="display:none;margin-top:16px" class="flash flash-error"></div>
</div>

<div class="card">
    <h2>Recent uploads</h2>
    <?php if (!$recent): ?><p class="muted">Abhi tak koi upload nahi.</p><?php else: ?>
    <table class="tbl">
        <thead><tr><th>File</th><th>Type</th><th class="r">Rows</th><th class="r">New</th><th class="r">Updated</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $u): ?>
            <tr><td><?= e($u['filename']) ?></td>
                <td><span class="pill pill-<?= ($u['kind']??'')==='payments'?'settled':'info' ?>"><?= e(($u['kind']??'')==='payments'?'Payment':'Claim') ?></span></td>
                <td class="r"><?= number_format($u['rows_read']) ?></td>
                <td class="r"><?= number_format($u['inserted']) ?></td><td class="r"><?= number_format($u['updated']) ?></td>
                <td class="small"><?= e(date('d-m-Y H:i', strtotime($u['uploaded_at']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const CLAIM_FIELDS = <?= json_encode($CLAIM_FIELDS) ?>;
const PAY_FIELDS   = <?= json_encode($PAY_FIELDS) ?>;
const CSRF   = <?= json_encode($CSRF) ?>;
const IMPORT_URL = <?= json_encode(BASE_URL.'/api/rghs_import.php') ?>;
const CLAIMS_URL = <?= json_encode(BASE_URL.'/rghs_claims.php?scheme=RGHS') ?>;
const CHUNK = 1500;

let picked = null;
const fileEl = document.getElementById('file');
const goEl   = document.getElementById('go');
const info   = document.getElementById('fileinfo');

fileEl.addEventListener('change', () => {
    picked = fileEl.files[0] || null;
    goEl.disabled = !picked;
    info.textContent = picked ? (picked.name + ' — ' + (picked.size/1048576).toFixed(1) + ' MB') : '';
    document.getElementById('result').style.display = 'none';
    document.getElementById('errbox').style.display = 'none';
});

// strip zero-width chars + trim, uppercase alnum-only for header matching
function norm(s){ return String(s==null?'':s).replace(/[\u200B-\u200D\uFEFF]/g,'').toUpperCase().replace(/[^A-Z0-9]/g,''); }
function clean(v){ return v==null ? '' : String(v).replace(/[\u200B-\u200D\uFEFF]/g,'').trim(); }

// map a normalised header row to {field: colIndex} for a given field set
function mapCols(hdrNorm, flds){
    const m = {};
    for (const fd of flds){ let idx=-1; for (const a of fd.a){ const i=hdrNorm.indexOf(a); if(i!==-1){ idx=i; break; } } m[fd.f]=idx; }
    return m;
}

// find the header row + report type by scanning the first few rows
function detect(rows){
    let best = null;
    const scan = Math.min(8, rows.length);
    for (let hr=0; hr<scan; hr++){
        const hdr = (rows[hr]||[]).map(norm);
        const claim = mapCols(hdr, CLAIM_FIELDS);
        const pay   = mapCols(hdr, PAY_FIELDS);
        const claimScore = Object.values(claim).filter(i=>i!==-1).length;
        const payScore   = Object.values(pay).filter(i=>i!==-1).length;
        const claimOk = claim.tid!==-1 && claim.sub_year!==-1;
        const payOk   = pay.tid!==-1 && pay.paid_amount!==-1;
        if (payOk && (!best || payScore>best.score))   best = {type:'payments', row:hr, cols:pay,   flds:PAY_FIELDS,   score:payScore};
        if (claimOk && (!best || claimScore>best.score)) best = {type:'claims',   row:hr, cols:claim, flds:CLAIM_FIELDS, score:claimScore};
    }
    return best;
}

goEl.addEventListener('click', async () => {
    if (!picked) return;
    goEl.disabled = true; fileEl.disabled = true;
    const errbox = document.getElementById('errbox'); errbox.style.display='none';
    const progwrap = document.getElementById('progwrap'); progwrap.style.display='block';
    const bar = document.getElementById('bar'); const ptext = document.getElementById('progtext');
    ptext.textContent = 'File padhi ja rahi hai…';

    try {
        const buf = await picked.arrayBuffer();
        const wb = XLSX.read(buf, { type:'array', cellDates:false });
        const ws = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(ws, { header:1, raw:false, defval:'' });
        if (!rows.length) throw new Error('Sheet khali hai.');

        const det = detect(rows);
        if (!det) throw new Error('Ye RGHS ki Claim Report ya Payment Tracker nahi lag rahi (columns match nahi hue).');

        const label = det.type==='payments' ? 'Payment Tracker' : 'Claim Report';
        ptext.textContent = `Detected: ${label} — upload ho raha hai…`;

        const order = det.flds.map(fd => fd.f);           // canonical field order
        const colOf = order.map(f => det.cols[f]);        // column index per field
        const dataRows = rows.slice(det.row+1).filter(r => r && r.length);
        const total = dataRows.length;
        if (!total) throw new Error('Koi data row nahi mili.');

        let sent=0, ins=0, upd=0, first=true;
        for (let off=0; off<total; off+=CHUNK) {
            const slice = dataRows.slice(off, off+CHUNK);
            const batch = slice.map(r => colOf.map(ci => ci===-1 ? '' : clean(r[ci])));
            const resp = await fetch(IMPORT_URL, {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ token:CSRF, kind:det.type, filename:picked.name, batch, first })
            });
            first = false;
            const j = await resp.json().catch(()=>({error:'bad response'}));
            if (!resp.ok || j.error) throw new Error('Server: ' + (j.message || j.error || resp.status));
            sent += slice.length; ins += j.inserted||0; upd += j.updated||0;
            const pct = Math.round(sent/total*100);
            bar.style.width = pct + '%';
            ptext.textContent = `${label}: ${sent.toLocaleString('en-IN')} / ${total.toLocaleString('en-IN')} — ${pct}%  (naye ${ins.toLocaleString('en-IN')}, update ${upd.toLocaleString('en-IN')})`;
        }

        const res = document.getElementById('result');
        res.style.display='block';
        res.innerHTML = `✅ ${label} import poora! <strong>${total.toLocaleString('en-IN')}</strong> rows — `
            + `${ins.toLocaleString('en-IN')} naye, ${upd.toLocaleString('en-IN')} update. `
            + `<a href="${CLAIMS_URL}">Claims dekhein →</a>`;
        ptext.textContent = 'Ho gaya.';
        setTimeout(()=>location.reload(), 2500);
    } catch (err) {
        errbox.style.display='block';
        errbox.textContent = '❌ ' + (err.message || err);
        goEl.disabled=false; fileEl.disabled=false;
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
