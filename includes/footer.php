        </main>
        <footer class="footer">
            © <?= date('Y') ?> <?= e(APP_NAME) ?> · <?= e(APP_OWNER) ?> · v<?= e(APP_VERSION) ?>
        </footer>
    </div><!-- /app-main -->
</div><!-- /app -->
<div class="sb-backdrop" onclick="toggleSidebar()"></div>

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
</script>
</body>
</html>
