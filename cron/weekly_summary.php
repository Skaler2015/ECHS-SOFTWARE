<?php
/**
 * Weekly ECHS summary email.
 * Run via Hostinger cron, e.g. weekly:
 *   php /home/USER/domains/subhashkaler.com/public_html/noble/cron/weekly_summary.php KEY
 * or via URL:  https://noble.subhashkaler.com/cron/weekly_summary.php?key=KEY
 *
 * The KEY is shown on the ECHS → Manage page.
 */
require_once __DIR__ . '/../includes/echs.php';
echs_ensure_table();

$key = $_GET['key'] ?? ($argv[1] ?? '');
if (!hash_equals(echs_cron_key(), (string)$key)) {
    http_response_code(403);
    die("Invalid key.\n");
}

$to = trim(setting('echs_email', ''));
$body = echs_weekly_summary_text();
$subject = 'ECHS Weekly Summary - ' . date('d-m-Y');

$sent = false;
if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $headers = 'From: ' . setting('org_name', 'Noble') . ' <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
    $sent = @mail($to, $subject, $body, $headers);
}

echs_log('weekly_summary', $sent ? "emailed $to" : 'generated (no email)');

header('Content-Type: text/plain; charset=utf-8');
echo $body . "\n-----------------------------------\n";
echo $to === '' ? "Email recipient set nahi hai (Manage page par set karein).\n"
               : ($sent ? "Email bheja gaya: $to\n" : "Email bhejne me dikkat (hosting mail check karein): $to\n");
