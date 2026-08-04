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

// field order + header aliases for the client-side mapper
$FIELD_ALIASES = [];
foreach (rghs_fields() as $x) {
    $FIELD_ALIASES[] = ['f' => $x[0], 'a' => array_map(function($s){ return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)); }, $x[2])];
}
$CSRF = csrf_token();

// recent uploads
$recent = db()->query("SELECT * FROM rghs_uploads ORDER BY id DESC LIMIT 8")->fetchAll();
$total  = (int)db()->query("SELECT COUNT(*) n FROM rghs_claims")->fetch()['n'];

require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <h1>⬆ RGHS Claim Report Upload</h1>
    <div class="page-actions">
        <a class="btn" href="<?= BASE_URL ?>/rghs_claims.php?scheme=RGHS">All Claims (<?= number_format($total) ?>)</a>
    </div>
</div>

<div class="card">
    <h2>Excel upload karein (TmsBisTrack)</h2>
    <p class="muted small">RGHS portal se jo claim report (.xlsx / .xls / .csv) download hoti hai, wahi yahan daalein.
       File aapke browser me hi padhi jaayegi aur chhote-chhote hisso me upload hogi — badi file bhi bina ruke chadh jaayegi.
       Claim ID (<strong>TID</strong>) ke hisaab se data update hota hai — dubara upload karne par purana data safe rehta hai, sirf naya/badla hua update hota hai.</p>

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
        <thead><tr><th>File</th><th class="r">Rows</th><th class="r">New</th><th class="r">Updated</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $u): ?>
            <tr><td><?= e($u['filename']) ?></td><td class="r"><?= number_format($u['rows_read']) ?></td>
                <td class="r"><?= number_format($u['inserted']) ?></td><td class="r"><?= number_format($u['updated']) ?></td>
                <td class="small"><?= e(date('d-m-Y H:i', strtotime($u['uploaded_at']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
const FIELDS = <?= json_encode($FIELD_ALIASES) ?>;
const CSRF   = <?= json_encode($CSRF) ?>;
const IMPORT_URL = <?= json_encode(BASE_URL.'/api/rghs_import.php') ?>;
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

function norm(s){ return String(s==null?'':s).toUpperCase().replace(/[^A-Z0-9]/g,''); }

goEl.addEventListener('click', async () => {
    if (!picked) return;
    goEl.disabled = true; fileEl.disabled = true;
    const errbox = document.getElementById('errbox'); errbox.style.display='none';
    const progwrap = document.getElementById('progwrap'); progwrap.style.display='block';
    const bar = document.getElementById('bar'); const ptext = document.getElementById('progtext');
    ptext.textContent = 'File padhi ja rahi hai…';

    try {
        const buf = await picked.arrayBuffer();
        const wb = XLSX.read(buf, { type:'array', cellDates:false, raw:true });
        const ws = wb.Sheets[wb.SheetNames[0]];
        const rows = XLSX.utils.sheet_to_json(ws, { header:1, raw:false, defval:'' });
        if (!rows.length) throw new Error('Sheet khali hai.');

        // header -> column index
        const hdr = rows[0].map(norm);
        const colIndex = FIELDS.map(fd => {
            for (const alias of fd.a) { const i = hdr.indexOf(alias); if (i !== -1) return i; }
            return -1;
        });
        if (colIndex[0] === -1) throw new Error('TID column nahi mila — kya yeh sahi RGHS report hai?');

        const dataRows = rows.slice(1).filter(r => r && r.length);
        const total = dataRows.length;
        if (!total) throw new Error('Koi data row nahi mili.');

        let sent=0, ins=0, upd=0, chg=0, first=true;
        for (let off=0; off<total; off+=CHUNK) {
            const slice = dataRows.slice(off, off+CHUNK);
            const batch = slice.map(r => colIndex.map(ci => ci===-1 ? '' : (r[ci]==null?'':r[ci])));
            const resp = await fetch(IMPORT_URL, {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ token:CSRF, filename:picked.name, batch, first })
            });
            first = false;
            const j = await resp.json().catch(()=>({error:'bad response'}));
            if (!resp.ok || j.error) throw new Error('Server: ' + (j.message || j.error || resp.status));
            sent += slice.length; ins += j.inserted||0; upd += j.updated||0; chg += j.changed||0;
            const pct = Math.round(sent/total*100);
            bar.style.width = pct + '%';
            ptext.textContent = `${sent.toLocaleString('en-IN')} / ${total.toLocaleString('en-IN')} rows — ${pct}%  (naye ${ins.toLocaleString('en-IN')}, update ${upd.toLocaleString('en-IN')})`;
        }

        const res = document.getElementById('result');
        res.style.display='block';
        res.innerHTML = `✅ Import poora! <strong>${total.toLocaleString('en-IN')}</strong> rows — `
            + `${ins.toLocaleString('en-IN')} naye, ${upd.toLocaleString('en-IN')} update, ${chg.toLocaleString('en-IN')} status change. `
            + `<a href="${<?= json_encode(BASE_URL) ?>}/rghs_claims.php?scheme=RGHS">Claims dekhein →</a>`;
        ptext.textContent = 'Ho gaya.';
    } catch (err) {
        errbox.style.display='block';
        errbox.textContent = '❌ ' + (err.message || err);
        goEl.disabled=false; fileEl.disabled=false;
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
