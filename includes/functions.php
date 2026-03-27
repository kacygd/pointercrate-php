<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['app_config'] ?? [];
    if ($key === '') {
        return $value;
    }

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function app_name(): string
{
    $name = trim((string) config('app.name', 'DemonList PHP'));
    return $name !== '' ? $name : 'DemonList PHP';
}

function app_tagline(): string
{
    $tagline = trim((string) config('app.tagline', 'A competitive Geometry Dash ranking platform for precise list management and verified records.'));
    return $tagline !== '' ? $tagline : 'A competitive Geometry Dash ranking platform for precise list management and verified records.';
}

function e(string|null|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_public_path(string $path): string
{
    $trimmed = ltrim(trim($path), '/');
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^index\.php(?:\?.*)?$/i', $trimmed) === 1) {
        return '';
    }

    if (preg_match('/^demon\.php\?id=([0-9]+)$/i', $trimmed, $match) === 1) {
        return 'id=' . (int) $match[1];
    }

    if (preg_match('/^(players|guidelines|submit|admin|account|profile|login|logout|register)\.php$/i', $trimmed, $match) === 1) {
        return strtolower($match[1]);
    }

    if (preg_match('/^(players|guidelines|submit|admin|account|profile|login|logout|register)\.php\?(.+)$/i', $trimmed, $match) === 1) {
        return strtolower($match[1]) . '?' . $match[2];
    }

    return $trimmed;
}

function base_url(string $path = ''): string
{
    $base = trim((string) config('app.base_url', ''), '/');
    $prefix = $base === '' ? '' : '/' . $base;

    if ($path === '' || $path === '/') {
        return $prefix === '' ? '/' : $prefix . '/';
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    $normalized = normalize_public_path($path);
    if ($normalized === '') {
        return $prefix === '' ? '/' : $prefix . '/';
    }

    return ($prefix === '' ? '' : $prefix) . '/' . ltrim($normalized, '/');
}

function app_public_url(): ?string
{
    $url = trim((string) config('app.public_url', ''));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return rtrim($url, '/');
}

function request_origin(): ?string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return null;
    }

    $https = false;
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $https = true;
    } elseif ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        $https = true;
    } elseif (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        $https = true;
    }

    return ($https ? 'https' : 'http') . '://' . $host;
}

function absolute_url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    if ($path === '' || $path === '/') {
        $relative = base_url('');
    } else {
        $relative = str_starts_with($path, '/') ? $path : base_url($path);
    }

    $origin = app_public_url() ?? request_origin();
    if ($origin === null) {
        return $relative;
    }

    return rtrim($origin, '/') . '/' . ltrim($relative, '/');
}

function current_url_absolute(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if (preg_match('#^https?://#i', $requestUri) === 1) {
        return $requestUri;
    }

    $origin = request_origin();
    if ($origin !== null) {
        return rtrim($origin, '/') . '/' . ltrim($requestUri, '/');
    }

    return absolute_url(current_path());
}

function discord_webhook_url(): ?string
{
    $url = trim((string) config('discord.webhook_url', ''));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return $url;
}

function discord_server_widget_url(): ?string
{
    $direct = trim((string) config('discord.server_widget_url', ''));
    if ($direct !== '' && filter_var($direct, FILTER_VALIDATE_URL) !== false) {
        return $direct;
    }

    $serverId = preg_replace('/\D+/', '', trim((string) config('discord.server_id', '')));
    if ($serverId === '') {
        return null;
    }

    $theme = strtolower(trim((string) config('discord.server_theme', 'dark')));
    if (!in_array($theme, ['dark', 'light'], true)) {
        $theme = 'dark';
    }

    return 'https://discord.com/widget?id=' . rawurlencode($serverId) . '&theme=' . rawurlencode($theme);
}

function send_discord_webhook(string $content, array $embeds = []): bool
{
    $url = discord_webhook_url();
    if ($url === null) {
        return false;
    }

    $payload = [
        'username' => app_name() . ' Notifier',
        'content' => substr(trim($content), 0, 1900),
    ];

    if ($embeds !== []) {
        $payload['embeds'] = $embeds;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: demonlist-php',
            ],
            CURLOPT_TIMEOUT => 5,
        ]);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status >= 200 && $status < 300;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: demonlist-php\r\n",
            'content' => $json,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);

    @file_get_contents($url, false, $context);

    if (!isset($http_response_header) || !is_array($http_response_header) || $http_response_header === []) {
        return false;
    }

    if (preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $match) !== 1) {
        return false;
    }

    $status = (int) $match[1];
    return $status >= 200 && $status < 300;
}

function current_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function redirect(string $path): never
{
    $target = str_starts_with($path, '/') ? $path : base_url($path);
    header('Location: ' . $target);
    exit;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $output = (string) $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $output;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf_token'];
}

function validate_csrf(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function method_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function validate_username(string $username): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_]{3,24}$/', $username);
}

function country_name_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $file = __DIR__ . '/country_names.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $map = [];
            foreach ($loaded as $code => $name) {
                $normalizedCode = strtoupper(trim((string) $code));
                if (preg_match('/^[A-Z]{2}$/', $normalizedCode) !== 1) {
                    continue;
                }

                $map[$normalizedCode] = trim((string) $name);
            }

            if ($map !== []) {
                return $map;
            }
        }
    }

    $map = [
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'VN' => 'Vietnam',
    ];

    return $map;
}

function supported_countries(): array
{
    static $countries = null;
    if ($countries !== null) {
        return $countries;
    }

    $flagsDir = dirname(__DIR__) . '/assets/flags';
    $codes = [];

    if (is_dir($flagsDir)) {
        $entries = scandir($flagsDir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (preg_match('/^([a-z]{2})\.svg$/i', $entry, $match) !== 1) {
                    continue;
                }

                $codes[] = strtoupper($match[1]);
            }
        }
    }

    if ($codes === []) {
        $countries = country_name_map();
        asort($countries, SORT_NATURAL | SORT_FLAG_CASE);
        return $countries;
    }

    $codes = array_values(array_unique($codes));
    sort($codes, SORT_STRING);

    $nameMap = country_name_map();
    $countries = [];

    foreach ($codes as $code) {
        $countries[$code] = $nameMap[$code] ?? $code;
    }

    asort($countries, SORT_NATURAL | SORT_FLAG_CASE);
    return $countries;
}

function normalize_country_code(?string $code): ?string
{
    $normalized = strtoupper(trim((string) $code));
    if ($normalized === '') {
        return null;
    }

    return array_key_exists($normalized, supported_countries()) ? $normalized : null;
}

function country_name(?string $code): ?string
{
    $normalized = normalize_country_code($code);
    if ($normalized === null) {
        return null;
    }

    return supported_countries()[$normalized] ?? null;
}

function country_flag_emoji(?string $code): string
{
    $normalized = normalize_country_code($code);
    if ($normalized === null) {
        return '';
    }

    $first = ord($normalized[0]) - 65 + 127462;
    $second = ord($normalized[1]) - 65 + 127462;
    return html_entity_decode('&#' . $first . ';&#' . $second . ';', ENT_NOQUOTES, 'UTF-8');
}

function country_flag_asset_url(?string $code): ?string
{
    $normalized = normalize_country_code($code);
    if ($normalized === null) {
        return null;
    }

    $fileName = strtolower($normalized) . '.svg';
    $filePath = dirname(__DIR__) . '/assets/flags/' . $fileName;
    if (!is_file($filePath)) {
        return null;
    }

    return base_url('assets/flags/' . $fileName);
}

function country_flag_html(?string $code, bool $withSpacing = false): string
{
    $url = country_flag_asset_url($code);
    if ($url === null) {
        $emoji = country_flag_emoji($code);
        return $emoji;
    }

    $style = "background-image: url('" . e($url) . "');";
    if ($withSpacing) {
        $style .= ' margin-right: 6px;';
    }

    return '<span class="flag-icon" style="' . $style . '"></span>';
}

function current_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }

    $cached = true;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT id, username, email, country_code, password_hash, role, points, created_at
                               FROM users
                               WHERE id = :id
                               LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            unset($_SESSION['user_id']);
            return null;
        }

        $user = $row;
        return $user;
    } catch (Throwable) {
        return null;
    }
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function current_user_id(): ?int
{
    $user = current_user();
    return $user !== null ? (int) $user['id'] : null;
}

function current_user_display_name(): ?string
{
    $user = current_user();
    return $user !== null ? (string) $user['username'] : null;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
}

function require_login(?string $next = null): void
{
    if (is_logged_in()) {
        return;
    }

    $destination = $next;
    if ($destination === null || $destination === '') {
        $destination = current_path();
    }

    flash('error', 'You need to login before submitting records.');
    redirect('login.php?next=' . rawurlencode($destination));
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && (($user['role'] ?? '') === 'admin');
}

function require_admin(): void
{
    if (is_admin()) {
        return;
    }

    flash('error', 'Admin permission required.');
    redirect('admin.php');
}

