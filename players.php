<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function pointercrate_beaten_score(int $position): float
{
    return demonlist_beaten_score($position);
}

function pointercrate_score(int $position, int $requirement, int $progress): float
{
    return demonlist_score($position, $requirement, $progress);
}

function format_stats_items(array $items): string
{
    if ($items === []) {
        return 'None';
    }

    return implode(' - ', $items);
}

function render_stats_demon_links(array $demons, bool $useLabel = false): string
{
    if ($demons === []) {
        return 'None';
    }

    $parts = [];
    foreach ($demons as $demon) {
        if (!is_array($demon)) {
            continue;
        }

        $name = trim((string) ($demon['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $position = isset($demon['position']) ? (int) $demon['position'] : null;
        $label = $name;
        if (!$useLabel && $position !== null && $position > 0) {
            $label = '#' . $position . ' ' . $name;
        }
        if ($useLabel) {
            $label = trim((string) ($demon['label'] ?? $label));
        }

        $url = trim((string) ($demon['url'] ?? ''));
        if ($url !== '') {
            $parts[] = '<a class="link stats-demon-link" href="' . e($url) . '">' . e($label) . '</a>';
        } else {
            $parts[] = e($label);
        }
    }

    if ($parts === []) {
        return 'None';
    }

    return implode(' - ', $parts);
}

$pdo = db();

$hasBonusPointsColumn = false;
try {
    $hasBonusPointsColumn = (bool) $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND column_name = 'bonus_points'"
    )->fetchColumn();
} catch (Throwable) {
    $hasBonusPointsColumn = false;
}

$hasUserBannedColumn = users_has_is_banned_column($pdo);
$userSelectFields = [
    'id',
    'username',
    'country_code',
    'role',
    'points',
    $hasBonusPointsColumn ? 'bonus_points' : '0.00 AS bonus_points',
    $hasUserBannedColumn ? 'is_banned' : '0 AS is_banned',
];
$userSelect = 'SELECT ' . implode(', ', $userSelectFields) . ' FROM users ORDER BY username ASC';

$users = $pdo->query($userSelect)->fetchAll();

$hasPublisherUserIdColumn = false;
$hasVerifierUserIdColumn = false;
$hasCreatorMoreColumn = false;
try {
    $claimColStmt = $pdo->query(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'demons'
           AND column_name IN ('creator_more', 'publisher_user_id', 'verifier_user_id')"
    );
    foreach ($claimColStmt->fetchAll(PDO::FETCH_COLUMN) as $columnName) {
        $columnName = strtolower((string) $columnName);
        if ($columnName === 'creator_more') {
            $hasCreatorMoreColumn = true;
        }
        if ($columnName === 'publisher_user_id') {
            $hasPublisherUserIdColumn = true;
        }
        if ($columnName === 'verifier_user_id') {
            $hasVerifierUserIdColumn = true;
        }
    }
} catch (Throwable) {
    $hasCreatorMoreColumn = false;
    $hasPublisherUserIdColumn = false;
    $hasVerifierUserIdColumn = false;
}

$demonSelectFields = [
    'id',
    'name',
    'position',
    'requirement',
    'legacy',
    'creator',
    $hasCreatorMoreColumn ? 'creator_more' : 'NULL AS creator_more',
    'publisher',
    'verifier',
    $hasPublisherUserIdColumn ? 'publisher_user_id' : 'NULL AS publisher_user_id',
    $hasVerifierUserIdColumn ? 'verifier_user_id' : 'NULL AS verifier_user_id',
];
$demons = $pdo->query('SELECT ' . implode(', ', $demonSelectFields) . '
                       FROM demons
                       ORDER BY position ASC')->fetchAll();

$records = db()->query('SELECT demon_id, player, progress
                        FROM completions
                        ORDER BY created_at DESC')->fetchAll();

$demonById = [];
foreach ($demons as $demon) {
    $demonId = (int) $demon['id'];
    $demonById[$demonId] = $demon;
}

$playersByKey = [];
$usersById = [];

$ensurePlayer = static function (string $rawName) use (&$playersByKey): ?string {
    $name = trim($rawName);
    if ($name === '') {
        return null;
    }

    $key = strtolower($name);
    if (!isset($playersByKey[$key])) {
        $playersByKey[$key] = [
            'key' => $key,
            'user_id' => null,
            'has_account' => false,
            'username' => $name,
            'country_code' => null,
            'role' => 'player',
            'is_banned' => false,
            'stored_points' => 0.0,
            'bonus_points' => 0.0,
            'points' => 0.0,
            'score' => 0.0,
            'rank' => null,
            'total_records' => 0,
            'total_completions' => 0,
            'completion_scores' => [],
            'main_records' => 0,
            'extended_records' => 0,
            'legacy_records' => 0,
            'hardest_demon' => null,
            'hardest_position' => null,
            'hardest_score' => 0.0,
            'completed' => [],
            'main_completed' => [],
            'extended_completed' => [],
            'legacy_completed' => [],
            'progress_on' => [],
            'demons_created' => [],
            'demons_published' => [],
            'demons_verified' => [],
        ];
    }

    return $key;
};

$updateHardest = static function (array &$player, string $demonName, int $position, float $score): void {
    $hardestScore = (float) ($player['hardest_score'] ?? 0.0);
    $hardestPosition = $player['hardest_position'] !== null
        ? (int) $player['hardest_position']
        : null;

    $isBetter = $score > $hardestScore;
    if (!$isBetter && abs($score - $hardestScore) < 0.00001) {
        $isBetter = $hardestPosition === null || $position < $hardestPosition;
    }

    if ($isBetter) {
        $player['hardest_score'] = $score;
        $player['hardest_position'] = $position;
        $player['hardest_demon'] = $demonName;
    }
};

foreach ($users as $user) {
    $username = trim((string) ($user['username'] ?? ''));
    $key = $ensurePlayer($username);
    if ($key === null) {
        continue;
    }

    $userId = (int) ($user['id'] ?? 0) > 0 ? (int) $user['id'] : null;
    $playersByKey[$key]['user_id'] = $userId;
    if ($userId !== null) {
        $usersById[$userId] = $username;
    }
    $playersByKey[$key]['has_account'] = true;
    $playersByKey[$key]['username'] = $username;
    $playersByKey[$key]['country_code'] = normalize_country_code((string) ($user['country_code'] ?? ''));
    $playersByKey[$key]['role'] = (string) ($user['role'] ?? 'player');
    $playersByKey[$key]['is_banned'] = (int) ($user['is_banned'] ?? 0) === 1;
    $playersByKey[$key]['stored_points'] = round((float) ($user['points'] ?? 0.0), 2);
    $playersByKey[$key]['bonus_points'] = round((float) ($user['bonus_points'] ?? 0.0), 2);
    $playersByKey[$key]['points'] = (float) $playersByKey[$key]['stored_points'];
}

foreach ($demons as $demon) {
    $demonId = (int) $demon['id'];
    $demonName = (string) $demon['name'];
    $demonPosition = (int) $demon['position'];
    $legacy = (int) $demon['legacy'] === 1;
    $isRankedEntry = demonlist_is_ranked_entry($demonPosition, $legacy);
    $demonItem = [
        'id' => $demonId,
        'name' => $demonName,
        'position' => $demonPosition,
        'url' => base_url((string) $demonPosition),
    ];

    $creatorNames = demon_creator_names($demon);
    if ($creatorNames === []) {
        $fallbackCreator = trim((string) ($demon['publisher'] ?? ''));
        if ($fallbackCreator !== '') {
            $creatorNames[] = $fallbackCreator;
        }
    }

    foreach ($creatorNames as $creator) {
        $creatorKey = $ensurePlayer($creator);
        if ($creatorKey !== null) {
            $playersByKey[$creatorKey]['demons_created'][$demonId] = $demonItem;
        }
    }

    $publisherUserId = (int) ($demon['publisher_user_id'] ?? 0);
    if ($publisherUserId > 0 && isset($usersById[$publisherUserId]) && $isRankedEntry) {
        $publisherKey = $ensurePlayer((string) $usersById[$publisherUserId]);
        if ($publisherKey !== null) {
            $playersByKey[$publisherKey]['demons_published'][$demonId] = $demonItem;
        }
    }

    $verifierUserId = (int) ($demon['verifier_user_id'] ?? 0);
    $verifierKey = null;
    if ($verifierUserId > 0 && isset($usersById[$verifierUserId])) {
        $verifierKey = $ensurePlayer((string) $usersById[$verifierUserId]);
    } else {
        $verifierName = trim((string) ($demon['verifier'] ?? ''));
        if ($verifierName !== '') {
            $verifierKey = $ensurePlayer($verifierName);
        }
    }

    if ($verifierKey !== null) {
        $playersByKey[$verifierKey]['demons_verified'][$demonId] = $demonItem;
    }
}

foreach ($records as $record) {
    $playerName = trim((string) ($record['player'] ?? ''));
    $key = $ensurePlayer($playerName);
    if ($key === null) {
        continue;
    }

    $demonId = (int) ($record['demon_id'] ?? 0);
    if ($demonId < 1 || !isset($demonById[$demonId])) {
        continue;
    }

    $demon = $demonById[$demonId];
    $demonName = (string) $demon['name'];
    $position = (int) $demon['position'];
    $requirement = (int) $demon['requirement'];
    $legacy = (int) $demon['legacy'] === 1;
    $isRankedEntry = demonlist_is_ranked_entry($position, $legacy);

    $progress = (int) ($record['progress'] ?? 0);
    if ($progress < $requirement) {
        continue;
    }

    $score = $isRankedEntry ? pointercrate_score($position, $requirement, $progress) : 0.0;

    $playersByKey[$key]['total_records']++;
    if ($isRankedEntry) {
        $playersByKey[$key]['score'] += $score;
        $existingScore = (float) ($playersByKey[$key]['completion_scores'][$demonId] ?? 0.0);
        if ($score > $existingScore) {
            $playersByKey[$key]['completion_scores'][$demonId] = $score;
        }
    }

    $listBucket = demonlist_list_bucket($position, $legacy);
    if ($listBucket === 'main') {
        $playersByKey[$key]['main_records']++;
    } elseif ($listBucket === 'extended') {
        $playersByKey[$key]['extended_records']++;
    } else {
        $playersByKey[$key]['legacy_records']++;
    }

    if ($progress >= 100) {
        $playersByKey[$key]['total_completions']++;
        $completionItem = [
            'id' => $demonId,
            'name' => $demonName,
            'position' => $position,
            'url' => base_url((string) $position),
        ];
        $playersByKey[$key]['completed'][$demonId] = $completionItem;
        if ($listBucket === 'main') {
            $playersByKey[$key]['main_completed'][$demonId] = $completionItem;
        } elseif ($listBucket === 'extended') {
            $playersByKey[$key]['extended_completed'][$demonId] = $completionItem;
        } else {
            $playersByKey[$key]['legacy_completed'][$demonId] = $completionItem;
        }

        if ($position > 0) {
            $completionHardestScore = pointercrate_score($position, $requirement, 100);
            $updateHardest($playersByKey[$key], $demonName, $position, $completionHardestScore);
        }
    } else {
        $playersByKey[$key]['progress_on'][$demonId] = [
            'id' => $demonId,
            'name' => $demonName,
            'position' => $position,
            'label' => $demonName . ' (' . $progress . '%)',
            'url' => base_url((string) $position),
        ];
    }
}

foreach ($playersByKey as &$playerData) {
    foreach ((array) $playerData['demons_verified'] as $verifiedDemon) {
        $verifiedDemonId = isset($verifiedDemon['id']) ? (int) $verifiedDemon['id'] : 0;
        if ($verifiedDemonId < 1 || !isset($demonById[$verifiedDemonId])) {
            continue;
        }

        $verifiedInfo = $demonById[$verifiedDemonId];
        $verifiedPosition = (int) ($verifiedInfo['position'] ?? 0);
        if ($verifiedPosition < 1) {
            continue;
        }

        $verifiedName = trim((string) ($verifiedInfo['name'] ?? ($verifiedDemon['name'] ?? '')));
        if ($verifiedName === '') {
            continue;
        }

        $verifiedRequirement = (int) ($verifiedInfo['requirement'] ?? 100);
        $verifiedScore = pointercrate_score($verifiedPosition, $verifiedRequirement, 100);
        $updateHardest($playerData, $verifiedName, $verifiedPosition, $verifiedScore);
    }
}
unset($playerData);

$sortByDemonPosition = static function (array $items): array {
    $sorted = array_values($items);

    usort($sorted, static function (array $a, array $b): int {
        $aPos = isset($a['position']) ? (int) $a['position'] : PHP_INT_MAX;
        $bPos = isset($b['position']) ? (int) $b['position'] : PHP_INT_MAX;
        if ($aPos !== $bPos) {
            return $aPos <=> $bPos;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $sorted;
};

$players = array_values($playersByKey);
$userPointUpdates = [];

foreach ($players as &$player) {
    $player['completed'] = $sortByDemonPosition((array) $player['completed']);
    $player['main_completed'] = $sortByDemonPosition((array) $player['main_completed']);
    $player['extended_completed'] = $sortByDemonPosition((array) $player['extended_completed']);
    $player['legacy_completed'] = $sortByDemonPosition((array) $player['legacy_completed']);
    $player['demons_created'] = $sortByDemonPosition((array) $player['demons_created']);
    $player['demons_published'] = $sortByDemonPosition((array) $player['demons_published']);
    $player['demons_verified'] = $sortByDemonPosition((array) $player['demons_verified']);
    $player['progress_on'] = $sortByDemonPosition((array) $player['progress_on']);

    $verifierBonus = 0.0;
    foreach ((array) $player['demons_verified'] as $verifiedDemon) {
        $verifiedDemonId = isset($verifiedDemon['id']) ? (int) $verifiedDemon['id'] : 0;
        if ($verifiedDemonId < 1 || !isset($demonById[$verifiedDemonId])) {
            continue;
        }

        $verifiedInfo = $demonById[$verifiedDemonId];
        $verifiedLegacy = (int) ($verifiedInfo['legacy'] ?? 0) === 1;
        $verifiedPosition = (int) ($verifiedInfo['position'] ?? 0);
        $verifiedRequirement = (int) ($verifiedInfo['requirement'] ?? 100);

        if (!demonlist_is_ranked_entry($verifiedPosition, $verifiedLegacy)) {
            continue;
        }

        $fullVerifierScore = pointercrate_score($verifiedPosition, $verifiedRequirement, 100);
        $existingCompletionScore = (float) ($player['completion_scores'][$verifiedDemonId] ?? 0.0);

        // Award verifier full points for the level, without double-counting if they already completed it.
        if ($fullVerifierScore > $existingCompletionScore) {
            $verifierBonus += ($fullVerifierScore - $existingCompletionScore);
        }
    }

    $computedPoints = round((float) $player['score'] + $verifierBonus + (float) ($player['bonus_points'] ?? 0.0), 2);
    $player['points'] = $computedPoints;

    if ((bool) ($player['has_account'] ?? false) && $player['user_id'] !== null) {
        $storedPoints = round((float) ($player['stored_points'] ?? 0.0), 2);
        if (abs($storedPoints - $computedPoints) >= 0.01) {
            $userPointUpdates[] = [
                'id' => (int) $player['user_id'],
                'points' => $computedPoints,
            ];
        }
    }
}
unset($player);

if ($userPointUpdates !== []) {
    try {
        $updatePoints = db()->prepare('UPDATE users SET points = :points WHERE id = :id');
        foreach ($userPointUpdates as $update) {
            $updatePoints->execute([
                ':points' => $update['points'],
                ':id' => $update['id'],
            ]);
        }
    } catch (Throwable) {
        // keep Stats Viewer available even if point sync fails
    }
}

usort($players, static function (array $a, array $b): int {
    $aBanned = !empty($a['is_banned']);
    $bBanned = !empty($b['is_banned']);
    if ($aBanned !== $bBanned) {
        return $aBanned ? 1 : -1;
    }

    $scoreCompare = (float) $b['points'] <=> (float) $a['points'];
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }

    $mainCompare = (int) $b['main_records'] <=> (int) $a['main_records'];
    if ($mainCompare !== 0) {
        return $mainCompare;
    }

    $recordsCompare = (int) $b['total_records'] <=> (int) $a['total_records'];
    if ($recordsCompare !== 0) {
        return $recordsCompare;
    }

    return strcasecmp((string) $a['username'], (string) $b['username']);
});

$rank = 0;
foreach ($players as &$player) {
    $playerBanned = !empty($player['is_banned']);
    if (!$playerBanned && (float) $player['points'] > 0.00001) {
        $rank++;
        $player['rank'] = $rank;
    } else {
        $player['rank'] = null;
    }
}
unset($player);

$countriesWithPlayers = [];
foreach ($players as $player) {
    $countryCode = normalize_country_code((string) ($player['country_code'] ?? ''));
    if ($countryCode === null) {
        continue;
    }

    $countriesWithPlayers[$countryCode] = country_name($countryCode) ?? $countryCode;
}
asort($countriesWithPlayers, SORT_NATURAL | SORT_FLAG_CASE);

$requestedUserId = (int) ($_GET['uid'] ?? 0);
$requestedKey = strtolower(trim((string) ($_GET['user'] ?? '')));
$requestedCountry = strtoupper(trim((string) ($_GET['country'] ?? '')));
if ($requestedCountry === 'WORLD') {
    $requestedCountry = '';
}
if ($requestedCountry !== '' && !isset($countriesWithPlayers[$requestedCountry])) {
    $requestedCountry = '';
}
$selectedIndex = 0;

if ($players !== []) {
    if ($requestedUserId > 0) {
        foreach ($players as $index => $player) {
            if ((int) ($player['user_id'] ?? 0) === $requestedUserId) {
                $selectedIndex = $index;
                break;
            }
        }
    } elseif ($requestedKey !== '') {
        foreach ($players as $index => $player) {
            if ((string) $player['key'] === $requestedKey) {
                $selectedIndex = $index;
                break;
            }
        }
    } elseif ($requestedCountry !== '') {
        foreach ($players as $index => $player) {
            $countryCode = normalize_country_code((string) ($player['country_code'] ?? ''));
            if ($countryCode === $requestedCountry) {
                $selectedIndex = $index;
                break;
            }
        }
    } else {
        foreach ($players as $index => $player) {
            if ($player['rank'] !== null) {
                $selectedIndex = $index;
                break;
            }
        }
    }
}

$selectedPlayer = $players[$selectedIndex] ?? null;

$serializeDemonItems = static function (array $items, bool $useLabel = false): array {
    $result = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $result[] = [
            'name' => $name,
            'position' => isset($item['position']) ? (int) $item['position'] : null,
            'url' => trim((string) ($item['url'] ?? '')),
            'label' => $useLabel ? trim((string) ($item['label'] ?? $name)) : null,
        ];
    }

    return $result;
};

$playersPayload = [];
foreach ($players as $player) {
    $countryCode = normalize_country_code((string) ($player['country_code'] ?? ''));
    $hardestLabel = 'None';
    if ($player['hardest_demon'] !== null) {
        $hardestLabel = $player['hardest_position'] !== null
            ? ('#' . (int) $player['hardest_position'] . ' ' . (string) $player['hardest_demon'])
            : (string) $player['hardest_demon'];
    }

    $points = round((float) $player['points'], 2);
    $playersPayload[] = [
        'key' => (string) $player['key'],
        'user_id' => $player['user_id'] !== null ? (int) $player['user_id'] : null,
        'username' => (string) $player['username'],
        'country_code' => $countryCode ?? '',
        'rank' => $player['rank'] !== null ? (int) $player['rank'] : null,
        'points' => $points,
        'total_records' => (int) $player['total_records'],
        'total_completions' => (int) $player['total_completions'],
        'main_records' => (int) $player['main_records'],
        'extended_records' => (int) $player['extended_records'],
        'legacy_records' => (int) $player['legacy_records'],
        'created_count' => count((array) $player['demons_created']),
        'published_count' => count((array) $player['demons_published']),
        'verified_count' => count((array) $player['demons_verified']),
        'hardest_demon' => $player['hardest_demon'] !== null ? (string) $player['hardest_demon'] : 'None',
        'hardest_position' => $player['hardest_position'] !== null ? (int) $player['hardest_position'] : null,
        'hardest_label' => $hardestLabel,
        'completed' => $serializeDemonItems((array) $player['completed']),
        'main_completed' => $serializeDemonItems((array) $player['main_completed']),
        'extended_completed' => $serializeDemonItems((array) $player['extended_completed']),
        'legacy_completed' => $serializeDemonItems((array) $player['legacy_completed']),
        'demons_created' => $serializeDemonItems((array) $player['demons_created']),
        'demons_published' => $serializeDemonItems((array) $player['demons_published']),
        'demons_verified' => $serializeDemonItems((array) $player['demons_verified']),
        'progress_on' => $serializeDemonItems((array) $player['progress_on'], true),
        'flag_url' => country_flag_asset_url($countryCode),
        'search' => strtolower((string) $player['username']) . ' '
            . (($player['rank'] !== null) ? ('#' . (int) $player['rank']) : '') . ' '
            . number_format($points, 2) . ' '
            . (int) $player['total_completions'],
    ];
}

render_header('Stats Viewer', 'players');
?>
<section class="panel fade stats-viewer-panel">
    <div class="panel-head">
        <h1>Stats Viewer</h1>
        <p>Compare players by points, completions, hardest demons, and contributions</p>
    </div>

    <?php if ($players === [] || $selectedPlayer === null): ?>
        <p class="muted">No player data available yet.</p>
    <?php else: ?>
        <?php
        $selectedCountryCode = normalize_country_code((string) ($selectedPlayer['country_code'] ?? ''));
        $selectedFlag = country_flag_html($selectedCountryCode, true);
        $selectedHardestLabel = 'None';
        if ($selectedPlayer['hardest_demon'] !== null) {
            $selectedHardestLabel = $selectedPlayer['hardest_position'] !== null
                ? ('#' . (int) $selectedPlayer['hardest_position'] . ' ' . (string) $selectedPlayer['hardest_demon'])
                : (string) $selectedPlayer['hardest_demon'];
        }
        ?>
        <div class="stats-viewer-grid">
            <aside class="stats-viewer-sidebar">
                <label class="stats-viewer-country" for="stats-country-filter">
                    <span>International</span>
                    <select id="stats-country-filter">
                        <option value="" <?= $requestedCountry === '' ? 'selected' : '' ?>>WORLD - International</option>
                        <?php foreach ($countriesWithPlayers as $countryCode => $countryName): ?>
                            <option value="<?= e($countryCode) ?>" <?= $requestedCountry === $countryCode ? 'selected' : '' ?>><?= e($countryCode . ' - ' . $countryName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="stats-viewer-search" for="stats-player-search">
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <input id="stats-player-search" type="text" placeholder="Enter to search..." autocomplete="off">
                    <button id="stats-player-search-clear" type="button" aria-label="Clear search">&times;</button>
                </label>

                <ul id="stats-player-list" class="stats-player-list">
                    <?php foreach ($players as $index => $player): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($player['country_code'] ?? ''));
                        $countryFlag = country_flag_html($countryCode, true);
                        $rankLabel = $player['rank'] !== null ? '#' . (int) $player['rank'] : '-';
                        ?>
                        <li
                            class="stats-player-item <?= $index === $selectedIndex ? 'active' : '' ?>"
                            data-player-key="<?= e((string) $player['key']) ?>"
                            data-country-code="<?= e((string) ($countryCode ?? '')) ?>"
                            data-search-value="<?= e(strtolower((string) $player['username']) . ' ' . strtolower($rankLabel)) ?>"
                        >
                            <button type="button" class="stats-player-button" data-player-select="<?= e((string) $player['key']) ?>">
                                <span class="stats-player-rank"><?= e($rankLabel) ?></span>
                                <span class="stats-player-name"><?= $countryFlag ?><span><?= e((string) $player['username']) ?></span></span>
                                <span class="stats-player-score"><?= e(number_format((float) $player['points'], 2)) ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="stats-viewer-pagination">
                    <button id="stats-viewer-prev" type="button" class="button white hover small">Previous</button>
                    <button id="stats-viewer-next" type="button" class="button white hover small">Next</button>
                </div>
            </aside>

            <section class="stats-viewer-detail" id="stats-viewer-detail">
                <div class="stats-viewer-player-head">
                    <h2 class="stats-viewer-player-title">
                        <span id="stats-player-flag"><?= $selectedFlag ?></span>
                        <span id="stats-player-name" class="stats-viewer-player-name"><?= e((string) $selectedPlayer['username']) ?></span>
                    </h2>
                </div>

                <div class="stats-viewer-summary">
                    <article class="stats-viewer-summary-card">
                        <h3>Demonlist rank</h3>
                        <p id="stats-rank"><?= $selectedPlayer['rank'] !== null ? '#' . (int) $selectedPlayer['rank'] : '-' ?></p>
                    </article>
                    <article class="stats-viewer-summary-card">
                        <h3>Total points</h3>
                        <p id="stats-score"><?= e(number_format((float) $selectedPlayer['points'], 2)) ?></p>
                    </article>
                    <article class="stats-viewer-summary-card">
                        <h3>Hardest demon</h3>
                        <p id="stats-hardest"><?= e($selectedHardestLabel) ?></p>
                    </article>
                    <article class="stats-viewer-summary-card stats-viewer-summary-card-contrib">
                        <h3>Contributions</h3>
                        <p id="stats-contrib"><?= count((array) $selectedPlayer['demons_created']) ?> Created, <?= count((array) $selectedPlayer['demons_published']) ?> Published, <?= count((array) $selectedPlayer['demons_verified']) ?> Verified</p>
                    </article>
                    <article class="stats-viewer-summary-card stats-viewer-summary-card-breakdown">
                        <h3>Demonlist stats</h3>
                        <p id="stats-breakdown"><?= (int) $selectedPlayer['main_records'] ?> Main, <?= (int) $selectedPlayer['extended_records'] ?> Extended, <?= (int) $selectedPlayer['legacy_records'] ?> Legacy</p>
                    </article>
                </div>

                <div class="stats-viewer-lines">
                    <article>
                        <h3>Main list completed</h3>
                        <p id="stats-main-completed"><?= render_stats_demon_links((array) $selectedPlayer['main_completed']) ?></p>
                    </article>
                    <article>
                        <h3>Extended list completed</h3>
                        <p id="stats-extended-completed"><?= render_stats_demon_links((array) $selectedPlayer['extended_completed']) ?></p>
                    </article>
                    <article>
                        <h3>Legacy list completed</h3>
                        <p id="stats-legacy-completed"><?= render_stats_demon_links((array) $selectedPlayer['legacy_completed']) ?></p>
                    </article>
                    <article>
                        <h3>Demons created</h3>
                        <p id="stats-created"><?= render_stats_demon_links((array) $selectedPlayer['demons_created']) ?></p>
                    </article>
                    <article>
                        <h3>Demons published</h3>
                        <p id="stats-published"><?= render_stats_demon_links((array) $selectedPlayer['demons_published']) ?></p>
                    </article>
                    <article>
                        <h3>Demons verified</h3>
                        <p id="stats-verified"><?= render_stats_demon_links((array) $selectedPlayer['demons_verified']) ?></p>
                    </article>
                    <article>
                        <h3>Progress on</h3>
                        <p id="stats-progress"><?= render_stats_demon_links((array) $selectedPlayer['progress_on'], true) ?></p>
                    </article>
                </div>
            </section>
        </div>

        <script>
        (() => {
            const data = <?= json_encode($playersPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            if (!Array.isArray(data) || data.length === 0) {
                return;
            }

            const list = document.getElementById('stats-player-list');
            const countryFilter = document.getElementById('stats-country-filter');
            const searchInput = document.getElementById('stats-player-search');
            const clearSearch = document.getElementById('stats-player-search-clear');
            const prevButton = document.getElementById('stats-viewer-prev');
            const nextButton = document.getElementById('stats-viewer-next');
            const detailEl = document.getElementById('stats-viewer-detail');

            const flagEl = document.getElementById('stats-player-flag');
            const nameEl = document.getElementById('stats-player-name');
            const rankEl = document.getElementById('stats-rank');
            const scoreEl = document.getElementById('stats-score');
            const breakdownEl = document.getElementById('stats-breakdown');
            const hardestEl = document.getElementById('stats-hardest');
            const contribEl = document.getElementById('stats-contrib');
            const mainCompletedEl = document.getElementById('stats-main-completed');
            const extendedCompletedEl = document.getElementById('stats-extended-completed');
            const legacyCompletedEl = document.getElementById('stats-legacy-completed');
            const createdEl = document.getElementById('stats-created');
            const publishedEl = document.getElementById('stats-published');
            const verifiedEl = document.getElementById('stats-verified');
            const progressEl = document.getElementById('stats-progress');

            if (!(list instanceof HTMLElement) || !(searchInput instanceof HTMLInputElement)) {
                return;
            }

            const items = Array.from(list.querySelectorAll('.stats-player-item'));
            const indexByKey = new Map();
            data.forEach((player, index) => {
                indexByKey.set(player.key, index);
            });

            let currentIndex = <?= (int) $selectedIndex ?>;
            let visibleIndexes = data.map((_, index) => index);

            const renderDemonList = (target, demons, useLabel = false) => {
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                target.textContent = '';
                if (!Array.isArray(demons) || demons.length === 0) {
                    target.textContent = 'None';
                    return;
                }

                demons.forEach((demon, index) => {
                    const position = Number.isFinite(Number(demon?.position)) ? Number(demon.position) : null;
                    const demonName = String(demon?.name || '').trim();
                    if (demonName === '') {
                        return;
                    }

                    let label = demonName;
                    if (useLabel) {
                        label = String(demon?.label || demonName).trim();
                    } else if (position !== null && position > 0) {
                        label = `#${position} ${demonName}`;
                    }

                    const url = typeof demon?.url === 'string' ? demon.url : '';
                    if (url !== '') {
                        const link = document.createElement('a');
                        link.className = 'link stats-demon-link';
                        link.href = url;
                        link.textContent = label;
                        target.appendChild(link);
                    } else {
                        target.appendChild(document.createTextNode(label));
                    }

                    if (index < demons.length - 1) {
                        target.appendChild(document.createTextNode(' - '));
                    }
                });

                if (target.textContent === '') {
                    target.textContent = 'None';
                }
            };

            const setActiveItem = (key) => {
                items.forEach((item) => {
                    const itemKey = item.getAttribute('data-player-key');
                    item.classList.toggle('active', itemKey === key);
                });
            };

            const renderFlag = (flagUrl) => {
                if (!(flagEl instanceof HTMLElement)) {
                    return;
                }

                flagEl.innerHTML = '';
                if (typeof flagUrl !== 'string' || flagUrl === '') {
                    return;
                }

                const flag = document.createElement('span');
                flag.className = 'flag-icon';
                flag.style.backgroundImage = `url('${flagUrl.replace(/'/g, "%27")}')`;
                flag.style.marginRight = '6px';
                flagEl.appendChild(flag);
            };

            const updateQueryString = (username, userId = null) => {
                try {
                    const url = new URL(window.location.href);
                    const normalizedId = Number(userId);
                    if (Number.isFinite(normalizedId) && normalizedId > 0) {
                        url.searchParams.set('uid', String(Math.trunc(normalizedId)));
                        url.searchParams.delete('user');
                    } else {
                        url.searchParams.set('user', username);
                        url.searchParams.delete('uid');
                    }
                    window.history.replaceState({}, '', url.toString());
                } catch (error) {
                    // no-op
                }
            };

            const updateCountryQueryString = (countryCode) => {
                try {
                    const url = new URL(window.location.href);
                    const normalized = String(countryCode || '').trim().toUpperCase();
                    if (normalized === '') {
                        url.searchParams.delete('country');
                    } else {
                        url.searchParams.set('country', normalized);
                    }
                    window.history.replaceState({}, '', url.toString());
                } catch (error) {
                    // no-op
                }
            };

            const renderPlayer = (index) => {
                if (index < 0 || index >= data.length) {
                    return;
                }

                currentIndex = index;
                const player = data[currentIndex];
                setActiveItem(player.key);

                renderFlag(player.flag_url || '');
                if (nameEl instanceof HTMLElement) {
                    nameEl.textContent = player.username;
                }

                if (rankEl instanceof HTMLElement) {
                    rankEl.textContent = player.rank !== null ? `#${player.rank}` : '-';
                }
                if (scoreEl instanceof HTMLElement) {
                    scoreEl.textContent = Number(player.points || 0).toFixed(2);
                }
                if (breakdownEl instanceof HTMLElement) {
                    breakdownEl.textContent = `${player.main_records} Main, ${player.extended_records} Extended, ${player.legacy_records} Legacy`;
                }
                if (hardestEl instanceof HTMLElement) {
                    hardestEl.textContent = String(player.hardest_label || 'None');
                }
                if (contribEl instanceof HTMLElement) {
                    contribEl.textContent = `${player.created_count} Created, ${player.published_count} Published, ${player.verified_count} Verified`;
                }
                renderDemonList(mainCompletedEl, player.main_completed);
                renderDemonList(extendedCompletedEl, player.extended_completed);
                renderDemonList(legacyCompletedEl, player.legacy_completed);
                renderDemonList(createdEl, player.demons_created);
                renderDemonList(publishedEl, player.demons_published);
                renderDemonList(verifiedEl, player.demons_verified);
                renderDemonList(progressEl, player.progress_on, true);

                updateQueryString(player.username, player.user_id ?? null);

                if (detailEl instanceof HTMLElement) {
                    detailEl.classList.remove('is-updating');
                    void detailEl.offsetWidth;
                    detailEl.classList.add('is-updating');
                }
            };

            const syncVisibleList = () => {
                const query = searchInput.value.trim().toLowerCase();
                const selectedCountry = countryFilter instanceof HTMLSelectElement
                    ? countryFilter.value.trim().toUpperCase()
                    : '';
                visibleIndexes = [];

                items.forEach((item) => {
                    const key = item.getAttribute('data-player-key') || '';
                    const index = indexByKey.has(key) ? indexByKey.get(key) : -1;
                    if (index === -1) {
                        item.style.display = 'none';
                        return;
                    }

                    const haystack = String(data[index].search || '').toLowerCase();
                    const itemCountry = (item.getAttribute('data-country-code') || '').trim().toUpperCase();
                    const countryMatches = selectedCountry === '' || itemCountry === selectedCountry;
                    const visible = countryMatches && (query === '' || haystack.includes(query));
                    item.style.display = visible ? '' : 'none';
                    if (visible) {
                        visibleIndexes.push(index);
                    }
                });

                const canNavigate = visibleIndexes.length > 0;
                if (prevButton instanceof HTMLButtonElement) {
                    prevButton.disabled = !canNavigate;
                }
                if (nextButton instanceof HTMLButtonElement) {
                    nextButton.disabled = !canNavigate;
                }

                if (!canNavigate) {
                    return;
                }

                if (!visibleIndexes.includes(currentIndex)) {
                    renderPlayer(visibleIndexes[0]);
                }
            };

            const moveSelection = (step) => {
                if (visibleIndexes.length === 0) {
                    return;
                }

                let visiblePosition = visibleIndexes.indexOf(currentIndex);
                if (visiblePosition < 0) {
                    visiblePosition = 0;
                }

                const nextPosition = (visiblePosition + step + visibleIndexes.length) % visibleIndexes.length;
                renderPlayer(visibleIndexes[nextPosition]);
            };

            items.forEach((item) => {
                const button = item.querySelector('.stats-player-button');
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }

                button.addEventListener('click', () => {
                    const key = item.getAttribute('data-player-key') || '';
                    const index = indexByKey.get(key);
                    if (typeof index === 'number') {
                        renderPlayer(index);
                    }
                });
            });

            searchInput.addEventListener('input', syncVisibleList);

            if (countryFilter instanceof HTMLSelectElement) {
                countryFilter.addEventListener('change', () => {
                    updateCountryQueryString(countryFilter.value);
                    syncVisibleList();
                });
            }

            if (clearSearch instanceof HTMLButtonElement) {
                clearSearch.addEventListener('click', () => {
                    searchInput.value = '';
                    syncVisibleList();
                    searchInput.focus();
                });
            }

            if (prevButton instanceof HTMLButtonElement) {
                prevButton.addEventListener('click', () => moveSelection(-1));
            }

            if (nextButton instanceof HTMLButtonElement) {
                nextButton.addEventListener('click', () => moveSelection(1));
            }

            syncVisibleList();
            renderPlayer(currentIndex);
        })();
        </script>
    <?php endif; ?>
</section>
<?php render_footer(); ?>






