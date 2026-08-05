<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/echs.php';
require_once __DIR__ . '/includes/icons.php';
echs_ensure_table();

$scheme = 'ECHS';
$meta   = scheme_meta('ECHS');
$active = 'upload';
$page_title = 'ECHS Upload';

$FIELDS = echs_alias_list();
$CSRF   = csrf_token();

$recent = db()->query("SELECT * FROM echs_uploads ORDER BY id DESC LIMIT 12")->fetchAll();
$total  = (int)db()->query("SELECT COUNT(*) n FROM echs_claims")->fetch()['n'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>⬆ ECHS Upload</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/echs_claims.php?scheme=ECHS">All Claims (<?= number_format($total) ?>)</a>
    </div>
</div>

<div class="card">
    <h2>CLAIMLIST files upload karein</h2>
    <p class="muted small">ECHS portal se jo <strong>CLAIMLIST_*.xls</strong> files download hoti hain (Claim Settled, Need More Information,
       Rejected, Cancel, etc.) — <strong>saari ek saath</strong> select kar dijiye. Har file ka <strong>status khud pehchaan</strong> liya jaata hai
       (file ke header se), aur claim <strong>Claim ID</strong> se update hota hai. Purana data safe rehta hai — sirf naya/badla update hota hai,
       aur status change hone par history bhi ban ti hai.</p>
    <div class="stat-grid" style="margin-top:10px">
        <div class="stat-card"><div class="stat-num"><?= number_format($total) ?></div><div class="stat-lbl">Claims stored</div></div>
    </div>

    <div class="upload-drop" id="drop">
        <input type="file" id="file" accept=".xls,.xlsx,.csv" multiple style="margin-top:8px">
        <label for="file">📄 Files chunein (ek ya kai)</label>
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
        <thead><tr><th>File</th><th>Status</th><th class="r">Rows</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $u): ?>
            <tr><td class="small"><?= e($u['filename']) ?></td>
                <td><span class="pill pill-info"><?= e($u['status_label'] ?: '—') ?></span></td>
                <td class="r"><?= number_format($u['rows']) ?></td>
                <td class="small"><?= e(date('d-m-Y H:i', strtotime($u['uploaded_at']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const FIELDS = <?= json_encode($FIELDS) ?>;
const CSRF   = <?= json_encode($CSRF) ?>;
const IMPORT_URL = <?= json_encode(BASE_URL.'/api/echs_import.php') ?>;
const CLAIMS_URL = <?= json_encode(BASE_URL.'/echs_claims.php?scheme=ECHS') ?>;
const CHUNK = 1500;

let picked = [];
const fileEl = document.getElementById('file');
const goEl   = document.getElementById('go');
const info   = document.getElementById('fileinfo');

fileEl.addEventListener('change', () => {
    picked = Array.from(fileEl.files || []);
    goEl.disabled = !picked.length;
    info.textContent = picked.length ? (picked.length + ' file(s) chuni') : '';
    document.getElementById('result').style.display = 'none';
    document.getElementById('errbox').style.display = 'none';
});

function norm(s){ return String(s==null?'':s).replace(/[\u200B-\u200D\uFEFF]/g,'').toUpperCase().replace(/[^A-Z0-9]/g,''); }
function clean(v){ return v==null ? '' : String(v).replace(/[\u200B-\u200D\uFEFF]/g,'').trim(); }

function mapCols(hdrNorm){
    const m = {};
    for (const fd of FIELDS){ let idx=-1; for (const a of fd.a){ const i=hdrNorm.indexOf(a); if(i!==-1){ idx=i; break; } } m[fd.f]=idx; }
    return m;
}

// find header row (row containing "Claim ID" + others)
function findHeader(rows){
    const scan = Math.min(14, rows.length);
    let best = null;
    for (let hr=0; hr<scan; hr++){
        const hdr = (rows[hr]||[]).map(norm);
        const cols = mapCols(hdr);
        const score = Object.values(cols).filter(i=>i!==-1).length;
        if (cols.claim_id!==-1 && cols.card_id!==-1 && (!best || score>best.score)) best = {row:hr, cols, score};
    }
    return best;
}

// detect the status label from the "<Status> as on <date>" line, else from filename
function detectStatus(rows, fname){
    const scan = Math.min(14, rows.length);
    for (let r=0; r<scan; r++){
        for (const cell of (rows[r]||[])){
            const s = clean(cell);
            const m = s.match(/^(.+?)\s+as on\s+/i);
            if (m) return m[1].trim();
        }
    }
    // fallback: CLAIMLIST_Claim_Settled.xls -> "Claim Settled"
    let base = String(fname||'').replace(/\.[^.]+$/,'').replace(/^CLAIMLIST[_\s-]*/i,'');
    base = base.replace(/[_]+/g,' ').trim();
    return base || 'Unknown';
}

async function importFile(file, onprog){
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type:'array', cellDates:false });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(ws, { header:1, raw:false, defval:'' });
    if (!rows.length) throw new Error(file.name+': sheet khali hai.');

    const det = findHeader(rows);
    if (!det) throw new Error(file.name+': Claim ID column nahi mila — ye ECHS CLAIMLIST file nahi lagti.');
    const status = detectStatus(rows, file.name);

    const order = FIELDS.map(fd => fd.f);
    const colOf = order.map(f => det.cols[f]);
    const dataRows = rows.slice(det.row+1).filter(r => r && r.length && clean(r[det.cols.claim_id]) !== '');
    const total = dataRows.length;
    let sent=0, ins=0, upd=0, first=true;
    for (let off=0; off<total; off+=CHUNK){
        const slice = dataRows.slice(off, off+CHUNK);
        const batch = slice.map(r => colOf.map(ci => ci===-1 ? '' : clean(r[ci])));
        const resp = await fetch(IMPORT_URL, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ token:CSRF, filename:file.name, status, batch, first })
        });
        first = false;
        const j = await resp.json().catch(()=>({error:'bad response'}));
        if (!resp.ok || j.error) throw new Error(file.name+' — Server: ' + (j.message || j.error || resp.status));
        sent += slice.length; ins += j.inserted||0; upd += j.updated||0;
        onprog(sent, total, status);
    }
    return { status, total, ins, upd };
}

goEl.addEventListener('click', async () => {
    if (!picked.length) return;
    goEl.disabled = true; fileEl.disabled = true;
    const errbox = document.getElementById('errbox'); errbox.style.display='none';
    const progwrap = document.getElementById('progwrap'); progwrap.style.display='block';
    const bar = document.getElementById('bar'); const ptext = document.getElementById('progtext');

    let doneFiles=0, grandIns=0, grandUpd=0, grandRows=0; const summary=[];
    try {
        for (const file of picked){
            ptext.textContent = `(${doneFiles+1}/${picked.length}) ${file.name} — padhi ja rahi hai…`;
            const res = await importFile(file, (sent,total,status)=>{
                const filePct = total? sent/total : 1;
                const pct = Math.round((doneFiles + filePct)/picked.length*100);
                bar.style.width = pct + '%';
                ptext.textContent = `(${doneFiles+1}/${picked.length}) ${status}: ${sent.toLocaleString('en-IN')}/${total.toLocaleString('en-IN')} — kul ${pct}%`;
            });
            doneFiles++; grandIns+=res.ins; grandUpd+=res.upd; grandRows+=res.total;
            summary.push(`${res.status}: ${res.total.toLocaleString('en-IN')} rows`);
        }
        bar.style.width='100%';
        const res = document.getElementById('result');
        res.style.display='block';
        res.innerHTML = `✅ ${picked.length} file import poore! <strong>${grandRows.toLocaleString('en-IN')}</strong> rows — `
            + `${grandIns.toLocaleString('en-IN')} naye, ${grandUpd.toLocaleString('en-IN')} update.<br>`
            + `<span class="muted small">${summary.join(' · ')}</span><br>`
            + `<a href="${CLAIMS_URL}">Claims dekhein →</a>`;
        ptext.textContent = 'Ho gaya.';
        setTimeout(()=>location.reload(), 3000);
    } catch (err) {
        errbox.style.display='block';
        errbox.textContent = '❌ ' + (err.message || err);
        goEl.disabled=false; fileEl.disabled=false;
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
