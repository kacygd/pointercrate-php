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

/**
 * @return array<int, string>
 */
function split_creator_names(mixed $raw): array
{
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*/u', $value) ?: [];
    $names = [];
    $seen = [];

    foreach ($parts as $part) {
        $name = trim((string) $part);
        if ($name === '' || $name === '-') {
            continue;
        }

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $names[] = $name;
    }

    return $names;
}

/**
 * @return array<int, string>
 */
function demon_creator_names(array $demon): array
{
    $names = [];
    foreach (['creator', 'creator_more'] as $field) {
        foreach (split_creator_names($demon[$field] ?? '') as $name) {
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
            if (isset($names[$key])) {
                continue;
            }

            $names[$key] = $name;
        }
    }

    return array_values($names);
}

function demon_primary_creator_name(array $demon): string
{
    $names = demon_creator_names($demon);
    if ($names !== []) {
        return $names[0];
    }

    return trim((string) ($demon['publisher'] ?? ''));
}

/**
 * @return array<int, string>
 */
function demon_extra_creator_names(array $demon): array
{
    $names = demon_creator_names($demon);
    if (count($names) <= 1) {
        return [];
    }

    return array_slice($names, 1);
}

function normalize_public_path(string $path): string
{
    $trimmed = ltrim(trim($path), '/');
    if ($trimmed === '') {
        return '';
    }

    $fragment = '';
    $hashPos = strpos($trimmed, '#');
    if ($hashPos !== false) {
        $fragment = (string) substr($trimmed, $hashPos);
        $trimmed = (string) substr($trimmed, 0, $hashPos);
    }

    if ($trimmed === '') {
        return $fragment;
    }

    $normalized = $trimmed;

    if (preg_match('/^index\.php(?:\?.*)?$/i', $trimmed) === 1) {
        $normalized = '';
    } elseif (preg_match('/^demon\.php\?rank=([0-9]+)$/i', $trimmed, $match) === 1) {
        $normalized = (string) ((int) $match[1]);
    } elseif (preg_match('/^demon\.php\?id=([0-9]+)$/i', $trimmed, $match) === 1) {
        $normalized = 'demon.php?id=' . (int) $match[1];
    } elseif (preg_match('/^id=([0-9]+)$/i', $trimmed, $match) === 1) {
        $normalized = 'id=' . (int) $match[1];
    } elseif (preg_match('/^(players|guidelines|submit|admin|account|profile|login|logout|register|roulette|time-machine)\.php$/i', $trimmed, $match) === 1) {
        $normalized = strtolower($match[1]);
    } elseif (preg_match('/^(players|guidelines|submit|admin|account|profile|login|logout|register|roulette|time-machine)\.php\?(.+)$/i', $trimmed, $match) === 1) {
        $normalized = strtolower($match[1]) . '?' . $match[2];
    }

    if ($normalized === '') {
        return $fragment;
    }

    return $normalized . $fragment;
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

function discord_bot_token(): ?string
{
    $token = trim((string) config('discord.bot_token', ''));
    return $token !== '' ? $token : null;
}

function discord_bot_api_base_url(): string
{
    $url = trim((string) config('discord.bot_api_base_url', 'https://discord.com/api/v10'));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'https://discord.com/api/v10';
    }

    return rtrim($url, '/');
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

function users_has_discord_link_columns(?PDO $pdo = null): bool
{
    static $checked = false;
    static $result = false;

    if ($checked) {
        return $result;
    }

    $checked = true;

    try {
        $pdo = $pdo instanceof PDO ? $pdo : db();
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name IN (
                   'discord_user_id',
                   'discord_username',
                   'discord_link_pending_user_id',
                   'discord_link_code_hash',
                   'discord_link_code_expires_at',
                   'discord_link_requested_at'
               )"
        );
        $result = (int) $stmt->fetchColumn() >= 6;
    } catch (Throwable) {
        $result = false;
    }

    return $result;
}

function users_has_is_banned_column(?PDO $pdo = null): bool
{
    static $checked = false;
    static $result = false;

    if ($checked) {
        return $result;
    }

    $checked = true;

    try {
        $pdo = $pdo instanceof PDO ? $pdo : db();
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name = 'is_banned'"
        );
        $result = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        $result = false;
    }

    return $result;
}

function users_has_comments_disabled_column(?PDO $pdo = null): bool
{
    static $checked = false;
    static $result = false;

    if ($checked) {
        return $result;
    }

    $checked = true;

    try {
        $pdo = $pdo instanceof PDO ? $pdo : db();
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name = 'comments_disabled'"
        );
        $result = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        $result = false;
    }

    return $result;
}

function users_has_display_name_column(?PDO $pdo = null): bool
{
    static $checked = false;
    static $result = false;

    if ($checked) {
        return $result;
    }

    $checked = true;

    try {
        $pdo = $pdo instanceof PDO ? $pdo : db();
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'users'
               AND column_name = 'display_name'"
        );
        $result = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        $result = false;
    }

    return $result;
}

function user_select_display_name_expression(string $tableAlias = '', string $usernameColumn = 'username', string $displayNameColumn = 'display_name'): string
{
    $prefix = '';
    $normalizedAlias = trim($tableAlias);
    if ($normalizedAlias !== '') {
        $prefix = rtrim($normalizedAlias, '.') . '.';
    }

    if (users_has_display_name_column()) {
        return 'COALESCE(NULLIF(' . $prefix . $displayNameColumn . ", ''), " . $prefix . $usernameColumn . ') AS display_name';
    }

    return $prefix . $usernameColumn . ' AS display_name';
}

function user_display_name_from_row(array $user, string $fallbackKey = 'username'): string
{
    $displayName = normalize_display_name((string) ($user['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }

    return trim((string) ($user[$fallbackKey] ?? ''));
}

function user_has_custom_display_name(array $user): bool
{
    return normalize_display_name((string) ($user['display_name'] ?? '')) !== '';
}

function app_settings_table_ready(?PDO $pdo = null): bool
{
    static $ready = null;
    if (is_bool($ready)) {
        return $ready;
    }

    try {
        $pdo = $pdo instanceof PDO ? $pdo : db();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $ready = true;
    } catch (Throwable) {
        $ready = false;
    }

    return $ready;
}

function app_setting_get(string $key, ?string $default = null): ?string
{
    static $cache = [];

    $normalizedKey = strtolower(trim($key));
    if ($normalizedKey === '') {
        return $default;
    }

    if (array_key_exists($normalizedKey, $cache)) {
        return $cache[$normalizedKey] ?? $default;
    }

    try {
        $pdo = db();
        if (!app_settings_table_ready($pdo)) {
            return $default;
        }

        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute([':setting_key' => $normalizedKey]);
        $value = $stmt->fetchColumn();
        if ($value === false || !is_string($value)) {
            $cache[$normalizedKey] = null;
            return $default;
        }

        $cache[$normalizedKey] = $value;
        return $value;
    } catch (Throwable) {
        return $default;
    }
}

function app_setting_set(string $key, string $value): bool
{
    static $cache = [];

    $normalizedKey = strtolower(trim($key));
    if ($normalizedKey === '') {
        return false;
    }

    try {
        $pdo = db();
        if (!app_settings_table_ready($pdo)) {
            return false;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':setting_key' => $normalizedKey,
            ':setting_value' => trim($value),
        ]);

        $cache[$normalizedKey] = trim($value);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function app_setting_truthy(?string $value, bool $default): bool
{
    if (!is_string($value)) {
        return $default;
    }

    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function demonlist_show_extended_default(): bool
{
    return true;
}

function demonlist_show_legacy_default(): bool
{
    return true;
}

function demonlist_show_extended_list(): bool
{
    return app_setting_truthy(
        app_setting_get('list.show_extended', null),
        demonlist_show_extended_default()
    );
}

function demonlist_show_legacy_list(): bool
{
    return app_setting_truthy(
        app_setting_get('list.show_legacy', null),
        demonlist_show_legacy_default()
    );
}

function demonlist_set_show_extended_list(bool $enabled): bool
{
    return app_setting_set('list.show_extended', $enabled ? '1' : '0');
}

function demonlist_set_show_legacy_list(bool $enabled): bool
{
    return app_setting_set('list.show_legacy', $enabled ? '1' : '0');
}

function demonlist_list_limit_min(): int
{
    return 1;
}

function demonlist_list_limit_max(): int
{
    return 10000;
}

function demonlist_main_list_limit_default(): int
{
    return 75;
}

function demonlist_extended_list_limit_default(): int
{
    return 150;
}

function demonlist_setting_int(?string $value, int $default): int
{
    if (!is_string($value)) {
        return $default;
    }

    $normalized = trim($value);
    if ($normalized === '' || preg_match('/^-?\d+$/', $normalized) !== 1) {
        return $default;
    }

    return (int) $normalized;
}

function demonlist_clamp_list_limit(int $value): int
{
    $min = demonlist_list_limit_min();
    $max = demonlist_list_limit_max();

    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }

    return $value;
}

function demonlist_list_limits_are_valid(int $mainLimit, int $extendedLimit): bool
{
    $min = demonlist_list_limit_min();
    $max = demonlist_list_limit_max();

    if ($mainLimit < $min || $mainLimit > $max) {
        return false;
    }

    if ($extendedLimit < $mainLimit || $extendedLimit > $max) {
        return false;
    }

    return true;
}

function demonlist_main_list_limit(): int
{
    $value = demonlist_setting_int(
        app_setting_get('list.main_max_rank', null),
        demonlist_main_list_limit_default()
    );

    return demonlist_clamp_list_limit($value);
}

function demonlist_extended_list_limit(): int
{
    $mainLimit = demonlist_main_list_limit();
    $value = demonlist_setting_int(
        app_setting_get('list.extended_max_rank', null),
        demonlist_extended_list_limit_default()
    );

    $value = demonlist_clamp_list_limit($value);
    if ($value < $mainLimit) {
        $value = $mainLimit;
    }

    return $value;
}

function demonlist_set_list_limits(int $mainLimit, int $extendedLimit): bool
{
    if (!demonlist_list_limits_are_valid($mainLimit, $extendedLimit)) {
        return false;
    }

    return app_setting_set('list.main_max_rank', (string) $mainLimit)
        && app_setting_set('list.extended_max_rank', (string) $extendedLimit);
}

function demon_level_info_field_definitions(): array
{
    return [
        'position' => 'Position',
        'category' => 'Category',
        'difficulty' => 'Difficulty',
        'requirement' => 'Requirement',
        'creator' => 'Created by',
        'publisher' => 'Published by',
        'verifier' => 'Verified by',
        'level_id' => 'Level ID',
        'level_length' => 'Level Length',
        'song' => 'Song',
        'object_count' => 'Object Count',
    ];
}

function demon_level_info_label(string $label, string $fallback): string
{
    $label = normalize_display_name($label);
    if ($label === '') {
        $label = $fallback;
    }

    return function_exists('mb_substr')
        ? (string) mb_substr($label, 0, 60, 'UTF-8')
        : (string) substr($label, 0, 60);
}

function demon_level_info_value(string $value, int $maxLength = 500): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    $value = trim((string) ($value ?? ''));

    return function_exists('mb_substr')
        ? (string) mb_substr($value, 0, $maxLength, 'UTF-8')
        : (string) substr($value, 0, $maxLength);
}

function demon_level_info_normalize_custom_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = (string) preg_replace('/[^a-z0-9]+/', '-', $key);
    $key = trim($key, '-');

    return function_exists('mb_substr')
        ? (string) mb_substr($key, 0, 64, 'UTF-8')
        : (string) substr($key, 0, 64);
}

function demon_level_info_unique_custom_key(string $baseKey, array $seen): string
{
    $baseKey = demon_level_info_normalize_custom_key($baseKey);
    if ($baseKey === '') {
        $baseKey = 'custom';
    }

    $key = $baseKey;
    $suffix = 2;
    while (isset($seen['custom:' . $key])) {
        $candidateBase = function_exists('mb_substr')
            ? (string) mb_substr($baseKey, 0, 57, 'UTF-8')
            : (string) substr($baseKey, 0, 57);
        $key = rtrim($candidateBase, '-') . '-' . $suffix;
        $suffix++;
    }

    return $key;
}

function demon_level_info_default_rows(): array
{
    $definitions = demon_level_info_field_definitions();
    $rows = [];
    foreach (array_keys($definitions) as $field) {
        $rows[] = [
            'type' => 'field',
            'field' => $field,
            'label' => $definitions[$field],
        ];
    }

    return $rows;
}

function demon_level_info_sanitize_rows(array $rows, bool $fallbackToDefault = false): array
{
    $definitions = demon_level_info_field_definitions();
    $sanitized = [];
    $seen = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $type = strtolower(trim((string) ($row['type'] ?? 'field')));
        if (!in_array($type, ['field', 'custom'], true)) {
            $type = 'field';
        }

        if ($type === 'custom') {
            $label = demon_level_info_label((string) ($row['label'] ?? ''), 'Custom Row');
            $keyBase = (string) ($row['key'] ?? '');
            if ($keyBase === '') {
                $keyBase = $label !== '' ? $label : 'custom-' . ((int) $index + 1);
            }
            $key = demon_level_info_unique_custom_key($keyBase, $seen);

            $sanitized[] = [
                'type' => 'custom',
                'key' => $key,
                'label' => $label,
                'default_value' => demon_level_info_value((string) ($row['default_value'] ?? '')),
            ];
            $seen['custom:' . $key] = true;
            continue;
        }

        $field = strtolower(trim((string) ($row['field'] ?? '')));
        if (!array_key_exists($field, $definitions) || isset($seen['field:' . $field])) {
            continue;
        }

        $sanitized[] = [
            'type' => 'field',
            'field' => $field,
            'label' => demon_level_info_label((string) ($row['label'] ?? ''), $definitions[$field]),
        ];
        $seen['field:' . $field] = true;
    }

    if ($sanitized === [] && $fallbackToDefault) {
        return demon_level_info_default_rows();
    }

    return $sanitized;
}

function demon_level_info_rows(): array
{
    $raw = app_setting_get('demon.level_info_rows', null);
    if (!is_string($raw) || trim($raw) === '') {
        return demon_level_info_default_rows();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return demon_level_info_default_rows();
    }

    return demon_level_info_sanitize_rows($decoded, false);
}

function demon_level_info_set_rows(array $rows): bool
{
    $encoded = json_encode(
        demon_level_info_sanitize_rows($rows, false),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($encoded)) {
        return false;
    }

    return app_setting_set('demon.level_info_rows', $encoded);
}

function demon_level_info_restore_default_rows(): bool
{
    $encoded = json_encode(demon_level_info_default_rows(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    return app_setting_set('demon.level_info_rows', $encoded);
}

function demon_level_info_custom_rows(?array $rows = null): array
{
    $rows = $rows ?? demon_level_info_rows();
    $customRows = [];

    foreach ($rows as $row) {
        if (!is_array($row) || (string) ($row['type'] ?? 'field') !== 'custom') {
            continue;
        }

        $key = demon_level_info_normalize_custom_key((string) ($row['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $customRows[] = [
            'type' => 'custom',
            'key' => $key,
            'label' => demon_level_info_label((string) ($row['label'] ?? ''), 'Custom Row'),
            'default_value' => demon_level_info_value((string) ($row['default_value'] ?? '')),
        ];
    }

    return $customRows;
}

function demon_level_info_custom_values(PDO $pdo, int $demonId): array
{
    if ($demonId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT row_key, row_value FROM demon_level_info_values WHERE demon_id = :demon_id');
        $stmt->execute([':demon_id' => $demonId]);
        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = demon_level_info_normalize_custom_key((string) ($row['row_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $values[$key] = (string) ($row['row_value'] ?? '');
        }

        return $values;
    } catch (Throwable) {
        return [];
    }
}

function demon_level_info_custom_value_updates_from_post(mixed $rawValues, mixed $rawClears): array
{
    $values = [];
    if (is_array($rawValues)) {
        foreach ($rawValues as $key => $value) {
            $normalizedKey = demon_level_info_normalize_custom_key((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $values[$normalizedKey] = demon_level_info_value((string) $value);
        }
    }

    $clears = [];
    if (is_array($rawClears)) {
        foreach ($rawClears as $key => $value) {
            $normalizedKey = demon_level_info_normalize_custom_key((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (app_setting_truthy((string) $value, false)) {
                $clears[] = $normalizedKey;
            }
        }
    }

    return [
        'values' => $values,
        'clears' => array_values(array_unique($clears)),
    ];
}

function demon_level_info_save_custom_values(PDO $pdo, int $demonId, array $values, array $clearKeys = []): bool
{
    if ($demonId < 1) {
        return false;
    }

    $allowedKeys = [];
    foreach (demon_level_info_custom_rows() as $row) {
        $allowedKeys[(string) $row['key']] = true;
    }
    if ($allowedKeys === []) {
        return true;
    }

    try {
        if ($clearKeys !== []) {
            $delete = $pdo->prepare('DELETE FROM demon_level_info_values WHERE demon_id = :demon_id AND row_key = :row_key');
            foreach ($clearKeys as $key) {
                $key = demon_level_info_normalize_custom_key((string) $key);
                if ($key === '' || !isset($allowedKeys[$key])) {
                    continue;
                }

                $delete->execute([
                    ':demon_id' => $demonId,
                    ':row_key' => $key,
                ]);
            }
        }

        $upsert = $pdo->prepare(
            'INSERT INTO demon_level_info_values (demon_id, row_key, row_value)
             VALUES (:demon_id, :row_key, :row_value)
             ON DUPLICATE KEY UPDATE
                row_value = VALUES(row_value),
                updated_at = CURRENT_TIMESTAMP'
        );
        foreach ($values as $key => $value) {
            $key = demon_level_info_normalize_custom_key((string) $key);
            $value = demon_level_info_value((string) $value);
            if ($key === '' || !isset($allowedKeys[$key]) || $value === '') {
                continue;
            }

            $upsert->execute([
                ':demon_id' => $demonId,
                ':row_key' => $key,
                ':row_value' => $value,
            ]);
        }

        return true;
    } catch (Throwable) {
        return false;
    }
}

function level_comments_enabled(): bool
{
    return app_setting_truthy(app_setting_get('level_comments.enabled', null), true);
}

function level_comments_set_enabled(bool $enabled): bool
{
    return app_setting_set('level_comments.enabled', $enabled ? '1' : '0');
}

function level_comments_enabled_for_demon(array $demon): bool
{
    return level_comments_enabled() && (int) ($demon['comments_disabled'] ?? 0) !== 1;
}

function current_user_comments_disabled(): bool
{
    if (!users_has_comments_disabled_column()) {
        return false;
    }

    $user = current_user();
    return $user !== null && (int) ($user['comments_disabled'] ?? 0) === 1;
}

function current_user_can_comment(): bool
{
    return is_logged_in() && !current_user_comments_disabled();
}

function level_comments_disabled_message(array $demon): ?string
{
    if (!level_comments_enabled()) {
        return 'Comments are disabled for the whole list.';
    }

    if ((int) ($demon['comments_disabled'] ?? 0) === 1) {
        return 'Comments are disabled for this level.';
    }

    return null;
}

function level_comment_body_max_length(): int
{
    return 1000;
}

function normalize_level_comment_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", trim($body));
    $body = (string) preg_replace("/[ \t]+/u", ' ', $body);
    $body = (string) preg_replace("/\n{3,}/u", "\n\n", $body);

    return function_exists('mb_substr')
        ? (string) mb_substr($body, 0, level_comment_body_max_length(), 'UTF-8')
        : (string) substr($body, 0, level_comment_body_max_length());
}

function can_pin_level_comments(): bool
{
    return can_moderate_level_comments();
}

function level_comment_reaction_value(string $reaction): ?int
{
    return match (strtolower(trim($reaction))) {
        'like' => 1,
        'dislike' => -1,
        default => null,
    };
}

function level_comment_report_reason_max_length(): int
{
    return 255;
}

function normalize_level_comment_report_reason(string $reason): string
{
    $reason = trim(str_replace(["\r", "\n"], ' ', $reason));
    $reason = (string) preg_replace('/\s+/u', ' ', $reason);

    return function_exists('mb_substr')
        ? (string) mb_substr($reason, 0, level_comment_report_reason_max_length(), 'UTF-8')
        : (string) substr($reason, 0, level_comment_report_reason_max_length());
}

function badge_name_max_length(): int
{
    return 36;
}

function badge_description_max_length(): int
{
    return 140;
}

function normalize_badge_name(string $name): string
{
    $name = normalize_display_name($name);

    return function_exists('mb_substr')
        ? (string) mb_substr($name, 0, badge_name_max_length(), 'UTF-8')
        : (string) substr($name, 0, badge_name_max_length());
}

function normalize_badge_description(string $description): string
{
    $description = normalize_display_name($description);

    return function_exists('mb_substr')
        ? (string) mb_substr($description, 0, badge_description_max_length(), 'UTF-8')
        : (string) substr($description, 0, badge_description_max_length());
}

function normalize_badge_image_url(string $imageUrl): string
{
    $imageUrl = trim(str_replace(["\r", "\n"], '', $imageUrl));
    if ($imageUrl === '') {
        return '';
    }

    $imageUrl = function_exists('mb_substr')
        ? (string) mb_substr($imageUrl, 0, 255, 'UTF-8')
        : (string) substr($imageUrl, 0, 255);

    if (preg_match('#^https?://#i', $imageUrl) === 1) {
        return filter_var($imageUrl, FILTER_VALIDATE_URL) !== false ? $imageUrl : '';
    }

    if (str_starts_with($imageUrl, '//')) {
        return '';
    }

    return preg_match('#^[A-Za-z0-9_./~%-]+\.(?:png|jpe?g|gif|webp|svg)$#i', ltrim($imageUrl, '/')) === 1
        ? $imageUrl
        : '';
}

function badge_image_src(string $imageUrl): string
{
    $imageUrl = normalize_badge_image_url($imageUrl);
    if ($imageUrl === '') {
        return '';
    }

    return preg_match('#^https?://#i', $imageUrl) === 1
        ? $imageUrl
        : base_url($imageUrl);
}

function normalize_badge_color(string $color, string $fallback = '#465A7A'): string
{
    $normalize = static function (string $value): ?string {
        $value = strtoupper(trim($value));
        if (preg_match('/^#?([0-9A-F]{3})$/', $value, $match) === 1) {
            $hex = $match[1];
            return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^#?([0-9A-F]{6})$/', $value, $match) === 1) {
            return '#' . $match[1];
        }

        return null;
    };

    return $normalize($color) ?? $normalize($fallback) ?? '#465A7A';
}

function badge_text_color_for_background(string $background): string
{
    $hex = ltrim(normalize_badge_color($background), '#');
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;

    $linear = static function (float $channel): float {
        return $channel <= 0.03928
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    };

    $luminance = 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    return $luminance > 0.48 ? '#182030' : '#FFFFFF';
}

function sanitize_badge_row(array $row): ?array
{
    $name = normalize_badge_name((string) ($row['name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $color = normalize_badge_color((string) ($row['color'] ?? ''));
    $textColor = normalize_badge_color(
        (string) ($row['text_color'] ?? ''),
        badge_text_color_for_background($color)
    );

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => $name,
        'description' => normalize_badge_description((string) ($row['description'] ?? '')),
        'image_url' => normalize_badge_image_url((string) ($row['image_url'] ?? '')),
        'color' => $color,
        'text_color' => $textColor,
        'is_active' => (int) ($row['is_active'] ?? 1) === 1,
        'assigned_count' => (int) ($row['assigned_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'assigned_at' => (string) ($row['assigned_at'] ?? ''),
    ];
}

function badge_fetch_all(PDO $pdo, bool $activeOnly = true): array
{
    try {
        $sql = 'SELECT b.id, b.name, b.description, b.image_url, b.color, b.text_color, b.is_active, b.created_at,
                       COALESCE(assigned.assignment_count, 0) AS assigned_count
                FROM badges b
                LEFT JOIN (
                    SELECT badge_id, COUNT(*) AS assignment_count
                    FROM user_badges
                    GROUP BY badge_id
                ) assigned ON assigned.badge_id = b.id';
        if ($activeOnly) {
            $sql .= ' WHERE COALESCE(b.is_active, 1) = 1';
        }
        $sql .= ' ORDER BY b.created_at DESC, b.name ASC';

        $badges = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $badge = is_array($row) ? sanitize_badge_row($row) : null;
            if ($badge !== null && (int) $badge['id'] > 0) {
                $badges[] = $badge;
            }
        }

        return $badges;
    } catch (Throwable) {
        return [];
    }
}

function user_badges_by_user_ids(PDO $pdo, array $userIds): array
{
    $ids = [];
    foreach ($userIds as $userId) {
        $userId = (int) $userId;
        if ($userId > 0) {
            $ids[$userId] = $userId;
        }
    }

    if ($ids === []) {
        return [];
    }

    try {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $userId;
        }

        $stmt = $pdo->prepare(
            'SELECT ub.user_id, ub.assigned_at,
                    b.id, b.name, b.description, b.image_url, b.color, b.text_color, b.is_active, b.created_at
             FROM user_badges ub
             INNER JOIN badges b ON b.id = ub.badge_id
             WHERE ub.user_id IN (' . implode(', ', $placeholders) . ')
               AND COALESCE(b.is_active, 1) = 1
             ORDER BY ub.assigned_at ASC, b.name ASC'
        );
        $stmt->execute($params);

        $badgesByUser = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            $badge = sanitize_badge_row($row);
            if ($userId < 1 || $badge === null || (int) $badge['id'] < 1) {
                continue;
            }

            $badgesByUser[$userId][] = $badge;
        }

        return $badgesByUser;
    } catch (Throwable) {
        return [];
    }
}

function user_badges_for_user(PDO $pdo, int $userId): array
{
    $badges = user_badges_by_user_ids($pdo, [$userId]);
    return $badges[$userId] ?? [];
}

function user_badges_payload(array $badges): array
{
    $payload = [];
    foreach ($badges as $badge) {
        if (!is_array($badge)) {
            continue;
        }

        $sanitized = sanitize_badge_row($badge);
        if ($sanitized === null || (int) $sanitized['id'] < 1) {
            continue;
        }

        $payload[] = [
            'id' => (int) $sanitized['id'],
            'name' => (string) $sanitized['name'],
            'description' => (string) $sanitized['description'],
            'image_url' => badge_image_src((string) $sanitized['image_url']),
            'color' => (string) $sanitized['color'],
            'text_color' => (string) $sanitized['text_color'],
        ];
    }

    return $payload;
}

function render_user_badges(array $badges, string $className = ''): string
{
    $items = [];
    foreach (user_badges_payload($badges) as $badge) {
        $name = (string) $badge['name'];
        $description = (string) $badge['description'];
        $title = $description !== '' ? $name . ' - ' . $description : $name;
        $imageUrl = badge_image_src((string) ($badge['image_url'] ?? ''));
        if ($imageUrl === '') {
            continue;
        }

        $image = '<img class="user-badge-image" src="' . e($imageUrl) . '" alt="' . e($name) . '" loading="lazy">';

        $items[] = '<span class="user-badge" title="' . e($title) . '">' . $image . '</span>';
    }

    if ($items === []) {
        return '';
    }

    $className = trim((string) preg_replace('/[^a-zA-Z0-9 _-]+/', '', $className));
    $classAttr = 'user-badges' . ($className !== '' ? ' ' . $className : '');

    return '<span class="' . e($classAttr) . '">' . implode('', $items) . '</span>';
}

function demonlist_list_bucket(int $position, bool $legacy): string
{
    if ($position < 1) {
        return 'legacy';
    }

    if ($legacy) {
        return demonlist_show_legacy_list() ? 'legacy' : 'main';
    }

    $mainLimit = demonlist_main_list_limit();
    $extendedLimit = demonlist_extended_list_limit();

    if ($position <= $mainLimit) {
        return 'main';
    }

    if ($position <= $extendedLimit) {
        return demonlist_show_extended_list() ? 'extended' : 'main';
    }

    return demonlist_show_legacy_list() ? 'legacy' : 'main';
}

function demonlist_main_list_dropdown_description(bool $showExtendedList, bool $showLegacyList): string
{
    return match (true) {
        !$showExtendedList && !$showLegacyList => 'All ranked demons are currently merged into this single list.',
        !$showExtendedList && $showLegacyList => 'Main and Extended entries are merged into this list.',
        $showExtendedList && !$showLegacyList => 'Main and Legacy entries are merged into this list.',
        default => 'Top 1-' . demonlist_main_list_limit() . ' demons in the current list.',
    };
}

function demonlist_extended_list_dropdown_description(bool $includeScoreHint = false): string
{
    $mainLimit = demonlist_main_list_limit();
    $extendedLimit = demonlist_extended_list_limit();
    $firstExtendedRank = $mainLimit + 1;

    if ($extendedLimit < $firstExtendedRank) {
        return 'No ranks are currently assigned to Extended List.';
    }

    $description = 'Demons top ' . $firstExtendedRank . '-' . $extendedLimit;
    if ($includeScoreHint) {
        $description .= ' that still count toward score';
    }

    return $description . '.';
}

function demonlist_legacy_list_dropdown_description(): string
{
    $firstLegacyRank = demonlist_extended_list_limit() + 1;
    return 'Demons top ' . $firstLegacyRank . '+ or manually marked as legacy.';
}

function demonlist_is_ranked_entry(int $position, bool $legacy): bool
{
    if ($position < 1) {
        return false;
    }

    return demonlist_list_bucket($position, $legacy) !== 'legacy';
}

function demonlist_top1_points_default(): float
{
    return 350.0;
}

function demonlist_top1_points_min(): float
{
    return 50.0;
}

function demonlist_top1_points_max(): float
{
    return 5000.0;
}

function demonlist_top1_points(): float
{
    $rawValue = app_setting_get('scoring.top1_points', null);
    if ($rawValue === null || !is_numeric($rawValue)) {
        return demonlist_top1_points_default();
    }

    $value = round((float) $rawValue, 2);
    if ($value < demonlist_top1_points_min()) {
        $value = demonlist_top1_points_min();
    }
    if ($value > demonlist_top1_points_max()) {
        $value = demonlist_top1_points_max();
    }

    return $value;
}

function demonlist_set_top1_points(float $value): bool
{
    if (!is_finite($value)) {
        return false;
    }

    $normalized = round($value, 2);
    if ($normalized < demonlist_top1_points_min() || $normalized > demonlist_top1_points_max()) {
        return false;
    }

    return app_setting_set('scoring.top1_points', number_format($normalized, 2, '.', ''));
}

function demonlist_base_beaten_score(int $position): float
{
    return match (true) {
        $position >= 56 => 1.039035131 * ((185.7 * exp(-0.02715 * $position)) + 14.84),
        $position >= 36 && $position <= 55 => 1.0371139743 * ((212.61 * pow(1.036, 1 - $position)) + 25.071),
        $position >= 21 && $position <= 35 => ((250 - 83.389) * pow(1.0099685, 2 - $position) - 31.152) * 1.0371139743,
        $position >= 4 && $position <= 20 => ((326.1 * exp(-0.0871 * $position)) + 51.09) * 1.037117142,
        $position >= 1 && $position <= 3 => (-18.2899079915 * $position) + 368.2899079915,
        default => 0.0,
    };
}

function demonlist_beaten_score(int $position): float
{
    $baseScore = demonlist_base_beaten_score($position);
    if ($baseScore <= 0.0) {
        return 0.0;
    }

    $baseTopOne = demonlist_base_beaten_score(1);
    if ($baseTopOne <= 0.0) {
        return $baseScore;
    }

    $scale = demonlist_top1_points() / $baseTopOne;
    return $baseScore * $scale;
}

function demonlist_score(int $position, int $requirement, int $progress): float
{
    if ($position < 1 || $progress < $requirement) {
        return 0.0;
    }

    $requirement = max(1, min(100, $requirement));
    $progress = max(0, min(100, $progress));

    $beatenScore = demonlist_beaten_score($position);
    if ($progress !== 100) {
        return ($beatenScore * pow(5, ($progress - $requirement) / max(1, (100 - $requirement)))) / 10;
    }

    return $beatenScore;
}
function demonlist_sync_user_points(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    $users = $pdo->query('SELECT id, username, points, bonus_points FROM users')->fetchAll();
    if ($users === []) {
        return [
            'processed_users' => 0,
            'updated_users' => 0,
        ];
    }

    $userIdByName = [];
    $storedPointsByUserId = [];
    $bonusPointsByUserId = [];
    $completionTotalByUserId = [];
    $completionScoresByUserId = [];

    foreach ($users as $user) {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            continue;
        }

        $username = trim((string) ($user['username'] ?? ''));
        if ($username !== '') {
            $userIdByName[strtolower($username)] = $userId;
        }

        $storedPointsByUserId[$userId] = round((float) ($user['points'] ?? 0.0), 2);
        $bonusPointsByUserId[$userId] = round((float) ($user['bonus_points'] ?? 0.0), 2);
        $completionTotalByUserId[$userId] = 0.0;
        $completionScoresByUserId[$userId] = [];
    }

    if ($storedPointsByUserId === []) {
        return [
            'processed_users' => 0,
            'updated_users' => 0,
        ];
    }

    $demons = $pdo->query('SELECT id, position, requirement, legacy, verifier, verifier_user_id FROM demons')->fetchAll();
    $demonById = [];
    foreach ($demons as $demon) {
        $demonId = (int) ($demon['id'] ?? 0);
        if ($demonId < 1) {
            continue;
        }

        $demonById[$demonId] = [
            'position' => (int) ($demon['position'] ?? 0),
            'requirement' => (int) ($demon['requirement'] ?? 100),
            'legacy' => (int) ($demon['legacy'] ?? 0),
            'verifier' => trim((string) ($demon['verifier'] ?? '')),
            'verifier_user_id' => (int) ($demon['verifier_user_id'] ?? 0),
        ];
    }

    $completions = $pdo->query('SELECT demon_id, player, progress FROM completions')->fetchAll();
    foreach ($completions as $completion) {
        $demonId = (int) ($completion['demon_id'] ?? 0);
        if ($demonId < 1 || !isset($demonById[$demonId])) {
            continue;
        }

        $playerName = strtolower(trim((string) ($completion['player'] ?? '')));
        if ($playerName === '' || !isset($userIdByName[$playerName])) {
            continue;
        }

        $userId = (int) $userIdByName[$playerName];
        if (!isset($completionTotalByUserId[$userId])) {
            continue;
        }

        $demon = $demonById[$demonId];
        $isRankedEntry = demonlist_is_ranked_entry(
            (int) ($demon['position'] ?? 0),
            (int) ($demon['legacy'] ?? 0) === 1
        );
        if (!$isRankedEntry) {
            continue;
        }

        $progress = max(0, min(100, (int) ($completion['progress'] ?? 0)));
        $score = demonlist_score($demon['position'], $demon['requirement'], $progress);

        $completionTotalByUserId[$userId] += $score;
        $completionScoresByUserId[$userId][$demonId] = $score;
    }

    $verifierBonusByUserId = [];
    foreach ($storedPointsByUserId as $userId => $_storedPoints) {
        $verifierBonusByUserId[(int) $userId] = 0.0;
    }

    foreach ($demonById as $demonId => $demon) {
        $verifierUserId = (int) ($demon['verifier_user_id'] ?? 0);
        if ($verifierUserId < 1 || !isset($storedPointsByUserId[$verifierUserId])) {
            $verifierNameKey = strtolower(trim((string) ($demon['verifier'] ?? '')));
            if ($verifierNameKey !== '' && isset($userIdByName[$verifierNameKey])) {
                $verifierUserId = (int) $userIdByName[$verifierNameKey];
            }
        }

        if ($verifierUserId < 1 || !isset($storedPointsByUserId[$verifierUserId])) {
            continue;
        }

        $position = (int) ($demon['position'] ?? 0);
        $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
        if (!demonlist_is_ranked_entry($position, $isLegacy)) {
            continue;
        }

        $fullVerifierScore = demonlist_score($position, (int) ($demon['requirement'] ?? 100), 100);
        $existingCompletionScore = (float) ($completionScoresByUserId[$verifierUserId][$demonId] ?? 0.0);
        if ($fullVerifierScore > $existingCompletionScore) {
            $verifierBonusByUserId[$verifierUserId] += ($fullVerifierScore - $existingCompletionScore);
        }
    }

    $updateStmt = $pdo->prepare('UPDATE users SET points = :points WHERE id = :id');
    $updatedUsers = 0;
    $processedUsers = 0;

    foreach ($storedPointsByUserId as $userId => $storedPoints) {
        $processedUsers++;

        $newPoints = round(
            (float) ($completionTotalByUserId[$userId] ?? 0.0)
            + (float) ($verifierBonusByUserId[$userId] ?? 0.0)
            + (float) ($bonusPointsByUserId[$userId] ?? 0.0),
            2
        );

        if (abs((float) $storedPoints - $newPoints) < 0.01) {
            continue;
        }

        $updateStmt->execute([
            ':points' => $newPoints,
            ':id' => $userId,
        ]);
        $updatedUsers++;
    }

    return [
        'processed_users' => $processedUsers,
        'updated_users' => $updatedUsers,
    ];
}

function discord_truncate_text(string $text, int $maxLength): string
{
    $text = trim($text);
    if ($maxLength < 1 || $text === '') {
        return '';
    }

    return function_exists('mb_substr')
        ? (string) mb_substr($text, 0, $maxLength, 'UTF-8')
        : (string) substr($text, 0, $maxLength);
}

function discord_text_length(string $text): int
{
    return function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
}

function discord_take_text(string $text, int $fieldLimit, int &$remaining): string
{
    if ($remaining < 1) {
        return '';
    }

    $text = discord_truncate_text($text, min($fieldLimit, $remaining));
    $remaining -= discord_text_length($text);

    return $text;
}

function discord_log_delivery_failure(string $target, int $status, string $message = ''): void
{
    $target = discord_truncate_text($target, 80);
    if ($target === '') {
        $target = 'delivery';
    }

    $summary = '[Discord] ' . $target . ' failed';
    if ($status > 0) {
        $summary .= ' with HTTP ' . $status;
    }

    $message = discord_truncate_text($message, 500);
    if ($message !== '') {
        $summary .= ': ' . $message;
    }

    error_log($summary);
}

function discord_normalize_embed(array $embed): array
{
    $normalized = [];
    $remaining = 6000;

    if (isset($embed['title'])) {
        $title = discord_take_text((string) $embed['title'], 256, $remaining);
        if ($title !== '') {
            $normalized['title'] = $title;
        }
    }
    if (isset($embed['description'])) {
        $description = discord_take_text((string) $embed['description'], 4096, $remaining);
        if ($description !== '') {
            $normalized['description'] = $description;
        }
    }
    if (isset($embed['url']) && filter_var((string) $embed['url'], FILTER_VALIDATE_URL) !== false) {
        $normalized['url'] = (string) $embed['url'];
    }
    if (isset($embed['color'])) {
        $normalized['color'] = max(0, min(0xFFFFFF, (int) $embed['color']));
    }
    if (isset($embed['timestamp'])) {
        $normalized['timestamp'] = (string) $embed['timestamp'];
    }

    if (isset($embed['fields']) && is_array($embed['fields'])) {
        $fields = [];
        foreach ($embed['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldRemaining = $remaining;
            $name = discord_take_text((string) ($field['name'] ?? ''), 256, $fieldRemaining);
            $value = discord_take_text((string) ($field['value'] ?? ''), 1024, $fieldRemaining);
            if ($name === '' || $value === '') {
                continue;
            }
            $remaining = $fieldRemaining;

            $fields[] = [
                'name' => $name,
                'value' => $value,
                'inline' => !empty($field['inline']),
            ];

            if (count($fields) >= 25) {
                break;
            }
        }

        if ($fields !== []) {
            $normalized['fields'] = $fields;
        }
    }

    if (isset($embed['footer']) && is_array($embed['footer'])) {
        $footerText = discord_take_text((string) ($embed['footer']['text'] ?? ''), 2048, $remaining);
        if ($footerText !== '') {
            $normalized['footer'] = ['text' => $footerText];
        }
    }

    if (isset($embed['author']) && is_array($embed['author'])) {
        $authorName = discord_take_text((string) ($embed['author']['name'] ?? ''), 256, $remaining);
        if ($authorName !== '') {
            $normalized['author'] = ['name' => $authorName];
        }
    }

    return $normalized;
}

function send_discord_webhook(string $content, array $embeds = []): bool
{
    $url = discord_webhook_url();
    if ($url === null) {
        return false;
    }

    $webhookUsername = trim((string) config('discord.webhook_username', app_name() . ' Notifier'));
    if ($webhookUsername === '') {
        $webhookUsername = app_name() . ' Notifier';
    }
    $webhookAvatarUrl = trim((string) config('discord.webhook_avatar_url', ''));
    if ($webhookAvatarUrl !== '' && filter_var($webhookAvatarUrl, FILTER_VALIDATE_URL) === false) {
        $webhookAvatarUrl = '';
    }

    $payload = [
        'username' => discord_truncate_text($webhookUsername, 80),
        'content' => discord_truncate_text($content, 1900),
        'allowed_mentions' => ['parse' => []],
    ];
    if ($webhookAvatarUrl !== '') {
        $payload['avatar_url'] = $webhookAvatarUrl;
    }

    if ($embeds !== []) {
        $normalizedEmbeds = [];
        foreach ($embeds as $embed) {
            if (!is_array($embed)) {
                continue;
            }

            if (!isset($embed['footer'])) {
                $embed['footer'] = ['text' => app_name() . ' Admin Event'];
            }
            if (!isset($embed['timestamp'])) {
                $embed['timestamp'] = gmdate('c');
            }

            $normalized = discord_normalize_embed($embed);
            if ($normalized === []) {
                continue;
            }

            $normalizedEmbeds[] = $normalized;
            if (count($normalizedEmbeds) >= 10) {
                break;
            }
        }

        if ($normalizedEmbeds !== []) {
            $payload['embeds'] = $normalizedEmbeds;
        }
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        discord_log_delivery_failure('webhook json encode', 0, json_last_error_msg());
        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            discord_log_delivery_failure('webhook curl init', 0);
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
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        $body = is_string($response) ? $response : '';
        $curlError = $response === false ? curl_error($ch) : '';
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $ok = $status >= 200 && $status < 300;
        if (!$ok) {
            discord_log_delivery_failure('webhook', $status, $curlError !== '' ? $curlError : $body);
        }

        return $ok;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: demonlist-php\r\n",
            'content' => $json,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $body = is_string($response) ? $response : '';

    if (!isset($http_response_header) || !is_array($http_response_header) || $http_response_header === []) {
        discord_log_delivery_failure('webhook stream', 0, $body);
        return false;
    }

    if (preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $match) !== 1) {
        discord_log_delivery_failure('webhook stream', 0, (string) $http_response_header[0]);
        return false;
    }

    $status = (int) $match[1];
    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        discord_log_delivery_failure('webhook', $status, $body);
    }

    return $ok;
}

function discord_bot_api_request(string $method, string $endpoint, ?array $payload = null): array
{
    $token = discord_bot_token();
    if ($token === null) {
        return ['ok' => false, 'status' => 0, 'data' => null];
    }

    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
        return ['ok' => false, 'status' => 0, 'data' => null];
    }

    $endpoint = '/' . ltrim($endpoint, '/');
    $url = discord_bot_api_base_url() . $endpoint;
    $json = $payload !== null
        ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';
    if ($json === false) {
        discord_log_delivery_failure('bot api json encode', 0, json_last_error_msg());
        return ['ok' => false, 'status' => 0, 'data' => null];
    }

    $headers = [
        'Authorization: Bot ' . $token,
        'User-Agent: demonlist-php',
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $body = '';
    $status = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            discord_log_delivery_failure('bot api curl init', 0);
            return ['ok' => false, 'status' => 0, 'data' => null];
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $response = curl_exec($ch);
        $body = is_string($response) ? $response : '';
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = $response === false ? curl_error($ch) : '';
        curl_close($ch);
    } else {
        $headerText = implode("\r\n", $headers) . "\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headerText,
                'content' => $payload !== null ? $json : '',
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $body = is_string($response) ? $response : '';
        if (isset($http_response_header) && is_array($http_response_header) && $http_response_header !== []
            && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $match) === 1) {
            $status = (int) $match[1];
        }
    }

    if ($status < 200 || $status >= 300) {
        discord_log_delivery_failure(
            'bot api ' . $method . ' ' . $endpoint,
            $status,
            $transportError !== '' ? $transportError : $body
        );
    }

    $decoded = null;
    if ($body !== '') {
        $parsed = json_decode($body, true);
        if (is_array($parsed)) {
            $decoded = $parsed;
        }
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $decoded,
    ];
}

function normalize_discord_user_id(string $input): string
{
    $input = trim($input);
    if (preg_match('/<@!?([0-9]{17,22})>/', $input, $match) === 1) {
        return $match[1];
    }
    if (preg_match('/([0-9]{17,22})/', $input, $match) === 1) {
        return $match[1];
    }

    return '';
}

function discord_user_label_from_api(string $discordUserId): string
{
    $discordUserId = normalize_discord_user_id($discordUserId);
    if ($discordUserId === '') {
        return '';
    }

    $response = discord_bot_api_request('GET', '/users/' . rawurlencode($discordUserId));
    if (empty($response['ok']) || !is_array($response['data'])) {
        return '';
    }

    $data = $response['data'];
    $globalName = trim((string) ($data['global_name'] ?? ''));
    $username = trim((string) ($data['username'] ?? ''));
    if ($globalName !== '' && $username !== '' && $globalName !== $username) {
        return $globalName . ' (@' . $username . ')';
    }
    if ($globalName !== '') {
        return $globalName;
    }

    return $username;
}

function send_discord_direct_message(string $discordUserId, string $content, array $embeds = []): bool
{
    $discordUserId = normalize_discord_user_id($discordUserId);
    if ($discordUserId === '' || discord_bot_token() === null) {
        return false;
    }

    $channelResponse = discord_bot_api_request('POST', '/users/@me/channels', [
        'recipient_id' => $discordUserId,
    ]);
    if (empty($channelResponse['ok']) || !is_array($channelResponse['data'])) {
        return false;
    }

    $channelId = trim((string) ($channelResponse['data']['id'] ?? ''));
    if ($channelId === '') {
        return false;
    }

    $payload = [
        'content' => discord_truncate_text($content, 1900),
        'allowed_mentions' => ['parse' => []],
    ];

    if ($embeds !== []) {
        $normalizedEmbeds = [];
        foreach ($embeds as $embed) {
            if (!is_array($embed)) {
                continue;
            }

            $normalized = discord_normalize_embed($embed);
            if ($normalized !== []) {
                $normalizedEmbeds[] = $normalized;
            }
            if (count($normalizedEmbeds) >= 10) {
                break;
            }
        }
        if ($normalizedEmbeds !== []) {
            $payload['embeds'] = $normalizedEmbeds;
        }
    }

    $messageResponse = discord_bot_api_request('POST', '/channels/' . rawurlencode($channelId) . '/messages', $payload);
    return !empty($messageResponse['ok']);
}

function send_discord_user_notification(PDO $pdo, int $userId, string $content, array $embeds = []): bool
{
    if ($userId < 1 || !users_has_discord_link_columns($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT discord_user_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $discordUserId = trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable) {
        return false;
    }

    if ($discordUserId === '') {
        return false;
    }

    return send_discord_direct_message($discordUserId, $content, $embeds);
}

function current_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function current_request_path_with_query(): string
{
    $uri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($uri === '') {
        return current_path();
    }

    if (preg_match('#^https?://#i', $uri) === 1) {
        $path = parse_url($uri, PHP_URL_PATH);
        $query = parse_url($uri, PHP_URL_QUERY);
        $uri = (is_string($path) && $path !== '') ? $path : '/';
        if (is_string($query) && $query !== '') {
            $uri .= '?' . $query;
        }
    }

    return str_starts_with($uri, '/') ? $uri : current_path();
}

function auth_next_path(?string $next, string $default = 'index.php'): string
{
    $fallback = base_url($default);
    $next = trim((string) $next);
    if ($next === '' || str_starts_with($next, '//') || preg_match('#^https?://#i', $next) === 1) {
        return $fallback;
    }

    if (str_starts_with($next, '/')) {
        return $next;
    }

    $normalized = normalize_public_path($next);
    if ($normalized === '' || str_starts_with($normalized, '#')) {
        return $fallback;
    }

    return base_url($normalized);
}

function redirect(string $path): never
{
    $target = str_starts_with($path, '/') ? $path : base_url($path);
    header('Location: ' . $target);
    exit;
}

function app_session_scope_seed(): string
{
    $namespace = trim((string) config('app.session_namespace', ''));
    if ($namespace !== '') {
        return 'namespace:' . $namespace;
    }

    $rootPath = str_replace('\\', '/', (string) (realpath(dirname(__DIR__)) ?: dirname(__DIR__)));
    $baseUrl = strtolower(trim((string) config('app.base_url', ''), '/'));
    $publicUrl = strtolower(trim((string) config('app.public_url', '')));
    $dbHost = strtolower(trim((string) config('db.host', '127.0.0.1')));
    $dbPort = (int) config('db.port', 3306);
    $dbName = strtolower(trim((string) config('db.database', 'demonlist')));

    return implode('|', [
        'base:' . $baseUrl,
        'public:' . $publicUrl,
        'db:' . $dbHost . ':' . $dbPort . '/' . $dbName,
        'path:' . strtolower($rootPath),
    ]);
}

function app_session_scope(): string
{
    static $cache = [];

    $seed = app_session_scope_seed();
    if (!isset($cache[$seed])) {
        $cache[$seed] = substr(hash('sha256', $seed), 0, 32);
    }

    return $cache[$seed];
}

function app_session_key(string $key): string
{
    $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($key));
    $safeKey = is_string($safeKey) && $safeKey !== '' ? $safeKey : 'value';

    return '_dl_' . app_session_scope() . '_' . $safeKey;
}

function flash(string $key, ?string $message = null): ?string
{
    $sessionKey = app_session_key('flash');

    if ($message !== null) {
        $_SESSION[$sessionKey][$key] = $message;
        return null;
    }

    if (!isset($_SESSION[$sessionKey][$key])) {
        return null;
    }

    $output = (string) $_SESSION[$sessionKey][$key];
    unset($_SESSION[$sessionKey][$key]);
    return $output;
}

function csrf_token(): string
{
    $sessionKey = app_session_key('csrf_token');
    if (empty($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION[$sessionKey];
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

function normalize_display_name(string $displayName): string
{
    $collapsed = preg_replace('/\s+/u', ' ', trim($displayName));
    return trim((string) ($collapsed ?? $displayName));
}

function validate_display_name(string $displayName): bool
{
    $normalized = normalize_display_name($displayName);
    if ($normalized === '') {
        return false;
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen($normalized, 'UTF-8')
        : strlen($normalized);

    return $length >= 1 && $length <= 40;
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
    unset($_SESSION['user_id']);

    $userIdKey = app_session_key('user_id');
    $contextKey = app_session_key('auth_context');
    $expectedContext = app_session_scope();
    $storedContext = (string) ($_SESSION[$contextKey] ?? '');
    if ($storedContext === '' || !hash_equals($expectedContext, $storedContext)) {
        unset($_SESSION[$userIdKey], $_SESSION[$contextKey]);
        return null;
    }

    $userId = (int) ($_SESSION[$userIdKey] ?? 0);
    if ($userId < 1) {
        unset($_SESSION[$userIdKey], $_SESSION[$contextKey]);
        return null;
    }

    try {
        $selectFields = [
            'id',
            'username',
            user_select_display_name_expression(),
            'email',
            'country_code',
            'youtube_channel',
            'password_hash',
            'role',
            users_has_comments_disabled_column() ? 'comments_disabled' : '0 AS comments_disabled',
            users_has_discord_link_columns() ? 'discord_user_id' : 'NULL AS discord_user_id',
            users_has_discord_link_columns() ? 'discord_username' : 'NULL AS discord_username',
            users_has_discord_link_columns() ? 'discord_link_pending_user_id' : 'NULL AS discord_link_pending_user_id',
            users_has_discord_link_columns() ? 'discord_link_code_expires_at' : 'NULL AS discord_link_code_expires_at',
            users_has_discord_link_columns() ? 'discord_link_requested_at' : 'NULL AS discord_link_requested_at',
            'points',
            'created_at',
        ];
        $stmt = db()->prepare('SELECT ' . implode(', ', $selectFields) . '
                               FROM users
                               WHERE id = :id
                               LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            unset($_SESSION[$userIdKey], $_SESSION[$contextKey], $_SESSION['user_id']);
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
    return $user !== null ? user_display_name_from_row($user) : null;
}

function user_public_name_by_id(?int $userId, ?string $fallback = null): ?string
{
    static $cache = [];

    $normalizedUserId = (int) ($userId ?? 0);
    if ($normalizedUserId < 1) {
        return $fallback;
    }

    if (array_key_exists($normalizedUserId, $cache)) {
        return $cache[$normalizedUserId] ?? $fallback;
    }

    try {
        $stmt = db()->prepare(
            'SELECT username, ' . user_select_display_name_expression() . '
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $normalizedUserId]);
        $user = $stmt->fetch();
        if ($user === false) {
            $cache[$normalizedUserId] = null;
            return $fallback;
        }

        $cache[$normalizedUserId] = user_display_name_from_row($user);
        return $cache[$normalizedUserId] ?? $fallback;
    } catch (Throwable) {
        return $fallback;
    }
}

function login_user(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    unset($_SESSION['user_id']);
    $_SESSION[app_session_key('auth_context')] = app_session_scope();
    $_SESSION[app_session_key('user_id')] = (int) $user['id'];
}

function logout_user(): void
{
    unset(
        $_SESSION[app_session_key('user_id')],
        $_SESSION[app_session_key('auth_context')],
        $_SESSION['user_id']
    );
}

function require_login(?string $next = null): void
{
    if (is_logged_in()) {
        return;
    }

    $destination = $next;
    if ($destination === null || $destination === '') {
        $destination = current_request_path_with_query();
    }
    $destination = auth_next_path($destination);

    flash('error', 'You need to login before submitting records.');
    redirect('login.php?next=' . rawurlencode($destination));
}

function normalize_user_role(?string $role): string
{
    $normalized = strtolower(trim((string) $role));

    return match ($normalized) {
        'owner' => 'owner',
        'list_editor' => 'list_editor',
        'list_helper' => 'list_helper',
        default => 'player',
    };
}

function role_label(string $role): string
{
    return match (normalize_user_role($role)) {
        'owner' => 'Owner',
        'list_editor' => 'List Editor',
        'list_helper' => 'List Helper',
        default => 'Player',
    };
}

if (!function_exists('pointercrate_beaten_score')) {
    function pointercrate_beaten_score(int $position): float
    {
        return demonlist_beaten_score($position);
    }
}

if (!function_exists('pointercrate_score')) {
    function pointercrate_score(int $position, int $requirement, int $progress): float
    {
        return demonlist_score($position, $requirement, $progress);
    }
}

if (!function_exists('youtube_video_id')) {
    function youtube_video_id(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if (str_contains($host, 'youtube.com') && !empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (!empty($query['v']) && is_string($query['v'])) {
                return trim($query['v']);
            }
        }

        if (str_contains($host, 'youtu.be') && !empty($parts['path'])) {
            return trim((string) $parts['path'], '/');
        }

        return null;
    }
}

function current_user_role(): string
{
    $user = current_user();
    if ($user === null) {
        return 'player';
    }

    return normalize_user_role((string) ($user['role'] ?? 'player'));
}

function has_owner_access(): bool
{
    return current_user_role() === 'owner';
}

function admin_permission_definitions(): array
{
    return [
        'admin_panel_access' => 'Admin Panel Access',
        'manage_levels' => 'Manage Levels',
        'claim_contributors' => 'Claim Contributors',
        'manage_users' => 'Manage Users',
        'manage_user_roles' => 'Manage User Roles',
        'manage_scoring' => 'Change Score',
        'manage_list_visibility' => 'Manage List Visibility',
        'manage_role_permissions' => 'Manage Role Permissions',
        'manage_badges' => 'Manage Badges',
        'moderate_comments' => 'Moderate Comments',
        'reset_passwords' => 'Reset Passwords',
        'review_submissions' => 'Review Submissions',
    ];
}

function admin_permission_keys(): array
{
    return array_keys(admin_permission_definitions());
}

function admin_role_permission_default(string $role, string $permission): bool
{
    $role = normalize_user_role($role);
    $permission = strtolower(trim($permission));

    if ($role === 'owner') {
        return true;
    }

    $defaults = [
        'list_editor' => [
            'admin_panel_access' => true,
            'manage_levels' => true,
            'claim_contributors' => true,
            'manage_users' => false,
            'manage_user_roles' => false,
            'manage_scoring' => true,
            'manage_list_visibility' => false,
            'manage_role_permissions' => false,
            'manage_badges' => false,
            'moderate_comments' => true,
            'reset_passwords' => false,
            'review_submissions' => true,
        ],
        'list_helper' => [
            'admin_panel_access' => true,
            'manage_levels' => false,
            'claim_contributors' => false,
            'manage_users' => false,
            'manage_user_roles' => false,
            'manage_scoring' => false,
            'manage_list_visibility' => false,
            'manage_role_permissions' => false,
            'manage_badges' => false,
            'moderate_comments' => false,
            'reset_passwords' => false,
            'review_submissions' => true,
        ],
    ];

    return (bool) ($defaults[$role][$permission] ?? false);
}

function admin_role_permission_setting_key(string $role, string $permission): string
{
    return 'admin.role_permission.' . normalize_user_role($role) . '.' . strtolower(trim($permission));
}

function admin_role_permission(string $role, string $permission): bool
{
    $role = normalize_user_role($role);
    $permission = strtolower(trim($permission));

    if (!in_array($permission, admin_permission_keys(), true)) {
        return false;
    }

    if ($role === 'owner') {
        return true;
    }

    if (!in_array($role, ['list_editor', 'list_helper'], true)) {
        return false;
    }

    $stored = app_setting_get(admin_role_permission_setting_key($role, $permission), null);
    if ($stored === null) {
        return admin_role_permission_default($role, $permission);
    }

    $normalizedStored = strtolower(trim($stored));
    if (in_array($normalizedStored, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalizedStored, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return admin_role_permission_default($role, $permission);
}

function admin_set_role_permission(string $role, string $permission, bool $allowed): bool
{
    $role = normalize_user_role($role);
    $permission = strtolower(trim($permission));

    if (!in_array($role, ['list_editor', 'list_helper'], true)) {
        return false;
    }
    if (!in_array($permission, admin_permission_keys(), true)) {
        return false;
    }

    return app_setting_set(
        admin_role_permission_setting_key($role, $permission),
        $allowed ? '1' : '0'
    );
}

function admin_role_permissions(string $role): array
{
    $permissions = [];

    foreach (admin_permission_keys() as $permission) {
        $permissions[$permission] = admin_role_permission($role, $permission);
    }

    return $permissions;
}

function current_user_has_permission(string $permission): bool
{
    return admin_role_permission(current_user_role(), $permission);
}

function has_admin_panel_access(): bool
{
    return current_user_has_permission('admin_panel_access');
}

function can_manage_levels(): bool
{
    return current_user_has_permission('manage_levels');
}

function can_claim_contributors(): bool
{
    return current_user_has_permission('claim_contributors');
}

function can_manage_users(): bool
{
    return current_user_has_permission('manage_users');
}

function can_manage_user_roles(): bool
{
    return current_user_has_permission('manage_user_roles');
}

function can_manage_scoring(): bool
{
    return current_user_has_permission('manage_scoring');
}

function can_manage_list_visibility(): bool
{
    return current_user_has_permission('manage_list_visibility');
}

function can_manage_role_permissions(): bool
{
    return current_user_has_permission('manage_role_permissions');
}

function can_manage_badges(): bool
{
    return current_user_has_permission('manage_badges');
}

function can_moderate_level_comments(): bool
{
    return current_user_has_permission('moderate_comments');
}

function can_review_submissions(): bool
{
    return current_user_has_permission('review_submissions');
}

function can_reset_passwords(): bool
{
    return current_user_has_permission('reset_passwords');
}

function is_admin(): bool
{
    return has_admin_panel_access();
}

function require_admin(): void
{
    if (has_admin_panel_access()) {
        return;
    }

    flash('error', 'Admin permission required.');
    redirect('admin.php');
}


