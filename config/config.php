<?php
/**
 * Noble Health Software - Global Configuration
 * RGHS + ECHS Claims Management System
 *
 * Deployed at: noble.subhashkaler.com
 */

// ---- Application info ----
define('APP_NAME',    'Noble Health Software');
define('APP_OWNER',   'Subhash Kaler');
define('APP_VERSION', '1.0.0');

// ---- Base URL ----
// Auto-detects the URL so it works on localhost AND on your subdomain.
$scheme_http = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
// If a page lives in a sub-folder (rghs/echs) we still want the app root.
$scriptDir   = preg_replace('#/(rghs|echs|api|install)$#', '', $scriptDir);
define('BASE_URL', $scheme_http . '://' . $host . ($scriptDir ?: ''));

// ---- Timezone ----
date_default_timezone_set('Asia/Kolkata');

// ---- Schemes handled by this software ----
$SCHEMES = [
    'RGHS' => [
        'code'  => 'RGHS',
        'name'  => 'Rajasthan Government Health Scheme',
        'short' => 'RGHS',
        'color' => '#0d6efd',
        'icon'  => '🏥',
        'card_label' => 'RGHS Card No.',
    ],
    'ECHS' => [
        'code'  => 'ECHS',
        'name'  => 'Ex-Servicemen Contributory Health Scheme',
        'short' => 'ECHS',
        'color' => '#198754',
        'icon'  => '🎖️',
        'card_label' => 'ECHS Card No.',
    ],
];

// ---- Error reporting: keep OFF in production ----
// While setting up you can turn this on. On live hosting keep display_errors off.
error_reporting(E_ALL);
ini_set('display_errors', '0'); // change to '1' only while debugging
