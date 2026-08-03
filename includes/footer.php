        </main>
<footer class="footer">
    <div>© <?= date('Y') ?> <?= e(APP_NAME) ?> · <?= e(APP_OWNER) ?> · v<?= e(APP_VERSION) ?></div>
</footer>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
function toggleTheme(){
    document.body.classList.toggle('dark');
    localStorage.setItem('theme', document.body.classList.contains('dark') ? 'dark' : 'light');
}
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js').catch(function(){});
}
</script>
</body>
</html>
