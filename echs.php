<?php
/**
 * ECHS is now a native upload-based claims tracker (like RGHS).
 * This file used to serve the third-party React tracker; it now simply
 * forwards any old links/bookmarks to the native ECHS dashboard.
 */
require_once __DIR__ . '/includes/auth.php';
require_login();
redirect(BASE_URL . '/dashboard.php?scheme=ECHS');
