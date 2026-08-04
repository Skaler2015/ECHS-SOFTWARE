<?php
/** Generates a simple app icon (PNG) at the requested size for the PWA.
 *  Uses only ellipse/rectangle calls (stable signatures across PHP 7.4–8.4). */
$s = (int)($_GET['s'] ?? 192);
if ($s < 48) $s = 48; if ($s > 1024) $s = 1024;
$c = strtolower($_GET['c'] ?? '');   // '', 'rghs' (gold ring) or 'echs' (white ring)

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');

$im = imagecreatetruecolor($s, $s);
$bg = imagecolorallocate($im, 15, 30, 61);     // navy (matches ECHS tracker)
// ring colour distinguishes the two installed apps
$fg = ($c === 'rghs')
    ? imagecolorallocate($im, 201, 162, 39)    // gold for RGHS
    : imagecolorallocate($im, 255, 255, 255);  // white for ECHS / default
imagefilledrectangle($im, 0, 0, $s, $s, $bg);

// white ring (donut) mark in the centre
$cx = (int)($s/2); $cy = (int)($s/2);
$outer = (int)($s*0.62); $inner = (int)($s*0.34);
imagefilledellipse($im, $cx, $cy, $outer, $outer, $fg);
imagefilledellipse($im, $cx, $cy, $inner, $inner, $bg);

imagepng($im);
imagedestroy($im);
