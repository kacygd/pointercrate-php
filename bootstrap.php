<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    $configPath = __DIR__ . '/config.example.php';
}

$GLOBALS['app_config'] = require $configPath;
$timezone = (string) ($GLOBALS['app_config']['app']['timezone'] ?? 'UTC');
date_default_timezone_set($timezone);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/layout.php';
