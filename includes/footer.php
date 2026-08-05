        </main>
        <footer class="footer">
            © <?= date('Y') ?> <?= e(APP_NAME) ?> · <?= e(APP_OWNER) ?> · v<?= e(APP_VERSION) ?>
        </footer>
    </div><!-- /app-main -->
</div><!-- /app -->
<div class="sb-backdrop" onclick="toggleSidebar()"></div>

<!-- Claim side-drawer: TID/Claim ID click opens here (Esc closes) -->
<div id="claimDrawer" class="claim-drawer" aria-hidden="true">
    <div class="cd-backdrop"></div>
    <aside class="cd-panel" role="dialog" aria-modal="true">
        <div class="cd-bar">
            <span class="cd-title muted small">Claim</span>
            <span style="flex:1"></span>
            <a class="cd-open" href="#" target="_blank" title="Naye tab me kholein">⤢</a>
            <button class="cd-close" title="Band karein (Esc)">✕</button>
        </div>
        <div class="cd-loading">Load ho raha hai…</div>
        <iframe class="cd-frame" title="Claim detail" src="about:blank"></iframe>
    </aside>
</div>

<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
function toggleTheme(){
    document.body.classList.toggle('dark');
    localStorage.setItem('theme', document.body.classList.contains('dark') ? 'dark' : 'light');
}
function toggleSidebar(){
    if (window.innerWidth <= 900) {
        document.body.classList.toggle('sb-open');
    } else {
        document.body.classList.toggle('sb-collapsed');
        localStorage.setItem('sidebar', document.body.classList.contains('sb-collapsed') ? 'collapsed' : 'open');
    }
}
function toggleDd(e, id){
    e = e || window.event; if (e && e.stopPropagation) e.stopPropagation();
    var el = document.getElementById(id);
    var open = el.classList.contains('show');
    document.querySelectorAll('.dd-panel.show').forEach(function(p){ p.classList.remove('show'); });
    if (!open) el.classList.add('show');
}
document.addEventListener('click', function(){ document.querySelectorAll('.dd-panel.show').forEach(function(p){ p.classList.remove('show'); }); });
if ('serviceWorker' in navigator) { navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js').catch(function(){}); }

/* ---------- Claim side-drawer ---------- */
(function(){
    var d = document.getElementById('claimDrawer');
    if (!d) return;
    var frame = d.querySelector('.cd-frame');
    var loading = d.querySelector('.cd-loading');
    var openBtn = d.querySelector('.cd-open');
    var isClaimLink = function(href){ return /\/(rghs|echs)_claim\.php\?/.test(href||''); };
    window.openClaimDrawer = function(url){
        if (!url) return;
        var full = url + (url.indexOf('?')>=0?'&':'?') + 'panel=1';
        openBtn.href = url;                       // "open in new tab" = the normal page
        loading.style.display = 'block';
        frame.style.visibility = 'hidden';
        frame.src = full;
        d.classList.add('open'); d.setAttribute('aria-hidden','false');
        document.body.classList.add('drawer-open');
    };
    window.closeClaimDrawer = function(){
        d.classList.remove('open'); d.setAttribute('aria-hidden','true');
        document.body.classList.remove('drawer-open');
        setTimeout(function(){ frame.src='about:blank'; }, 250);
    };
    frame.addEventListener('load', function(){ if (frame.src!=='about:blank'){ loading.style.display='none'; frame.style.visibility='visible'; } });
    // intercept claim links anywhere on the page
    document.addEventListener('click', function(e){
        var a = e.target.closest('a'); if (!a) return;
        if (a.target === '_blank') return;
        var href = a.getAttribute('href') || '';
        if (isClaimLink(href)) { e.preventDefault(); window.openClaimDrawer(a.href); }
    });
    d.querySelector('.cd-backdrop').addEventListener('click', window.closeClaimDrawer);
    d.querySelector('.cd-close').addEventListener('click', window.closeClaimDrawer);
    document.addEventListener('keydown', function(e){ if ((e.key==='Escape'||e.key==='Esc') && d.classList.contains('open')) window.closeClaimDrawer(); });
})();
</script>
</body>
</html>
