<?php
declare(strict_types=1);

$rootPath = dirname(__DIR__);
$configPath = $rootPath . '/config.php';
if (!is_file($configPath)) {
    $configPath = $rootPath . '/config.example.php';
}

$GLOBALS['app_config_path'] = $configPath;
$GLOBALS['app_config'] = require $configPath;

require_once $rootPath . '/includes/functions.php';

$apiBase = rtrim(absolute_url('api'), '/');
$siteBase = rtrim(absolute_url(''), '/');
$appName = app_name();
$sampleDemon = docs_sample_demon();
$sampleDemonId = (int) ($sampleDemon['id'] ?? 1);
$sampleLevelId = trim((string) ($sampleDemon['level_id'] ?? ''));
$sampleName = trim((string) ($sampleDemon['name'] ?? 'Level Name'));
$sampleNameQuery = rawurlencode($sampleName !== '' ? $sampleName : 'Level Name');
$sampleLevelPath = $sampleLevelId !== '' ? $sampleLevelId : '{level_id}';
$sampleDemonJson = docs_pretty_json(docs_sample_demon_object($sampleDemon));
$sampleLevelJson = docs_pretty_json(docs_sample_level_object($sampleDemon));

$endpoints = [
    [
        'method' => 'GET',
        'path' => '/listed/',
        'summary' => 'Short listed demons endpoint. Same response format as Pointercrate listed demons.',
        'query' => 'name, name_contains, level_id, requirement, requirement__gt, requirement__lt, publisher_name, verifier_name, before, after, limit',
        'example' => $sampleLevelId !== '' ? $apiBase . '/listed/?level_id=' . rawurlencode($sampleLevelId) : $apiBase . '/listed/?limit=1',
    ],
    [
        'method' => 'GET',
        'path' => '/levels/{level_id}',
        'summary' => 'Level ID lookup for game mods. Returns one JSON object with position.',
        'query' => 'Extra query parameters are ignored.',
        'example' => $apiBase . '/levels/' . rawurlencode($sampleLevelPath),
    ],
    [
        'method' => 'GET',
        'path' => '/v2/demons/listed/',
        'summary' => 'Pointercrate-compatible listed demons endpoint ordered by position.',
        'query' => 'name, name_contains, level_id, requirement, requirement__gt, requirement__lt, publisher_name, verifier_name, before, after, limit',
        'example' => $apiBase . '/v2/demons/listed/?name=' . $sampleNameQuery,
    ],
    [
        'method' => 'GET',
        'path' => '/v2/demons/',
        'summary' => 'All demons ordered by internal ID.',
        'query' => 'name, name_contains, level_id, before, after, limit',
        'example' => $apiBase . '/v2/demons/?limit=10',
    ],
    [
        'method' => 'GET',
        'path' => '/v2/demons/{id}/',
        'summary' => 'Full demon object with creators and records.',
        'query' => 'None',
        'example' => $apiBase . '/v2/demons/' . $sampleDemonId . '/',
    ],
    [
        'method' => 'GET',
        'path' => '/v1/records/',
        'summary' => 'Approved records/completions.',
        'query' => 'progress, progress__gt, progress__lt, demon, demon_id, demon_position, demon_position__gt, demon_position__lt, video, before, after, limit',
        'example' => $apiBase . '/v1/records/?demon_id=' . $sampleDemonId,
    ],
    [
        'method' => 'GET',
        'path' => '/v1/players/',
        'summary' => 'Players known to the list.',
        'query' => 'name, name_contains, banned, nation, before, after, limit',
        'example' => $apiBase . '/v1/players/?limit=10',
    ],
    [
        'method' => 'GET',
        'path' => '/v1/players/ranking/',
        'summary' => 'Stats Viewer ranking ordered by score.',
        'query' => 'name_contains, nation, before, after, limit',
        'example' => $apiBase . '/v1/players/ranking/?limit=10',
    ],
    [
        'method' => 'GET',
        'path' => '/v1/list_information/',
        'summary' => 'List size and total listed demon count.',
        'query' => 'None',
        'example' => $apiBase . '/v1/list_information/',
    ],
    [
        'method' => 'GET',
        'path' => '/v1/nationalities/ranking/',
        'summary' => 'Country ranking by total score.',
        'query' => 'before, after, limit',
        'example' => $apiBase . '/v1/nationalities/ranking/',
    ],
];

function docs_db(): ?PDO
{
    static $pdo = null;
    static $attempted = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($attempted) {
        return null;
    }

    $attempted = true;

    try {
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
    } catch (Throwable) {
        $pdo = null;
    }

    return $pdo;
}

function docs_sample_demon(): array
{
    $pdo = docs_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        $stmt = $pdo->query(
            "SELECT id, position, name, requirement, video_url, thumbnail_url, publisher, verifier, creator, creator_more, level_id
             FROM demons
             WHERE level_id IS NOT NULL
               AND TRIM(level_id) <> ''
             ORDER BY position ASC
             LIMIT 1"
        );
        $row = $stmt !== false ? $stmt->fetch() : false;
        if (is_array($row)) {
            return $row;
        }

        $stmt = $pdo->query(
            'SELECT id, position, name, requirement, video_url, thumbnail_url, publisher, verifier, creator, creator_more, level_id
             FROM demons
             ORDER BY position ASC
             LIMIT 1'
        );
        $row = $stmt !== false ? $stmt->fetch() : false;
        return is_array($row) ? $row : [];
    } catch (Throwable) {
        return [];
    }
}

function docs_player_object(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        $name = 'Unknown';
    }

    return [
        'id' => (int) (100000000 + (sprintf('%u', crc32(strtolower($name))) % 900000000)),
        'name' => $name,
        'banned' => false,
    ];
}

function docs_sample_demon_object(array $demon): array
{
    $levelId = trim((string) ($demon['level_id'] ?? ''));

    return [
        'id' => (int) ($demon['id'] ?? 1),
        'position' => (int) ($demon['position'] ?? 1),
        'name' => (string) ($demon['name'] ?? 'Level Name'),
        'requirement' => (int) ($demon['requirement'] ?? 100),
        'video' => docs_nullable_string($demon['video_url'] ?? null),
        'thumbnail' => docs_nullable_string($demon['thumbnail_url'] ?? null),
        'publisher' => docs_player_object((string) ($demon['publisher'] ?? 'Unknown')),
        'verifier' => docs_player_object((string) ($demon['verifier'] ?? 'Unknown')),
        'level_id' => preg_match('/^\d+$/', $levelId) === 1 ? (int) $levelId : null,
    ];
}

function docs_sample_level_object(array $demon): array
{
    $object = docs_sample_demon_object($demon);
    $object['creators'] = array_map(
        static fn(string $name): array => docs_player_object($name),
        docs_creator_names($demon)
    );

    return $object;
}

function docs_creator_names(array $demon): array
{
    $names = function_exists('demon_creator_names') ? demon_creator_names($demon) : [];
    if ($names !== []) {
        return $names;
    }

    $publisher = trim((string) ($demon['publisher'] ?? ''));
    return $publisher !== '' ? [$publisher] : [];
}

function docs_nullable_string(mixed $value): ?string
{
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : null;
}

function docs_pretty_json(array $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '{}';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($appName) ?> API Docs</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #242933;
            --muted: #667085;
            --line: #d9dee7;
            --panel: #ffffff;
            --page: #f3f6fa;
            --accent: #0b7fc3;
            --accent-ink: #07517d;
            --code: #111827;
            --code-bg: #edf1f6;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--ink);
            background: var(--page);
            font: 15px/1.55 Arial, Helvetica, sans-serif;
        }

        header {
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }

        main,
        .topbar {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
        }

        .topbar {
            min-height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            font-weight: 700;
            font-size: 20px;
        }

        nav {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        a {
            color: var(--accent-ink);
            text-decoration: none;
            font-weight: 700;
        }

        a:hover {
            text-decoration: underline;
        }

        main {
            padding: 28px 0 44px;
        }

        .intro,
        .endpoint,
        .section {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 18px;
        }

        h1,
        h2,
        h3,
        p {
            margin-top: 0;
        }

        h1 {
            font-size: 30px;
            margin-bottom: 8px;
        }

        h2 {
            font-size: 22px;
            margin-bottom: 14px;
        }

        h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        .muted {
            color: var(--muted);
        }

        .base-url {
            display: block;
            overflow-x: auto;
            padding: 12px 14px;
            border-radius: 6px;
            background: var(--code-bg);
            color: var(--code);
            font-weight: 700;
        }

        .endpoint-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .route {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .method {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            min-height: 26px;
            padding: 0 9px;
            border-radius: 5px;
            background: var(--accent);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0;
        }

        code,
        pre {
            font-family: Consolas, Monaco, "Courier New", monospace;
        }

        code {
            word-break: break-word;
        }

        pre {
            overflow-x: auto;
            margin: 10px 0 0;
            padding: 14px;
            border-radius: 6px;
            background: var(--code-bg);
            color: var(--code);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        dl {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr);
            gap: 8px 14px;
            margin: 0;
        }

        dt {
            font-weight: 700;
        }

        dd {
            margin: 0;
            color: var(--muted);
        }

        .footer-note {
            color: var(--muted);
            font-size: 14px;
        }

        @media (max-width: 760px) {
            main,
            .topbar {
                width: min(100% - 20px, 1120px);
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
                padding: 16px 0;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            dl {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<header>
    <div class="topbar">
        <div class="brand"><?= e($appName) ?> API</div>
        <nav aria-label="API docs navigation">
            <a href="<?= e($siteBase === '' ? '/' : $siteBase) ?>">Website</a>
            <a href="<?= e($apiBase . '/') ?>">JSON Root</a>
            <a href="<?= e($apiBase . '/listed/?limit=10') ?>">Try Listed</a>
        </nav>
    </div>
</header>

<main>
    <section class="intro">
        <h1>API Docs</h1>
        <p class="muted">Read-only JSON API for demonlist data, with Pointercrate-compatible listed endpoints and a level ID lookup endpoint for in-game mods.</p>
        <p class="muted">Examples on this page are generated from the current list database when available.</p>
        <h2>Base URL</h2>
        <code class="base-url"><?= e($apiBase) ?></code>
    </section>

    <section class="section grid">
        <div>
            <h2>Basics</h2>
            <dl>
                <dt>Format</dt>
                <dd>JSON, UTF-8</dd>
                <dt>Auth</dt>
                <dd>Not required for public read endpoints</dd>
                <dt>Methods</dt>
                <dd>GET and OPTIONS</dd>
                <dt>CORS</dt>
                <dd><code>Access-Control-Allow-Origin: *</code></dd>
            </dl>
        </div>
        <div>
            <h2>Pagination</h2>
            <dl>
                <dt><code>limit</code></dt>
                <dd>1-100, default 50</dd>
                <dt><code>after</code></dt>
                <dd>Return entries after the given pagination id</dd>
                <dt><code>before</code></dt>
                <dd>Return entries before the given pagination id</dd>
            </dl>
        </div>
    </section>

    <section class="section">
        <h2>Mod URL</h2>
        <p class="muted">For level ID lookups in a Geometry Dash mod, use this base and append the level ID.</p>
        <pre><code><?= e($apiBase) ?>/levels/</code></pre>
        <pre><code>std::string url = "<?= e($apiBase) ?>/levels/" + std::to_string(lvlID);</code></pre>
        <?php if ($sampleLevelId !== ''): ?>
            <p class="muted">Example from this list: <a href="<?= e($apiBase . '/levels/' . rawurlencode($sampleLevelId)) ?>"><?= e($apiBase . '/levels/' . $sampleLevelId) ?></a></p>
        <?php endif; ?>
    </section>

    <section>
        <h2>Endpoints</h2>
        <?php foreach ($endpoints as $endpoint): ?>
            <article class="endpoint">
                <div class="endpoint-head">
                    <div class="route">
                        <span class="method"><?= e($endpoint['method']) ?></span>
                        <code><?= e($endpoint['path']) ?></code>
                    </div>
                    <a href="<?= e($endpoint['example']) ?>">Open example</a>
                </div>
                <p><?= e($endpoint['summary']) ?></p>
                <dl>
                    <dt>Query</dt>
                    <dd><?= e($endpoint['query']) ?></dd>
                    <dt>Example</dt>
                    <dd><code><?= e($endpoint['example']) ?></code></dd>
                </dl>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="section grid">
        <div>
            <h2>Demon Object</h2>
            <pre><code><?= e($sampleDemonJson) ?></code></pre>
        </div>
        <div>
            <h2>Level Lookup Object</h2>
            <pre><code><?= e($sampleLevelJson) ?></code></pre>
        </div>
    </section>

    <section class="section">
        <h2>Compatibility Notes</h2>
        <p class="footer-note">This API is intentionally public and read-only. It mirrors the Pointercrate response shape needed by tools and mods, but it does not implement Pointercrate admin/auth POST, PATCH, or DELETE behavior.</p>
    </section>
</main>
</body>
</html>
