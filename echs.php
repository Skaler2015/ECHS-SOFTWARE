<?php
/**
 * ECHS Claims Tracker — Noble Care Hospital.
 *
 * This is the standalone tracker app (single-page HTML) that the hospital
 * shared. It stores all its data centrally through the /api/data/ backend
 * (see api/data/index.php), so every device sees the same data.
 *
 * We serve it through PHP so that only logged-in staff can open it — the
 * same login that protects the rest of the software.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();

$file = __DIR__ . '/echs/app.html';
if (!is_file($file)) {
    http_response_code(500);
    exit('ECHS app file (echs/app.html) nahi mili.');
}

header('Content-Type: text/html; charset=utf-8');
// no-cache so app updates reach users immediately after a deploy
header('Cache-Control: no-store, must-revalidate');

$htmlOut = file_get_contents($file);

// ---- Make ECHS installable as its own mobile app (PWA) ----
// app.html doesn't go through our PHP shell, so inject the manifest + icons here.
$pwaHead = '<link rel="manifest" href="' . BASE_URL . '/manifest-echs.webmanifest">'
    . '<meta name="theme-color" content="#0F1E3D">'
    . '<meta name="mobile-web-app-capable" content="yes">'
    . '<meta name="apple-mobile-web-app-capable" content="yes">'
    . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">'
    . '<meta name="apple-mobile-web-app-title" content="ECHS">'
    . '<link rel="apple-touch-icon" href="' . BASE_URL . '/assets/icon.php?s=192&c=echs">';
$hp = stripos($htmlOut, '</head>');
if ($hp !== false) {
    $htmlOut = substr($htmlOut, 0, $hp) . $pwaHead . substr($htmlOut, $hp);
}
// register the service worker (same one the rest of the app uses)
$pwaSw = '<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("'
    . BASE_URL . '/sw.js").catch(function(){});}</script>';

// Inject a small floating scheme-switcher so users can jump to RGHS / Home
// without leaving the tracker. Self-contained (the tracker doesn't load our CSS).
$rghsUrl = BASE_URL . '/dashboard.php?scheme=RGHS';
$homeUrl = BASE_URL . '/home.php';
$switch = '<div id="nobleSwitch" style="position:fixed;bottom:16px;right:16px;z-index:2147483000;display:flex;align-items:center;gap:4px;background:#0F1E3D;border:1px solid #C9A227;border-radius:999px;padding:4px;box-shadow:0 8px 24px rgba(0,0,0,.35);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:13px">'
    . '<span style="padding:6px 13px;border-radius:999px;background:#C9A227;color:#0F1E3D;font-weight:800">ECHS</span>'
    . '<a href="' . htmlspecialchars($rghsUrl) . '" style="padding:6px 13px;border-radius:999px;color:#fff;text-decoration:none;font-weight:700">RGHS &rarr;</a>'
    . '<a href="' . htmlspecialchars($homeUrl) . '" title="Home" style="padding:6px 11px;border-radius:999px;color:#C9A227;text-decoration:none;font-weight:700">&#8962;</a>'
    . '</div>';

// insert before the LAST </body> (earlier ones may live inside JS strings)
$pos = strripos($htmlOut, '</body>');
if ($pos !== false) {
    $htmlOut = substr($htmlOut, 0, $pos) . $switch . $pwaSw . substr($htmlOut, $pos);
} else {
    $htmlOut .= $switch . $pwaSw;
}
echo $htmlOut;
