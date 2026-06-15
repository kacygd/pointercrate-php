<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);
$configPath = $rootPath . '/config.php';
if (!is_file($configPath)) {
    $configPath = $rootPath . '/config.example.php';
}

$GLOBALS['app_config_path'] = $configPath;
$GLOBALS['app_config'] = require $configPath;

$timezone = (string) ($GLOBALS['app_config']['app']['timezone'] ?? 'UTC');
date_default_timezone_set($timezone);

require_once $rootPath . '/includes/functions.php';

api_set_common_headers();

if (api_request_method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    api_dispatch();
} catch (Throwable $exception) {
    api_error(
        500,
        (bool) config('app.debug', false) ? $exception->getMessage() : 'Internal Server Error'
    );
}

function api_request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function api_set_common_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('X-Content-Type-Options: nosniff');
}

function api_send(mixed $payload, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        if ($value !== null && $value !== '') {
            header($name . ': ' . $value);
        }
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(int $status, string $message, int $code = 0): never
{
    api_send([
        'message' => $message,
        'code' => $code > 0 ? $code : $status,
    ], $status);
}

function api_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) config('db.host', '127.0.0.1');
    $port = (int) config('db.port', 3306);
    $database = (string) config('db.database', 'demonlist');
    $charset = (string) config('db.charset', 'utf8mb4');
    $username = (string) config('db.username', 'root');
    $password = (string) config('db.password', '');

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function api_dispatch(): never
{
    if (api_request_method() !== 'GET') {
        api_error(405, 'Only GET endpoints are available in this Pointercrate-compatible API.');
    }

    $segments = api_path_segments();

    if ($segments === []) {
        api_send([
            'name' => app_name(),
            'compatibility' => 'pointercrate-readonly',
            'endpoints' => [
                '/api/v2/demons/',
                '/api/v2/demons/listed/',
                '/api/v2/demons/{id}/',
                '/api/listed/',
                '/api/levels/{level_id}',
                '/api/docs',
                '/api/v1/records/',
                '/api/v1/players/',
                '/api/v1/players/ranking/',
                '/api/v1/list_information/',
                '/api/v1/nationalities/ranking/',
            ],
        ]);
    }

    $pdo = api_db();

    if ($segments === ['v1', 'list_information']) {
        api_send(api_list_information($pdo));
    }

    if ($segments === ['listed']) {
        api_send(api_paginate_demons($pdo, true));
    }

    if (count($segments) === 2 && $segments[0] === 'levels' && preg_match('/^\d+$/', $segments[1]) === 1) {
        api_send(api_level_by_level_id($pdo, (string) $segments[1]));
    }

    if (count($segments) >= 2 && $segments[0] === 'v2' && $segments[1] === 'demons') {
        if (count($segments) === 2) {
            api_send(api_paginate_demons($pdo, false));
        }

        if (count($segments) === 3 && $segments[2] === 'listed') {
            api_send(api_paginate_demons($pdo, true));
        }

        if (isset($segments[2]) && preg_match('/^\d+$/', $segments[2]) === 1) {
            $demonId = (int) $segments[2];
            if (count($segments) === 3) {
                api_send(api_full_demon($pdo, $demonId));
            }

            if (count($segments) === 5 && $segments[3] === 'audit' && $segments[4] === 'movement') {
                api_send(api_demon_movement_log($pdo, $demonId));
            }
        }
    }

    if (count($segments) >= 2 && $segments[0] === 'v1' && $segments[1] === 'records') {
        if (count($segments) === 2) {
            api_send(api_paginate_records($pdo));
        }

        if (count($segments) === 3 && preg_match('/^\d+$/', $segments[2]) === 1) {
            api_send(api_full_record($pdo, (int) $segments[2]));
        }
    }

    if (count($segments) >= 2 && $segments[0] === 'v1' && $segments[1] === 'players') {
        if (count($segments) === 2) {
            api_send(api_paginate_players($pdo, false));
        }

        if (count($segments) === 3 && $segments[2] === 'ranking') {
            api_send(api_paginate_players($pdo, true));
        }

        if (count($segments) === 3 && preg_match('/^\d+$/', $segments[2]) === 1) {
            api_send(api_full_player($pdo, (int) $segments[2]));
        }
    }

    if (count($segments) >= 2 && $segments[0] === 'v1' && $segments[1] === 'nationalities') {
        if (count($segments) === 3 && $segments[2] === 'ranking') {
            api_send(api_nationality_ranking($pdo));
        }

        if (count($segments) === 3) {
            api_send(api_nationality_record($pdo, $segments[2]));
        }
    }

    api_error(404, 'API endpoint not found.');
}

function api_path_segments(): array
{
    $uriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($scriptDir !== '' && str_starts_with($uriPath, $scriptDir)) {
        $path = substr($uriPath, strlen($scriptDir));
    } else {
        $apiPos = strpos($uriPath, '/api');
        $path = $apiPos === false ? $uriPath : substr($uriPath, $apiPos + 4);
    }

    $path = trim((string) $path, '/');
    if ($path === '' || $path === 'index.php') {
        return [];
    }

    return array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
}

function api_schema_col_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column"
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    $cache[$key] = (int) $stmt->fetchColumn() > 0;
    return $cache[$key];
}

function api_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table"
    );
    $stmt->execute([':table' => $table]);

    $cache[$table] = (int) $stmt->fetchColumn() > 0;
    return $cache[$table];
}

function api_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    if (!api_table_exists($pdo, 'app_settings')) {
        return $default;
    }

    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();

    return is_string($value) ? $value : $default;
}

function api_setting_int(PDO $pdo, string $key, int $default): int
{
    $value = trim((string) api_setting($pdo, $key, (string) $default));
    return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
}

function api_setting_bool(PDO $pdo, string $key, bool $default): bool
{
    $value = api_setting($pdo, $key, $default ? '1' : '0');
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function api_main_limit(PDO $pdo): int
{
    return max(1, min(10000, api_setting_int($pdo, 'list.main_max_rank', 75)));
}

function api_extended_limit(PDO $pdo): int
{
    $mainLimit = api_main_limit($pdo);
    return max($mainLimit, min(10000, api_setting_int($pdo, 'list.extended_max_rank', 150)));
}

function api_list_bucket(PDO $pdo, int $position, bool $legacy): string
{
    if ($position < 1) {
        return 'legacy';
    }

    if ($legacy) {
        return api_setting_bool($pdo, 'list.show_legacy', true) ? 'legacy' : 'main';
    }

    if ($position <= api_main_limit($pdo)) {
        return 'main';
    }

    if ($position <= api_extended_limit($pdo)) {
        return api_setting_bool($pdo, 'list.show_extended', true) ? 'extended' : 'main';
    }

    return api_setting_bool($pdo, 'list.show_legacy', true) ? 'legacy' : 'main';
}

function api_is_ranked_entry(PDO $pdo, int $position, bool $legacy): bool
{
    return $position > 0 && api_list_bucket($pdo, $position, $legacy) !== 'legacy';
}

function api_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }

    $value = is_array($_GET[$key]) ? reset($_GET[$key]) : $_GET[$key];
    return preg_match('/^-?\d+$/', (string) $value) === 1 ? (int) $value : null;
}

function api_query_string(string $key): ?string
{
    if (!array_key_exists($key, $_GET)) {
        return null;
    }

    $value = is_array($_GET[$key]) ? reset($_GET[$key]) : $_GET[$key];
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function api_query_bool(string $key): ?bool
{
    $value = api_query_string($key);
    if ($value === null) {
        return null;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function api_limit(): int
{
    $limit = api_query_int('limit') ?? 50;
    if ($limit < 1 || $limit > 100) {
        api_error(422, 'Invalid pagination limit. Use a value from 1 to 100.');
    }

    return $limit;
}

function api_bind(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function api_paginated_sql(PDO $pdo, string $sql, array $params, string $pageColumn, callable $mapper): array
{
    $before = api_query_int('before');
    $after = api_query_int('after');
    $limit = api_limit();
    $order = ($before !== null && $after === null) ? 'DESC' : 'ASC';

    if ($before !== null) {
        $sql .= ' AND ' . $pageColumn . ' < :before';
        $params[':before'] = $before;
    }

    if ($after !== null) {
        $sql .= ' AND ' . $pageColumn . ' > :after';
        $params[':after'] = $after;
    }

    $sql .= ' ORDER BY ' . $pageColumn . ' ' . $order . ' LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    api_bind($stmt, $params);
    $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    if ($before !== null && $after === null) {
        $rows = array_reverse($rows);
    }

    return array_map($mapper, $rows);
}

function api_paginated_array(array $items, callable $idResolver): array
{
    $before = api_query_int('before');
    $after = api_query_int('after');
    $limit = api_limit();
    $beforeOnly = $before !== null && $after === null;

    $items = array_values(array_filter($items, static function (array $item) use ($idResolver, $before, $after): bool {
        $id = (int) $idResolver($item);
        if ($before !== null && $id >= $before) {
            return false;
        }
        if ($after !== null && $id <= $after) {
            return false;
        }

        return true;
    }));

    usort($items, static function (array $a, array $b) use ($idResolver, $beforeOnly): int {
        $result = (int) $idResolver($a) <=> (int) $idResolver($b);
        return $beforeOnly ? -$result : $result;
    });

    $items = array_slice($items, 0, $limit);

    return $beforeOnly ? array_reverse($items) : $items;
}

function api_demon_sql_parts(PDO $pdo): array
{
    $hasPublisherUser = api_schema_col_exists($pdo, 'demons', 'publisher_user_id');
    $hasVerifierUser = api_schema_col_exists($pdo, 'demons', 'verifier_user_id');
    $hasDisplayName = api_schema_col_exists($pdo, 'users', 'display_name');
    $hasIsBanned = api_schema_col_exists($pdo, 'users', 'is_banned');

    $publisherName = $hasDisplayName
        ? "COALESCE(NULLIF(pu.display_name, ''), pu.username)"
        : 'pu.username';
    $verifierName = $hasDisplayName
        ? "COALESCE(NULLIF(vu.display_name, ''), vu.username)"
        : 'vu.username';
    $publisherBanned = $hasIsBanned ? 'COALESCE(pu.is_banned, 0)' : '0';
    $verifierBanned = $hasIsBanned ? 'COALESCE(vu.is_banned, 0)' : '0';

    $select = 'd.*';
    $joins = '';

    if ($hasPublisherUser) {
        $select .= ", d.publisher_user_id AS publisher_user_id_resolved, {$publisherName} AS publisher_account_name, {$publisherBanned} AS publisher_banned";
        $joins .= ' LEFT JOIN users pu ON pu.id = d.publisher_user_id';
    } else {
        $select .= ', NULL AS publisher_user_id_resolved, NULL AS publisher_account_name, 0 AS publisher_banned';
    }

    if ($hasVerifierUser) {
        $select .= ", d.verifier_user_id AS verifier_user_id_resolved, {$verifierName} AS verifier_account_name, {$verifierBanned} AS verifier_banned";
        $joins .= ' LEFT JOIN users vu ON vu.id = d.verifier_user_id';
    } else {
        $select .= ', NULL AS verifier_user_id_resolved, NULL AS verifier_account_name, 0 AS verifier_banned';
    }

    return [
        'select' => $select,
        'from' => ' FROM demons d' . $joins,
    ];
}

function api_paginate_demons(PDO $pdo, bool $listed): array
{
    $parts = api_demon_sql_parts($pdo);
    $where = ['1 = 1'];
    $params = [];

    if ($listed) {
        $where[] = 'd.position > 0';
    }

    $name = api_query_string('name');
    if ($name !== null) {
        $where[] = 'LOWER(d.name) = LOWER(:name)';
        $params[':name'] = $name;
    }

    $nameContains = api_query_string('name_contains');
    if ($nameContains !== null) {
        $where[] = 'LOWER(d.name) LIKE LOWER(:name_contains)';
        $params[':name_contains'] = '%' . $nameContains . '%';
    }

    foreach (['requirement', 'requirement__gt', 'requirement__lt'] as $filter) {
        $value = api_query_int($filter);
        if ($value === null) {
            continue;
        }

        $operator = $filter === 'requirement__gt' ? '>' : ($filter === 'requirement__lt' ? '<' : '=');
        $param = ':' . str_replace('__', '_', $filter);
        $where[] = 'd.requirement ' . $operator . ' ' . $param;
        $params[$param] = $value;
    }

    $levelId = api_query_string('level_id');
    if ($levelId !== null) {
        $where[] = 'd.level_id = :level_id';
        $params[':level_id'] = $levelId;
    }

    $publisherName = api_query_string('publisher_name');
    if ($publisherName !== null) {
        $where[] = 'LOWER(d.publisher) = LOWER(:publisher_name)';
        $params[':publisher_name'] = $publisherName;
    }

    $verifierName = api_query_string('verifier_name');
    if ($verifierName !== null) {
        $where[] = 'LOWER(d.verifier) = LOWER(:verifier_name)';
        $params[':verifier_name'] = $verifierName;
    }

    $sql = 'SELECT ' . $parts['select'] . $parts['from'] . ' WHERE ' . implode(' AND ', $where);
    $pageColumn = $listed ? 'd.position' : 'd.id';

    return api_paginated_sql($pdo, $sql, $params, $pageColumn, static fn(array $row): array => api_format_demon($row));
}

function api_full_demon(PDO $pdo, int $demonId): array
{
    $parts = api_demon_sql_parts($pdo);
    $stmt = $pdo->prepare('SELECT ' . $parts['select'] . $parts['from'] . ' WHERE d.id = :id LIMIT 1');
    $stmt->execute([':id' => $demonId]);
    $row = $stmt->fetch();

    if ($row === false) {
        api_error(404, 'Demon not found.');
    }

    $demon = api_format_demon($row);
    $demon['creators'] = array_map(
        static fn(string $name): array => api_player_for_name($name),
        api_creator_names($row)
    );
    $demon['records'] = api_records_for_demon($pdo, (int) $row['id']);

    return $demon;
}

function api_level_by_level_id(PDO $pdo, string $levelId): array
{
    $parts = api_demon_sql_parts($pdo);
    $stmt = $pdo->prepare('SELECT ' . $parts['select'] . $parts['from'] . ' WHERE d.level_id = :level_id LIMIT 1');
    $stmt->execute([':level_id' => $levelId]);
    $row = $stmt->fetch();

    if ($row === false) {
        api_error(404, 'Level not found.');
    }

    $demon = api_format_demon($row);
    $creators = api_creator_names($row);

    return [
        'id' => (int) $row['id'],
        'level_id' => preg_match('/^\d+$/', $levelId) === 1 ? (int) $levelId : $levelId,
        'position' => (int) $row['position'],
        'name' => (string) $row['name'],
        'requirement' => (int) ($row['requirement'] ?? 100),
        'video' => $demon['video'],
        'thumbnail' => $demon['thumbnail'],
        'publisher' => $demon['publisher'],
        'verifier' => $demon['verifier'],
        'creators' => array_map(static fn(string $name): array => api_player_for_name($name), $creators),
    ];
}

function api_demon_movement_log(PDO $pdo, int $demonId): array
{
    if (!api_table_exists($pdo, 'demon_position_history')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, old_position, new_position, note, created_at
         FROM demon_position_history
         WHERE demon_id = :id
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([':id' => $demonId]);

    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'time' => api_iso_time((string) $row['created_at']),
        'old_position' => $row['old_position'] !== null ? (int) $row['old_position'] : null,
        'new_position' => (int) $row['new_position'],
        'note' => $row['note'] !== null ? (string) $row['note'] : null,
    ], $stmt->fetchAll());
}

function api_format_demon(array $row): array
{
    $levelId = trim((string) ($row['level_id'] ?? ''));

    return [
        'id' => (int) $row['id'],
        'position' => (int) $row['position'],
        'name' => (string) $row['name'],
        'requirement' => (int) ($row['requirement'] ?? 100),
        'video' => api_nullable_url($row['video_url'] ?? null),
        'thumbnail' => (string) ($row['thumbnail_url'] ?? ''),
        'publisher' => api_player_for_name(
            (string) ($row['publisher_account_name'] ?: ($row['publisher'] ?? 'Unknown')),
            (int) ($row['publisher_user_id_resolved'] ?? 0),
            (bool) ((int) ($row['publisher_banned'] ?? 0))
        ),
        'verifier' => api_player_for_name(
            (string) ($row['verifier_account_name'] ?: ($row['verifier'] ?? 'Unknown')),
            (int) ($row['verifier_user_id_resolved'] ?? 0),
            (bool) ((int) ($row['verifier_banned'] ?? 0))
        ),
        'level_id' => preg_match('/^\d+$/', $levelId) === 1 ? (int) $levelId : null,
    ];
}

function api_minimal_demon(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'position' => (int) $row['position'],
        'name' => (string) $row['name'],
    ];
}

function api_creator_names(array $demon): array
{
    $names = function_exists('demon_creator_names') ? demon_creator_names($demon) : [];
    if ($names !== []) {
        return $names;
    }

    $publisher = trim((string) ($demon['publisher'] ?? ''));
    return $publisher !== '' ? [$publisher] : [];
}

function api_nullable_url(mixed $value): ?string
{
    $url = trim((string) ($value ?? ''));
    return $url === '' ? null : $url;
}

function api_iso_time(string $value): string
{
    $time = strtotime($value);
    return gmdate('c', $time !== false ? $time : time());
}

function api_player_for_name(string $name, ?int $preferredId = null, bool $banned = false): array
{
    $name = trim($name);
    if ($name === '') {
        $name = 'Unknown';
    }

    $user = api_user_by_name_or_id($name, $preferredId);
    if ($user !== null) {
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['display_name'],
            'banned' => (bool) $user['banned'],
        ];
    }

    return [
        'id' => api_pseudo_player_id($name),
        'name' => $name,
        'banned' => $banned,
    ];
}

function api_pseudo_player_id(string $name): int
{
    $hash = (int) (sprintf('%u', crc32(strtolower(trim($name)))) % 900000000);
    return 100000000 + $hash;
}

function api_user_index(): array
{
    static $loaded = false;
    static $byId = [];
    static $byName = [];

    if (!$loaded) {
        $loaded = true;
        $pdo = api_db();
        $hasDisplayName = api_schema_col_exists($pdo, 'users', 'display_name');
        $hasIsBanned = api_schema_col_exists($pdo, 'users', 'is_banned');
        $hasCountry = api_schema_col_exists($pdo, 'users', 'country_code');
        $hasPoints = api_schema_col_exists($pdo, 'users', 'points');
        $hasBonus = api_schema_col_exists($pdo, 'users', 'bonus_points');

        $displayExpr = $hasDisplayName
            ? "COALESCE(NULLIF(display_name, ''), username)"
            : 'username';
        $bannedExpr = $hasIsBanned ? 'is_banned' : '0';
        $countryExpr = $hasCountry ? 'country_code' : 'NULL';
        $pointsExpr = $hasPoints ? 'points' : '0';
        $bonusExpr = $hasBonus ? 'bonus_points' : '0';

        $users = $pdo->query(
            "SELECT id, username, {$displayExpr} AS display_name, {$bannedExpr} AS is_banned,
                    {$countryExpr} AS country_code, {$pointsExpr} AS points, {$bonusExpr} AS bonus_points
             FROM users"
        )->fetchAll();

        foreach ($users as $user) {
            $normalized = [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'display_name' => (string) $user['display_name'],
                'banned' => (int) ($user['is_banned'] ?? 0) === 1,
                'country_code' => api_normalize_country_code((string) ($user['country_code'] ?? '')),
                'points' => (float) ($user['points'] ?? 0),
                'bonus_points' => (float) ($user['bonus_points'] ?? 0),
            ];
            $byId[$normalized['id']] = $normalized;
            $byName[strtolower($normalized['username'])] = $normalized;
            $byName[strtolower($normalized['display_name'])] = $normalized;
        }
    }

    return [
        'by_id' => $byId,
        'by_name' => $byName,
    ];
}

function api_user_by_name_or_id(string $name, ?int $id = null): ?array
{
    $index = api_user_index();

    if ($id !== null && $id > 0 && isset($index['by_id'][$id])) {
        return $index['by_id'][$id];
    }

    $key = strtolower(trim($name));
    return $index['by_name'][$key] ?? null;
}

function api_users(): array
{
    $index = api_user_index();
    return array_values($index['by_id']);
}

function api_normalize_country_code(string $code): ?string
{
    $code = strtoupper(trim($code));
    return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
}

function api_country_names(): array
{
    static $names = null;
    if (is_array($names)) {
        return $names;
    }

    $path = dirname(__DIR__) . '/includes/country_names.php';
    $loaded = is_file($path) ? require $path : [];
    $names = is_array($loaded) ? $loaded : [];

    return $names;
}

function api_nationality(?string $countryCode): ?array
{
    $countryCode = $countryCode !== null ? api_normalize_country_code($countryCode) : null;
    if ($countryCode === null) {
        return null;
    }

    $names = api_country_names();

    return [
        'iso_country_code' => $countryCode,
        'nation' => $names[$countryCode] ?? $countryCode,
        'subdivision' => null,
    ];
}

function api_score(PDO $pdo, int $position, int $requirement, int $progress, bool $legacy): float
{
    if (!api_is_ranked_entry($pdo, $position, $legacy) || $progress < $requirement) {
        return 0.0;
    }

    if (function_exists('demonlist_score')) {
        return (float) demonlist_score($position, $requirement, $progress);
    }

    return $progress >= 100 ? max(1, 151 - $position) : max(0, 151 - $position) * ($progress / 100);
}

function api_records_for_demon(PDO $pdo, int $demonId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.progress, c.video_url, c.player
         FROM completions c
         WHERE c.demon_id = :id
         ORDER BY c.progress DESC, c.created_at ASC, c.id ASC'
    );
    $stmt->execute([':id' => $demonId]);

    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'progress' => (int) $row['progress'],
        'video' => api_nullable_url($row['video_url'] ?? null),
        'status' => 'approved',
        'player' => api_player_for_name((string) $row['player']),
        'nationality' => api_nationality(api_user_by_name_or_id((string) $row['player'])['country_code'] ?? null),
    ], $stmt->fetchAll());
}

function api_paginate_records(PDO $pdo): array
{
    $where = ['1 = 1'];
    $params = [];

    $status = api_query_string('status');
    if ($status !== null && strtolower($status) !== 'approved') {
        return [];
    }

    foreach (['progress', 'progress__gt', 'progress__lt'] as $filter) {
        $value = api_query_int($filter);
        if ($value === null) {
            continue;
        }

        $operator = $filter === 'progress__gt' ? '>' : ($filter === 'progress__lt' ? '<' : '=');
        $param = ':' . str_replace('__', '_', $filter);
        $where[] = 'c.progress ' . $operator . ' ' . $param;
        $params[$param] = $value;
    }

    foreach (['demon_position', 'demon_position__gt', 'demon_position__lt'] as $filter) {
        $value = api_query_int($filter);
        if ($value === null) {
            continue;
        }

        $operator = $filter === 'demon_position__gt' ? '>' : ($filter === 'demon_position__lt' ? '<' : '=');
        $param = ':' . str_replace('__', '_', $filter);
        $where[] = 'd.position ' . $operator . ' ' . $param;
        $params[$param] = $value;
    }

    $demonName = api_query_string('demon');
    if ($demonName !== null) {
        $where[] = 'LOWER(d.name) = LOWER(:demon)';
        $params[':demon'] = $demonName;
    }

    $demonId = api_query_int('demon_id');
    if ($demonId !== null) {
        $where[] = 'd.id = :demon_id';
        $params[':demon_id'] = $demonId;
    }

    $video = api_query_string('video');
    if ($video !== null) {
        $where[] = 'c.video_url = :video';
        $params[':video'] = $video;
    }

    $sql = 'SELECT c.id, c.progress, c.video_url, c.player,
                   d.id AS demon_id, d.name AS demon_name, d.position
            FROM completions c
            INNER JOIN demons d ON d.id = c.demon_id
            WHERE ' . implode(' AND ', $where);

    return api_paginated_sql($pdo, $sql, $params, 'c.id', static fn(array $row): array => [
        'id' => (int) $row['id'],
        'progress' => (int) $row['progress'],
        'video' => api_nullable_url($row['video_url'] ?? null),
        'status' => 'approved',
        'demon' => [
            'id' => (int) $row['demon_id'],
            'position' => (int) $row['position'],
            'name' => (string) $row['demon_name'],
        ],
        'player' => api_player_for_name((string) $row['player']),
    ]);
}

function api_full_record(PDO $pdo, int $recordId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id, c.progress, c.video_url, c.player,
                d.id AS demon_id, d.name AS demon_name, d.position
         FROM completions c
         INNER JOIN demons d ON d.id = c.demon_id
         WHERE c.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $recordId]);
    $row = $stmt->fetch();

    if ($row === false) {
        api_error(404, 'Record not found.');
    }

    return [
        'id' => (int) $row['id'],
        'progress' => (int) $row['progress'],
        'video' => api_nullable_url($row['video_url'] ?? null),
        'status' => 'approved',
        'player' => api_player_for_name((string) $row['player']),
        'demon' => [
            'id' => (int) $row['demon_id'],
            'position' => (int) $row['position'],
            'name' => (string) $row['demon_name'],
        ],
        'submitter' => null,
        'raw_footage' => null,
    ];
}

function api_build_players(PDO $pdo): array
{
    $players = [];

    $ensure = static function (string $name, ?array $user = null) use (&$players): string {
        $name = trim($name);
        if ($name === '') {
            $name = 'Unknown';
        }

        $key = strtolower($user['username'] ?? $name);
        if (!isset($players[$key])) {
            $player = api_player_for_name($name, isset($user['id']) ? (int) $user['id'] : null);
            $players[$key] = [
                'id' => (int) $player['id'],
                'name' => (string) $player['name'],
                'banned' => (bool) $player['banned'],
                'score' => 0.0,
                'rank' => null,
                'nationality' => api_nationality($user['country_code'] ?? null),
                'records' => [],
                'created' => [],
                'verified' => [],
                'published' => [],
                '_completion_scores' => [],
            ];
        }

        if ($user !== null) {
            $players[$key]['id'] = (int) $user['id'];
            $players[$key]['name'] = (string) $user['display_name'];
            $players[$key]['banned'] = (bool) $user['banned'];
            $players[$key]['nationality'] = api_nationality($user['country_code'] ?? null);
        }

        return $key;
    };

    foreach (api_users() as $user) {
        $ensure((string) $user['username'], $user);
    }

    $demonRows = $pdo->query(
        'SELECT id, position, name, requirement, legacy, creator, creator_more, publisher, verifier,
                ' . (api_schema_col_exists($pdo, 'demons', 'publisher_user_id') ? 'publisher_user_id' : 'NULL AS publisher_user_id') . ',
                ' . (api_schema_col_exists($pdo, 'demons', 'verifier_user_id') ? 'verifier_user_id' : 'NULL AS verifier_user_id') . '
         FROM demons
         ORDER BY position ASC'
    )->fetchAll();

    $demonsById = [];
    foreach ($demonRows as $demon) {
        $demonId = (int) $demon['id'];
        $demonsById[$demonId] = $demon;
        $minimal = api_minimal_demon($demon);

        foreach (api_creator_names($demon) as $creator) {
            $players[$ensure($creator)]['created'][$demonId] = $minimal;
        }

        $publisherUser = api_user_by_name_or_id((string) ($demon['publisher'] ?? ''), (int) ($demon['publisher_user_id'] ?? 0));
        $publisherKey = $ensure((string) ($publisherUser['username'] ?? ($demon['publisher'] ?? '')), $publisherUser);
        $players[$publisherKey]['published'][$demonId] = $minimal;

        $verifierUser = api_user_by_name_or_id((string) ($demon['verifier'] ?? ''), (int) ($demon['verifier_user_id'] ?? 0));
        $verifierKey = $ensure((string) ($verifierUser['username'] ?? ($demon['verifier'] ?? '')), $verifierUser);
        $players[$verifierKey]['verified'][$demonId] = $minimal;
    }

    $records = $pdo->query(
        'SELECT c.id, c.demon_id, c.player, c.video_url, c.progress
         FROM completions c
         ORDER BY c.created_at DESC, c.id DESC'
    )->fetchAll();

    foreach ($records as $record) {
        $demonId = (int) $record['demon_id'];
        if (!isset($demonsById[$demonId])) {
            continue;
        }

        $demon = $demonsById[$demonId];
        $key = $ensure((string) $record['player']);
        $progress = (int) $record['progress'];
        $position = (int) $demon['position'];
        $requirement = (int) $demon['requirement'];
        $legacy = (int) ($demon['legacy'] ?? 0) === 1;
        $score = api_score($pdo, $position, $requirement, $progress, $legacy);

        $players[$key]['score'] += $score;
        $players[$key]['_completion_scores'][$demonId] = max((float) ($players[$key]['_completion_scores'][$demonId] ?? 0), $score);
        $players[$key]['records'][] = [
            'id' => (int) $record['id'],
            'progress' => $progress,
            'video' => api_nullable_url($record['video_url'] ?? null),
            'status' => 'approved',
            'demon' => api_minimal_demon($demon),
        ];
    }

    foreach ($players as &$player) {
        foreach ($player['verified'] as $demonId => $demon) {
            if (!isset($demonsById[$demonId])) {
                continue;
            }

            $source = $demonsById[$demonId];
            $position = (int) $source['position'];
            $requirement = (int) $source['requirement'];
            $legacy = (int) ($source['legacy'] ?? 0) === 1;
            $fullScore = api_score($pdo, $position, $requirement, 100, $legacy);
            $completionScore = (float) ($player['_completion_scores'][$demonId] ?? 0);
            if ($fullScore > $completionScore) {
                $player['score'] += $fullScore - $completionScore;
            }
        }

        $user = api_user_by_name_or_id((string) $player['name'], (int) $player['id']);
        if ($user !== null) {
            $player['score'] += (float) ($user['bonus_points'] ?? 0);
        }

        $player['score'] = round((float) $player['score'], 2);
        $player['created'] = array_values($player['created']);
        $player['verified'] = array_values($player['verified']);
        $player['published'] = array_values($player['published']);
        unset($player['_completion_scores']);
    }
    unset($player);

    $rankable = array_values(array_filter($players, static fn(array $player): bool => !$player['banned'] && (float) $player['score'] > 0));
    usort($rankable, static fn(array $a, array $b): int => ((float) $b['score'] <=> (float) $a['score']) ?: strcasecmp((string) $a['name'], (string) $b['name']));

    $rank = 1;
    foreach ($rankable as $ranked) {
        foreach ($players as &$player) {
            if ((int) $player['id'] === (int) $ranked['id']) {
                $player['rank'] = $rank;
                break;
            }
        }
        unset($player);
        $rank++;
    }

    return array_values($players);
}

function api_paginate_players(PDO $pdo, bool $ranking): array
{
    $players = api_build_players($pdo);

    $name = api_query_string('name');
    $nameContains = api_query_string('name_contains');
    $banned = api_query_bool('banned');
    $nation = api_query_string('nation');

    $players = array_values(array_filter($players, static function (array $player) use ($ranking, $name, $nameContains, $banned, $nation): bool {
        if ($ranking && ((float) $player['score'] <= 0 || (bool) $player['banned'])) {
            return false;
        }
        if ($name !== null && strcasecmp((string) $player['name'], $name) !== 0) {
            return false;
        }
        if ($nameContains !== null && stripos((string) $player['name'], $nameContains) === false) {
            return false;
        }
        if ($banned !== null && (bool) $player['banned'] !== $banned) {
            return false;
        }
        if ($nation !== null) {
            $code = $player['nationality']['iso_country_code'] ?? null;
            $nationName = $player['nationality']['nation'] ?? null;
            if (strcasecmp((string) $code, $nation) !== 0 && strcasecmp((string) $nationName, $nation) !== 0) {
                return false;
            }
        }

        return true;
    }));

    if ($ranking) {
        usort($players, static fn(array $a, array $b): int => ((int) ($a['rank'] ?? PHP_INT_MAX) <=> (int) ($b['rank'] ?? PHP_INT_MAX)));
        $index = 1;
        foreach ($players as &$player) {
            $player['_page_index'] = $index++;
        }
        unset($player);

        return array_map('api_public_player', api_paginated_array($players, static fn(array $player): int => (int) $player['_page_index']));
    }

    return array_map('api_public_player', api_paginated_array($players, static fn(array $player): int => (int) $player['id']));
}

function api_full_player(PDO $pdo, int $playerId): array
{
    foreach (api_build_players($pdo) as $player) {
        if ((int) $player['id'] === $playerId) {
            return api_public_player($player, true);
        }
    }

    api_error(404, 'Player not found.');
}

function api_public_player(array $player, bool $full = false): array
{
    $public = [
        'id' => (int) $player['id'],
        'name' => (string) $player['name'],
        'banned' => (bool) $player['banned'],
        'score' => (float) $player['score'],
        'rank' => $player['rank'] !== null ? (int) $player['rank'] : null,
        'nationality' => $player['nationality'] ?? null,
    ];

    if ($full) {
        $public['records'] = $player['records'];
        $public['created'] = $player['created'];
        $public['verified'] = $player['verified'];
        $public['published'] = $player['published'];
    }

    return $public;
}

function api_nationality_ranking(PDO $pdo): array
{
    $scores = [];
    foreach (api_build_players($pdo) as $player) {
        $nationality = $player['nationality'] ?? null;
        if ($nationality === null || (float) $player['score'] <= 0 || (bool) $player['banned']) {
            continue;
        }

        $code = (string) $nationality['iso_country_code'];
        if (!isset($scores[$code])) {
            $scores[$code] = [
                'iso_country_code' => $code,
                'nation' => (string) $nationality['nation'],
                'score' => 0.0,
                'rank' => null,
            ];
        }

        $scores[$code]['score'] += (float) $player['score'];
    }

    $ranking = array_values($scores);
    usort($ranking, static fn(array $a, array $b): int => ((float) $b['score'] <=> (float) $a['score']) ?: strcmp((string) $a['nation'], (string) $b['nation']));

    foreach ($ranking as $index => &$nation) {
        $nation['score'] = round((float) $nation['score'], 2);
        $nation['rank'] = $index + 1;
    }
    unset($nation);

    return api_paginated_array($ranking, static fn(array $nation): int => (int) $nation['rank']);
}

function api_nationality_record(PDO $pdo, string $code): array
{
    $nationality = api_nationality($code);
    if ($nationality === null) {
        api_error(404, 'Nationality not found.');
    }

    $players = array_values(array_filter(api_build_players($pdo), static function (array $player) use ($nationality): bool {
        return (($player['nationality']['iso_country_code'] ?? null) === $nationality['iso_country_code']);
    }));

    usort($players, static fn(array $a, array $b): int => ((float) $b['score'] <=> (float) $a['score']) ?: strcasecmp((string) $a['name'], (string) $b['name']));

    return [
        'iso_country_code' => $nationality['iso_country_code'],
        'nation' => $nationality['nation'],
        'subdivision' => null,
        'players' => array_map('api_public_player', $players),
    ];
}

function api_list_information(PDO $pdo): array
{
    $totalDemons = (int) $pdo->query('SELECT COUNT(*) FROM demons')->fetchColumn();
    $listedDemons = (int) $pdo->query('SELECT COUNT(*) FROM demons WHERE position > 0')->fetchColumn();

    return [
        'name' => app_name(),
        'list_size' => api_main_limit($pdo),
        'extended_list_size' => api_extended_limit($pdo),
        'total_demons' => $totalDemons,
        'listed_demons' => $listedDemons,
    ];
}
