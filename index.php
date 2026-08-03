<?php
require_once __DIR__ . '/includes/auth.php';
redirect(is_logged_in() ? BASE_URL . '/home.php' : BASE_URL . '/login.php');
