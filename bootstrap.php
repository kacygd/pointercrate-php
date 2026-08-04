<?php
declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    header('Location: setup.php');
    exit;
}

$GLOBALS['app_config_path'] = $configPath;
$GLOBALS['app_config'] = require $configPath;
$timezone = (string) ($GLOBALS['app_config']['app']['timezone'] ?? 'UTC');
date_default_timezone_set($timezone);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionConfig = $GLOBALS['app_config'];
    $securityConfig = is_array($sessionConfig['security'] ?? null) ? $sessionConfig['security'] : [];
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

    // Sessions persist until explicit logout instead of expiring when the browser closes.
    $sessionLifetimeSeconds = 60 * 60 * 24 * 365;
    ini_set('session.gc_maxlifetime', (string) $sessionLifetimeSeconds);

    $cookieParams = session_get_cookie_params();
    $cookieParams['lifetime'] = $sessionLifetimeSeconds;
    $cookieParams['path'] = $sessionBaseUrl === '' ? '/' : '/' . $sessionBaseUrl;
    $cookieParams['httponly'] = (bool) ($securityConfig['session_cookie_httponly'] ?? true);
    $sameSite = (string) ($securityConfig['session_cookie_samesite'] ?? 'Lax');
    $cookieParams['samesite'] = in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax';
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || (bool) ($securityConfig['session_cookie_secure'] ?? false)) {
        $cookieParams['secure'] = true;
    }
    session_set_cookie_params($cookieParams);

    session_start();
}

require_once __DIR__ . '/includes/functions.php';

if (isset($_GET['lang']) && is_string($_GET['lang']) && is_supported_language($_GET['lang'])) {
    $_SESSION['language'] = $_GET['lang'];
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/schema_update.php';

ensure_schema_updated_on_bootstrap();

require_once __DIR__ . '/includes/layout.php';
