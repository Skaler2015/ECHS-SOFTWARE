<?php
/** Generates a simple app icon (PNG) at the requested size for the PWA.
 *  Uses only ellipse/rectangle calls (stable signatures across PHP 7.4–8.4). */
$s = (int)($_GET['s'] ?? 192);
if ($s < 48) $s = 48; if ($s > 1024) $s = 1024;

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');

$im = imagecreatetruecolor($s, $s);
$bg = imagecolorallocate($im, 13, 110, 253);   // brand blue
$fg = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, $s, $s, $bg);

// white ring (donut) mark in the centre
$cx = (int)($s/2); $cy = (int)($s/2);
$outer = (int)($s*0.62); $inner = (int)($s*0.34);
imagefilledellipse($im, $cx, $cy, $outer, $outer, $fg);
imagefilledellipse($im, $cx, $cy, $inner, $inner, $bg);

imagepng($im);
imagedestroy($im);
