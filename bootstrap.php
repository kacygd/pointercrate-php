<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Missing config.php. Please create config.php before running the app.');
}

$GLOBALS['app_config_path'] = $configPath;
$GLOBALS['app_config'] = require $configPath;
$timezone = (string) ($GLOBALS['app_config']['app']['timezone'] ?? 'UTC');
date_default_timezone_set($timezone);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionConfig = $GLOBALS['app_config'];
    $sessionBaseUrl = trim((string) ($sessionConfig['app']['base_url'] ?? ''), '/');
    $sessionNamespace = trim((string) ($sessionConfig['app']['session_namespace'] ?? ''));
    $sessionInstallPath = str_replace('\\', '/', (string) (realpath(__DIR__) ?: __DIR__));
    $sessionSeed = $sessionNamespace !== ''
        ? 'namespace:' . $sessionNamespace
        : implode('|', [
            'base:' . strtolower($sessionBaseUrl),
            'public:' . strtolower(trim((string) ($sessionConfig['app']['public_url'] ?? ''))),
            'path:' . strtolower($sessionInstallPath),
        ]);

    session_name('DLSESSID' . substr(hash('sha256', $sessionSeed), 0, 16));

    $cookieParams = session_get_cookie_params();
    $cookieParams['path'] = $sessionBaseUrl === '' ? '/' : '/' . $sessionBaseUrl;
    $cookieParams['httponly'] = true;
    $cookieParams['samesite'] = 'Lax';
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        $cookieParams['secure'] = true;
    }
    session_set_cookie_params($cookieParams);

    session_start();
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema_update.php';

ensure_schema_updated_on_bootstrap();

require_once __DIR__ . '/includes/layout.php';
