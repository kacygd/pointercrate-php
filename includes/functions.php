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
    } elseif (preg_match('/^(players|guidelines|submit|admin|account|profile|login|logout|register)\.php$/i', $trimmed, $match) === 1) {
        $normalized = strtolower($match[1]);
    } elseif (preg_match('/^(players|guidelines|submit|admin|account|profile|login|logout|register)\.php\?(.+)$/i', $trimmed, $match) === 1) {
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
                setting_value VARCHAR(255) NOT NULL,
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

    $description = 'Demons ranked ' . $firstExtendedRank . '-' . $extendedLimit;
    if ($includeScoreHint) {
        $description .= ' that still count toward score';
    }

    return $description . '.';
}

function demonlist_legacy_list_dropdown_description(): string
{
    $firstLegacyRank = demonlist_extended_list_limit() + 1;
    return 'Demons ranked #' . $firstLegacyRank . '+ or manually marked as legacy.';
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
function discord_normalize_embed(array $embed): array
{
    $normalized = [];

    if (isset($embed['title'])) {
        $normalized['title'] = mb_substr(trim((string) $embed['title']), 0, 256);
    }
    if (isset($embed['description'])) {
        $normalized['description'] = mb_substr(trim((string) $embed['description']), 0, 4096);
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

            $name = mb_substr(trim((string) ($field['name'] ?? '')), 0, 256);
            $value = mb_substr(trim((string) ($field['value'] ?? '')), 0, 1024);
            if ($name === '' || $value === '') {
                continue;
            }

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
        $footerText = mb_substr(trim((string) ($embed['footer']['text'] ?? '')), 0, 2048);
        if ($footerText !== '') {
            $normalized['footer'] = ['text' => $footerText];
        }
    }

    if (isset($embed['author']) && is_array($embed['author'])) {
        $authorName = mb_substr(trim((string) ($embed['author']['name'] ?? '')), 0, 256);
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
        'username' => mb_substr($webhookUsername, 0, 80),
        'content' => mb_substr(trim($content), 0, 1900),
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

            $normalized = discord_normalize_embed($embed);
            if ($normalized === []) {
                continue;
            }

            if (!isset($normalized['footer'])) {
                $normalized['footer'] = ['text' => app_name() . ' Admin Event'];
            }
            if (!isset($normalized['timestamp'])) {
                $normalized['timestamp'] = gmdate('c');
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
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
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
            'timeout' => 8,
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
        'manage_scoring' => 'Change Score',
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
            'manage_scoring' => true,
            'review_submissions' => true,
        ],
        'list_helper' => [
            'admin_panel_access' => true,
            'manage_levels' => false,
            'claim_contributors' => false,
            'manage_users' => false,
            'manage_scoring' => false,
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

function can_manage_scoring(): bool
{
    return current_user_has_permission('manage_scoring');
}

function can_review_submissions(): bool
{
    return current_user_has_permission('review_submissions');
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


