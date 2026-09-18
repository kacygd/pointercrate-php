<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (array_key_exists('at', $_GET) || array_key_exists('timemachine', $_GET)) {
    $target = 'time-machine.php';
    if (array_key_exists('at', $_GET)) {
        $target .= '?at=' . rawurlencode((string) $_GET['at']);
    }
    redirect($target);
}

function card_thumbnail_url(array $demon): string
{
    $configured = trim((string) ($demon['thumbnail_url'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    $videoUrl = trim((string) ($demon['video_url'] ?? ''));
    $youtubeId = youtube_video_id($videoUrl);
    if ($youtubeId !== null && $youtubeId !== '') {
        return 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
    }

    return '';
}

function css_background_image(string $url): string
{
    if ($url === '') {
        return 'background-image: linear-gradient(135deg, #1f3048 0%, #101824 100%);';
    }

    $safe = str_replace(
        ["\\", "'", "\r", "\n"],
        ["\\\\", "\\'", '', ''],
        $url
    );

    return "background-image: url('{$safe}');";
}

function demon_creator_name(array $demon): string
{
    return demon_primary_creator_name($demon);
}

function render_player_role_link(string $name, ?int $userId = null): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '-';
    }

    $labelText = $userId !== null && $userId > 0
        ? (user_public_name_by_id($userId, $trimmed) ?? $trimmed)
        : $trimmed;
    $label = '<b>' . e($labelText) . '</b>';
    if ($userId !== null && $userId > 0) {
        $url = base_url('players.php?uid=' . $userId);
        return '<a class="player-link" href="' . e($url) . '">' . $label . '</a>';
    }

    return $label;
}

function render_creator_credit(array $demon): string
{
    $creators = demon_creator_names($demon);
    if ($creators === []) {
        return '-';
    }

    $primary = array_shift($creators);
    
    // Get user ID for primary creator
    $primaryTrimmed = trim($primary);
    $userStmt = db()->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $userStmt->execute([':username' => $primaryTrimmed]);
    $primaryUserId = (int) ($userStmt->fetchColumn() ?: 0);
    
    $html = render_player_role_link($primary, $primaryUserId > 0 ? $primaryUserId : null);

    if ($creators === []) {
        return $html;
    }

    // Additional creators in tooltip - plain text only
    $tooltipText = implode(', ', array_map(fn($c) => e($c), $creators));
    
    $html .= ' ' . e(t('demon.and')) . ' <span class="tooltip underdotted">';
    $html .= e(t('demon.more'));
    $html .= '<span class="tooltiptext fade">' . $tooltipText . '</span>';
    $html .= '</span>';

    return $html;
}

function render_list_dropdown(string $id, string $title, string $description, array $demons): void
{
    ?>
    <div>
        <div class="button white hover no-shadow js-toggle" data-toggle-group="0" data-dropdown-id="<?= e($id) ?>">
            <?= e($title) ?>
        </div>

        <div class="see-through fade dropdown" id="<?= e($id) ?>">
            <div class="search js-search seperated" style="margin: 10px;">
                <input placeholder="<?= e(t('list.filter')) ?>" type="text">
            </div>
            <p style="margin: 10px;"><?= e($description) ?></p>
            <ul class="flex wrap space">
                <?php if ($demons === []): ?>
                    <li class="white" style="min-width: 100%; width: 100%;"><?= e(t('list.no_entries')) ?></li>
                <?php endif; ?>

                <?php foreach ($demons as $demon): ?>
                    <?php
                    $positionLabel = demonlist_position_label((int) $demon['position'], $id === 'legacy');
                    $positionedName = demonlist_positioned_name((int) $demon['position'], $id === 'legacy', (string) $demon['name']);
                    $dropdownVerifier = trim((string) ($demon['verifier'] ?? ''));
                    $dropdownPublisher = trim((string) ($demon['publisher'] ?? ''));
                    $dropdownPublisherLabel = user_public_name_by_id((int) ($demon['publisher_user_id'] ?? 0), $dropdownPublisher) ?? $dropdownPublisher;
                    $dropdownVerifierLabel = user_public_name_by_id((int) ($demon['verifier_user_id'] ?? 0), $dropdownVerifier) ?? $dropdownVerifier;
                    ?>
                    <li class="hover white" title="<?= e($positionedName) ?>">
                        <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                            <?= e($positionedName) ?>
                            <br>
                            <i><?= e(t('list.published_by')) ?> <?= e($dropdownPublisherLabel) ?><?php if ($dropdownVerifier !== ''): ?>, <?= e(t('list.verified_by')) ?> <?= e($dropdownVerifierLabel) ?><?php endif; ?></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

function time_machine_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone((string) config('app.timezone', 'UTC'));
    return $timezone;
}

function time_machine_format_input(DateTimeInterface $date): string
{
    return DateTimeImmutable::createFromInterface($date)
        ->setTimezone(time_machine_timezone())
        ->format('Y-m-d\TH:i');
}

function time_machine_parse_input(string $value): ?DateTimeImmutable
{
    $normalized = trim($value);
    if ($normalized === '') {
        return null;
    }

    $timezone = time_machine_timezone();
    $formats = [
        'Y-m-d\TH:i',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        DateTimeInterface::RFC3339,
        DateTimeInterface::RFC3339_EXTENDED,
    ];

    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $normalized, $timezone);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    try {
        return new DateTimeImmutable($normalized, $timezone);
    } catch (Throwable) {
        return null;
    }
}

function time_machine_format_banner(DateTimeInterface $date): string
{
    return DateTimeImmutable::createFromInterface($date)
        ->setTimezone(time_machine_timezone())
        ->format('l, F jS Y \a\t g:i:sa \G\M\TP');
}

function time_machine_available_since(PDO $pdo): ?DateTimeImmutable
{
    try {
        $value = $pdo->query('SELECT MIN(created_at) FROM demons')->fetchColumn();
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new DateTimeImmutable($value, time_machine_timezone());
    } catch (Throwable) {
        return null;
    }
}

function time_machine_reconstruct_demons(array $currentDemons, array $futureEvents): array
{
    $demonsById = [];
    foreach ($currentDemons as $demon) {
        $demonId = (int) ($demon['id'] ?? 0);
        if ($demonId < 1) {
            continue;
        }

        $demon['position'] = (int) ($demon['position'] ?? 0);
        $demonsById[$demonId] = $demon;
    }

    foreach ($futureEvents as $event) {
        $demonId = (int) ($event['demon_id'] ?? 0);
        $newPosition = (int) ($event['new_position'] ?? 0);
        $oldPosition = $event['old_position'] !== null ? (int) $event['old_position'] : null;

        if ($demonId < 1 || $newPosition < 1) {
            continue;
        }

        if ($oldPosition === null) {
            if (!isset($demonsById[$demonId])) {
                continue;
            }

            unset($demonsById[$demonId]);
            foreach ($demonsById as &$otherDemon) {
                $position = (int) ($otherDemon['position'] ?? 0);
                if ($position > $newPosition) {
                    $otherDemon['position'] = $position - 1;
                }
            }
            unset($otherDemon);
            continue;
        }

        if (!isset($demonsById[$demonId]) || $oldPosition < 1) {
            continue;
        }

        if ($newPosition < $oldPosition) {
            foreach ($demonsById as $otherId => &$otherDemon) {
                if ($otherId === $demonId) {
                    continue;
                }

                $position = (int) ($otherDemon['position'] ?? 0);
                if ($position > $newPosition && $position <= $oldPosition) {
                    $otherDemon['position'] = $position - 1;
                }
            }
            unset($otherDemon);
        } elseif ($newPosition > $oldPosition) {
            foreach ($demonsById as $otherId => &$otherDemon) {
                if ($otherId === $demonId) {
                    continue;
                }

                $position = (int) ($otherDemon['position'] ?? 0);
                if ($position >= $oldPosition && $position < $newPosition) {
                    $otherDemon['position'] = $position + 1;
                }
            }
            unset($otherDemon);
        }

        $demonsById[$demonId]['position'] = $oldPosition;
    }

    $reconstructed = array_values($demonsById);
    usort($reconstructed, static function (array $a, array $b): int {
        $positionCompare = (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0);
        if ($positionCompare !== 0) {
            return $positionCompare;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $reconstructed;
}

function historical_list_bucket(int $position): string
{
    if ($position < 1) {
        return 'legacy';
    }

    if ($position <= demonlist_main_list_limit()) {
        return 'main';
    }

    if ($position <= demonlist_extended_list_limit()) {
        return demonlist_show_extended_list() ? 'extended' : 'main';
    }

    return demonlist_show_legacy_list() ? 'legacy' : 'main';
}

function roulette_item_from_demon(array $demon, string $bucket, bool $shown): array
{
    $position = (int) ($demon['position'] ?? 0);
    $requirement = (int) ($demon['requirement'] ?? 100);
    $publisher = trim((string) ($demon['publisher'] ?? ''));
    $verifier = trim((string) ($demon['verifier'] ?? ''));
    $publisherLabel = user_public_name_by_id((int) ($demon['publisher_user_id'] ?? 0), $publisher) ?? $publisher;
    $verifierLabel = user_public_name_by_id((int) ($demon['verifier_user_id'] ?? 0), $verifier) ?? $verifier;
    $creator = demon_creator_name($demon);
    $levelId = trim((string) ($demon['level_id'] ?? ''));
    $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
    $score = '';
    if (demonlist_is_ranked_entry($position, $isLegacy)) {
        $score = number_format(pointercrate_score($position, $requirement, $requirement), 2) . ' (' . $requirement . '%) - '
            . number_format(pointercrate_score($position, $requirement, 100), 2) . ' (100%) points';
    }

    return [
        'id' => (int) ($demon['id'] ?? 0),
        'bucket' => $bucket,
        'shown' => $shown,
        'position' => $position,
        'positionLabel' => demonlist_position_label($position, $bucket === 'legacy'),
        'currentPosition' => (int) ($demon['current_position'] ?? $position),
        'name' => (string) ($demon['name'] ?? ''),
        'creator' => $creator !== '' ? $creator : $publisherLabel,
        'url' => base_url((string) $position),
        'videoUrl' => (string) ($demon['video_url'] ?? ''),
        'thumb' => card_thumbnail_url($demon),
        'levelId' => $levelId,
        'byline' => t('list.published_by') . ' ' . $publisherLabel . ($verifierLabel !== '' ? ', ' . t('list.verified_by') . ' ' . $verifierLabel : ''),
        'score' => $score,
    ];
}
function demonlist_list_sort_available_options(array $demons): array
{
    $hasCompletions = false;
    $hasCreatedAt = false;

    foreach ($demons as $demon) {
        if ((int) ($demon['completion_count'] ?? 0) > 0) {
            $hasCompletions = true;
        }
        if (strtotime((string) ($demon['created_at'] ?? '')) !== false) {
            $hasCreatedAt = true;
        }
    }

    $options = [
        '' => t('list.sort_default'),
        'easiest' => t('list.sort_easiest'),
    ];

    $options['requirement_low'] = t('list.sort_requirement_low');
    $options['requirement_high'] = t('list.sort_requirement_high');

    if ($hasCompletions) {
        $options['completions_least'] = t('list.sort_completions_least');
        $options['completions_most'] = t('list.sort_completions_most');
    }
    if ($hasCreatedAt) {
        $options['newest'] = t('list.sort_newest');
        $options['oldest'] = t('list.sort_oldest');
    }

    $options['name_az'] = t('list.sort_name_az');
    $options['name_za'] = t('list.sort_name_za');

    return $options;
}

function demonlist_sort_demons(array $demons, string $sort): array
{
    if ($sort === '') {
        return $demons;
    }

    usort($demons, static function (array $a, array $b) use ($sort): int {
        $byPosition = static fn (array $row): int => (int) ($row['position'] ?? 0);

        switch ($sort) {
            case 'easiest':
                $direction = -1;
                return $direction * ($byPosition($a) <=> $byPosition($b));

            case 'requirement_low':
            case 'requirement_high':
                $direction = $sort === 'requirement_low' ? 1 : -1;
                return $direction * (((int) ($a['requirement'] ?? 100)) <=> ((int) ($b['requirement'] ?? 100)));

            case 'completions_least':
            case 'completions_most':
                $direction = $sort === 'completions_least' ? 1 : -1;
                return $direction * (((int) ($a['completion_count'] ?? 0)) <=> ((int) ($b['completion_count'] ?? 0)));

            case 'newest':
            case 'oldest':
                $direction = $sort === 'oldest' ? 1 : -1;
                $aTime = strtotime((string) ($a['created_at'] ?? ''));
                $bTime = strtotime((string) ($b['created_at'] ?? ''));
                if ($aTime === false || $bTime === false) {
                    if ($aTime !== $bTime) {
                        return $aTime === false ? 1 : -1;
                    }
                    return $byPosition($a) <=> $byPosition($b);
                }
                return $direction * ($aTime <=> $bTime);

            case 'name_az':
                $nameCompare = strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                return $nameCompare !== 0 ? $nameCompare : ($byPosition($a) <=> $byPosition($b));

            case 'name_za':
                $nameCompare = strcasecmp((string) ($b['name'] ?? ''), (string) ($a['name'] ?? ''));
                return $nameCompare !== 0 ? $nameCompare : ($byPosition($a) <=> $byPosition($b));
        }

        return $byPosition($a) <=> $byPosition($b);
    });

    return $demons;
}

$pdo = db();

$pdo = db();
$hasUserBannedColumn = users_has_is_banned_column($pdo);

if ($hasUserBannedColumn) {
    $allDemons = $pdo->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count, ej.average_enjoyment
                              FROM demons d
                              LEFT JOIN (
                                  SELECT c.demon_id, COUNT(*) AS completion_count
                                  FROM completions c
                                  LEFT JOIN users banned_users
                                    ON LOWER(banned_users.username) = LOWER(c.player)
                                   AND COALESCE(banned_users.is_banned, 0) = 1
                                  WHERE banned_users.id IS NULL
                                    AND c.progress >= 100
                                  GROUP BY c.demon_id
                              ) cc ON cc.demon_id = d.id
                              LEFT JOIN (
                                  SELECT c.demon_id, AVG(c.enjoyment) AS average_enjoyment
                                  FROM completions c
                                  LEFT JOIN users banned_users
                                    ON LOWER(banned_users.username) = LOWER(c.player)
                                   AND COALESCE(banned_users.is_banned, 0) = 1
                                  WHERE banned_users.id IS NULL
                                    AND c.enjoyment IS NOT NULL
                                  GROUP BY c.demon_id
                              ) ej ON ej.demon_id = d.id
                              ORDER BY d.position ASC')->fetchAll();
} else {
    $allDemons = $pdo->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count, ej.average_enjoyment
                              FROM demons d
                              LEFT JOIN (
                                  SELECT c.demon_id, COUNT(*) AS completion_count
                                  FROM completions c
                                  WHERE c.progress >= 100
                                  GROUP BY c.demon_id
                              ) cc ON cc.demon_id = d.id
                              LEFT JOIN (
                                  SELECT c.demon_id, AVG(c.enjoyment) AS average_enjoyment
                                  FROM completions c
                                  WHERE c.enjoyment IS NOT NULL
                                  GROUP BY c.demon_id
                              ) ej ON ej.demon_id = d.id
                              ORDER BY d.position ASC')->fetchAll();
}

foreach ($allDemons as &$demon) {
    $demon['position'] = (int) ($demon['position'] ?? 0);
    $demon['current_position'] = (int) ($demon['position'] ?? 0);
}
unset($demon);

$isTimeMachineView = false;

$main = [];
$extended = [];
$legacy = [];
$showcase = [];
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();

foreach ($allDemons as $demon) {
    $position = (int) $demon['position'];
    $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
    $listBucket = $isTimeMachineView
        ? historical_list_bucket($position)
        : demonlist_list_bucket($position, $isLegacy);

    if ($listBucket === 'main') {
        $main[] = $demon;
        $showcase[] = $demon;
        continue;
    }

    if ($listBucket === 'extended') {
        $extended[] = $demon;
        $showcase[] = $demon;
        continue;
    }

    $legacy[] = $demon;
}

function demonlist_list_tags_from_get(): array
{
    $raw = $_GET['tag'] ?? [];
    $values = is_array($raw) ? $raw : [$raw];

    return array_values(array_unique(array_map(
        static fn ($value): string => strtolower(trim((string) $value)),
        array_filter($values, static fn ($value): bool => trim((string) $value) !== '')
    )));
}

function demonlist_filter_demons_by_tags(array $demons, array $tagNames, array $tagsMap): array
{
    if ($tagNames === []) {
        return $demons;
    }

    return array_values(array_filter(
        $demons,
        static function (array $demon) use ($tagNames, $tagsMap): bool {
            $demonTagNames = array_map(
                static fn (array $tag): string => strtolower(trim((string) $tag['name'])),
                $tagsMap[(int) ($demon['id'] ?? 0)] ?? []
            );

            foreach ($tagNames as $wantedTag) {
                if (!in_array($wantedTag, $demonTagNames, true)) {
                    return false;
                }
            }

            return true;
        }
    ));
}

$listSortOptions = demonlist_list_sort_available_options($showcase);
$listSortValue = trim((string) ($_GET['sort'] ?? ''));
$listSort = array_key_exists($listSortValue, $listSortOptions) ? $listSortValue : '';

$listTags = array_values(array_filter(
    tag_fetch_all($pdo),
    static fn (array $tag): bool => (int) ($tag['usage_count'] ?? 0) > 0
));
$listSelectedTags = demonlist_list_tags_from_get();
if ($listSelectedTags !== []) {
    $showcase = demonlist_filter_demons_by_tags(
        $showcase,
        $listSelectedTags,
        demon_tag_map_for_demons($pdo, array_map(static fn (array $demon): int => (int) ($demon['id'] ?? 0), $showcase))
    );
}

function demonlist_list_enjoyment_bound(mixed $raw): ?float
{
    $raw = trim((string) ($raw ?? ''));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    $value = (float) $raw;
    if ($value < 0.0 || $value > 10.0) {
        return null;
    }

    return $value;
}

function demonlist_demon_enjoyment(array $demon): ?float
{
    $raw = $demon['average_enjoyment'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }

    return (float) $raw;
}

function demonlist_filter_demons_by_enjoyment(array $demons, ?float $min, ?float $max): array
{
    if ($min === null && $max === null) {
        return $demons;
    }

    return array_values(array_filter(
        $demons,
        static function (array $demon) use ($min, $max): bool {
            $enjoyment = demonlist_demon_enjoyment($demon);
            if ($enjoyment === null) {
                return false;
            }
            if ($min !== null && $enjoyment < $min) {
                return false;
            }
            if ($max !== null && $enjoyment > $max) {
                return false;
            }

            return true;
        }
    ));
}

$listEnjoymentMin = demonlist_list_enjoyment_bound($_GET['enj_min'] ?? null);
$listEnjoymentMax = demonlist_list_enjoyment_bound($_GET['enj_max'] ?? null);
if ($listEnjoymentMin !== null && $listEnjoymentMax !== null && $listEnjoymentMin > $listEnjoymentMax) {
    [$listEnjoymentMin, $listEnjoymentMax] = [$listEnjoymentMax, $listEnjoymentMin];
}
$listEnjoymentActive = $listEnjoymentMin !== null || $listEnjoymentMax !== null;
if ($listEnjoymentActive) {
    $showcase = demonlist_filter_demons_by_enjoyment($showcase, $listEnjoymentMin, $listEnjoymentMax);
}

if ($listSort !== '') {
    $showcase = demonlist_sort_demons($showcase, $listSort);
}

function demonlist_build_list_url(array $params): string
{
    $pairs = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }

        if ($key === 'tag') {
            $tags = is_array($value) ? $value : [$value];
            foreach ($tags as $tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $pairs[] = 'tag[]=' . rawurlencode($tag);
            }
            continue;
        }

        if ($value === '') {
            continue;
        }

        $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    $query = implode('&', $pairs);
    $url = base_url('index.php');

    return $query === '' ? $url : $url . '?' . $query;
}

$listSortParams = $_GET;
unset($listSortParams['tag_reopen']);
$listSortUrl = static function (string $sort) use ($listSortParams): string {
    $params = $listSortParams;
    if ($sort === '') {
        unset($params['sort']);
    } else {
        $params['sort'] = $sort;
    }
    return demonlist_build_list_url($params);
};
$listClearTagsUrl = static function () use ($listSortParams): string {
    $params = $listSortParams;
    unset($params['tag'], $params['enj_min'], $params['enj_max']);
    return demonlist_build_list_url($params);
};
$listTagToggleUrl = static function (string $tagName) use ($listSortParams): string {
    $params = $listSortParams;
    $rawTags = $params['tag'] ?? [];
    $currentTags = is_array($rawTags) ? $rawTags : ($rawTags !== null && $rawTags !== '' ? [$rawTags] : []);

    $wanted = strtolower(trim($tagName));
    $found = false;
    foreach ($currentTags as $index => $currentTag) {
        if (strtolower(trim((string) $currentTag)) === $wanted) {
            $found = true;
            unset($currentTags[$index]);
        }
    }

    if (!$found) {
        $currentTags[] = $tagName;
    }

    $currentTags = array_values($currentTags);
    if ($currentTags === []) {
        unset($params['tag']);
    } else {
        $params['tag'] = $currentTags;
    }

    return demonlist_build_list_url($params);
};

$listEditorsSql = 'SELECT username, ' . user_select_display_name_expression() . ', country_code, youtube_channel
                   FROM users
                   WHERE role IN ("owner", "list_editor")';
if ($hasUserBannedColumn) {
    $listEditorsSql .= ' AND COALESCE(is_banned, 0) = 0';
}
$listEditorsSql .= '
                   ORDER BY created_at ASC, username ASC
                   LIMIT 20';
$listEditors = $pdo->query($listEditorsSql)->fetchAll();

$listHelpersSql = 'SELECT username, ' . user_select_display_name_expression() . ', country_code, youtube_channel
                   FROM users
                   WHERE role = "list_helper"';
if ($hasUserBannedColumn) {
    $listHelpersSql .= ' AND COALESCE(is_banned, 0) = 0';
}
$listHelpersSql .= '
                   ORDER BY created_at ASC, username ASC
                   LIMIT 20';
$listHelpers = $pdo->query($listHelpersSql)->fetchAll();

$discordWidgetUrl = discord_server_widget_url();
$pageDescription = (!$showExtendedList && !$showLegacyList)
    ? 'All ranked demons are currently merged into one Main List.'
    : 'Ranked demons with Main, Extended, and Legacy sections.';
$mainListDescription = demonlist_main_list_dropdown_description($showExtendedList, $showLegacyList);
$extendedListDescription = demonlist_extended_list_dropdown_description(true);
$legacyListDescription = demonlist_legacy_list_dropdown_description();
$mainIntro = (!$showExtendedList && !$showLegacyList)
    ? t('home.intro_all')
    : t('home.intro_default');

render_header(t('home.title'), 'list', [
    'title' => t('home.title'),
    'description' => $pageDescription,
    'url' => base_url('index.php'),
]);
?>

<nav class="flex wrap m-center fade" id="lists" style="text-align: center;">
    <?php render_list_dropdown('mainlist', 'Main List', $mainListDescription, $main); ?>
    <?php if ($showExtendedList): ?>
        <?php render_list_dropdown('extended', 'Extended List', $extendedListDescription, $extended); ?>
    <?php endif; ?>
    <?php if ($showLegacyList): ?>
        <?php render_list_dropdown('legacy', 'Legacy List', $legacyListDescription, $legacy); ?>
    <?php endif; ?>
</nav>

<div class="flex m-center container">
    <main class="left">
        <section class="panel fade" style="overflow: visible; z-index: 50;">
            <h1><?= e(t('home.heading')) ?></h1>
            <p style="margin-top: 0;"><?= e($mainIntro) ?></p>
            <div class="search seperated" style="margin: 10px 0;">
                <input placeholder="<?= e(t('list.filter_shown')) ?>" type="text" data-live-search>
                <div class="list-sort-dropdown">
                    <button type="button" class="list-sort-button js-toggle<?= ($listSort !== '' || $listSelectedTags !== [] || $listEnjoymentActive) ? ' is-active' : '' ?>" data-dropdown-id="list-sort-menu" aria-label="<?= e(t('list.sort_label')) ?>"></button>
                    <div class="see-through fade dropdown" id="list-sort-menu">
                        <div class="list-sort-panel">
                            <div class="list-sort-panel-col">
                                <p class="list-sort-panel-label"><?= e(t('list.sort_column')) ?> <span class="list-name-dir-buttons"><a class="list-name-dir<?= $listSort === 'name_az' ? ' is-on' : '' ?>" href="<?= e($listSortUrl('name_az')) ?>" title="<?= e(t('list.sort_name_az')) ?>" aria-label="<?= e(t('list.sort_name_az')) ?>">A&#8593;Z</a><a class="list-name-dir<?= $listSort === 'name_za' ? ' is-on' : '' ?>" href="<?= e($listSortUrl('name_za')) ?>" title="<?= e(t('list.sort_name_za')) ?>" aria-label="<?= e(t('list.sort_name_za')) ?>">Z&#8595;A</a></span></p>
                                <ul class="list-sort-menu">
                                    <?php foreach ($listSortOptions as $sortKey => $sortLabel): ?>
                                        <?php if ($sortKey === 'name_az' || $sortKey === 'name_za') continue; ?>
                                        <li class="hover<?= $sortKey === $listSort ? ' selected' : '' ?>">
                                            <a href="<?= e($listSortUrl($sortKey)) ?>"><?= e($sortLabel) ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <?php if (true): ?>
                                <div class="list-tag-filter-form">
                                    <p class="list-tag-filter-label"><?= e(t('list.sort_tags')) ?><?php if ($listSelectedTags !== [] || $listEnjoymentActive): ?> <a class="list-tag-filter-clear" href="<?= e($listClearTagsUrl()) ?>"><?= e(t('common.clear')) ?></a><?php endif; ?></p>
                                    <div class="list-tag-filter-options">
                                        <?php foreach ($listTags as $listTag): ?>
                                            <?php
                                            $listTagName = (string) $listTag['name'];
                                            $listTagNameLower = strtolower(trim($listTagName));
                                            $listTagIsOn = in_array($listTagNameLower, $listSelectedTags, true);
                                            $listTagColor = normalize_tag_color($listTag['color'] ?? null);
                                            $listTagChipStyle = $listTagIsOn
                                                ? e(demon_tag_background_style($listTag)) . ' border-color: ' . $listTagColor . ';'
                                                : 'border-color: ' . $listTagColor . '; color: inherit; background: transparent;';
                                            $listTagToggleHref = $listTagToggleUrl($listTagName);
                                            $listTagToggleHref .= (str_contains($listTagToggleHref, '?') ? '&' : '?') . 'tag_reopen=1';
                                            ?>
                                            <a class="list-tag-chip<?= $listTagIsOn ? ' is-on' : '' ?>" href="<?= e($listTagToggleHref) ?>" style="<?= $listTagChipStyle ?>">
                                                <span class="list-tag-chip-dot"></span><?= e($listTagName) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <form class="list-enjoyment-filter" id="list-enjoyment-filter" method="get" action="<?= e(base_url('index.php')) ?>">
                                        <input type="hidden" name="tag_reopen" value="1">
                                        <?php foreach ($listSortParams as $listEnjoyKey => $listEnjoyValue): ?>
                                            <?php if ($listEnjoyKey === 'enj_min' || $listEnjoyKey === 'enj_max' || $listEnjoyKey === 'tag_reopen') continue; ?>
                                            <?php if (is_array($listEnjoyValue)): ?>
                                                <?php foreach ($listEnjoyValue as $listEnjoyItem): ?>
                                                    <input type="hidden" name="<?= e($listEnjoyKey) ?>[]" value="<?= e((string) $listEnjoyItem) ?>">
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <input type="hidden" name="<?= e($listEnjoyKey) ?>" value="<?= e((string) $listEnjoyValue) ?>">
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <p class="list-enjoyment-label"><?= e(t('list.sort_enjoyment')) ?></p>
                                        <div class="list-enjoyment-inputs">
                                            <input type="number" class="list-enjoyment-num" name="enj_min" min="0" max="10" step="0.5" inputmode="decimal" placeholder="0" aria-label="<?= e(t('list.sort_enjoyment_min')) ?>" value="<?= $listEnjoymentMin !== null ? e(rtrim(rtrim(number_format($listEnjoymentMin, 1, '.', ''), '0'), '.')) : '' ?>">
                                            <div class="list-enjoyment-slider">
                                                <div class="list-enjoyment-track" aria-hidden="true">
                                                    <div class="list-enjoyment-fill" id="list-enjoyment-fill"></div>
                                                </div>
                                                <input type="range" id="list-enjoyment-lo" min="0" max="10" step="0.5" aria-label="<?= e(t('list.sort_enjoyment_min')) ?>" value="<?= $listEnjoymentMin !== null ? e(rtrim(rtrim(number_format($listEnjoymentMin, 1, '.', ''), '0'), '.')) : '0' ?>">
                                                <input type="range" id="list-enjoyment-hi" min="0" max="10" step="0.5" aria-label="<?= e(t('list.sort_enjoyment_max')) ?>" value="<?= $listEnjoymentMax !== null ? e(rtrim(rtrim(number_format($listEnjoymentMax, 1, '.', ''), '0'), '.')) : '10' ?>">
                                            </div>
                                            <input type="number" class="list-enjoyment-num" name="enj_max" min="0" max="10" step="0.5" inputmode="decimal" placeholder="10" aria-label="<?= e(t('list.sort_enjoyment_max')) ?>" value="<?= $listEnjoymentMax !== null ? e(rtrim(rtrim(number_format($listEnjoymentMax, 1, '.', ''), '0'), '.')) : '' ?>">
                                            <button type="submit" class="list-enjoyment-apply"><?= e(t('common.apply')) ?></button>
                                        </div>
                                    </form>
                        </div>
                    </div>
                </div>
        </section>

        <?php foreach ($showcase as $demon): ?>
            <?php
            $thumb = card_thumbnail_url($demon);
            $thumbStyle = css_background_image($thumb);
            $creator = demon_creator_name($demon);
            $creatorSearchText = implode(' ', demon_creator_names($demon));
            $publisher = trim((string) ($demon['publisher'] ?? ''));
            $verifier = trim((string) ($demon['verifier'] ?? ''));
            $publisherUserId = isset($demon['publisher_user_id']) ? (int) $demon['publisher_user_id'] : 0;
            $verifierUserId = isset($demon['verifier_user_id']) ? (int) $demon['verifier_user_id'] : 0;
            $publisherLabel = user_public_name_by_id($publisherUserId > 0 ? $publisherUserId : null, $publisher) ?? $publisher;
            $verifierLabel = user_public_name_by_id($verifierUserId > 0 ? $verifierUserId : null, $verifier) ?? $verifier;
            $cardSearchText = strtolower((string) ($demon['name'] . ' ' . $creatorSearchText . ' ' . $publisher . ' ' . $publisherLabel . ' ' . $verifier . ' ' . $verifierLabel . ' ' . $demon['difficulty']
                . ' ' . trim((string) ($demon['level_id'] ?? ''))
                . ' ' . trim((string) ($demon['level_length'] ?? ''))
                . ' ' . (($demon['object_count'] ?? null) !== null ? (string) (int) $demon['object_count'] : '')));
            $requirement = (int) $demon['requirement'];
            $position = (int) $demon['position'];
            $currentPosition = (int) ($demon['current_position'] ?? $position);
            $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
            $showDemonPoints = demonlist_is_ranked_entry($position, $isLegacy);
            $minimumScore = $showDemonPoints ? number_format(pointercrate_score($position, $requirement, $requirement), 2) : '0.00';
            $fullScore = $showDemonPoints ? number_format(pointercrate_score($position, $requirement, 100), 2) : '0.00';
            $bucket = $isTimeMachineView
                ? historical_list_bucket($position)
                : demonlist_list_bucket($position, $isLegacy);
            $positionedName = demonlist_positioned_name($position, $bucket === 'legacy', (string) $demon['name']);
            ?>
            <section
                class="panel fade flex mobile-col"
                style="overflow: hidden;"
                data-search-value="<?= e($cardSearchText) ?>"
                data-roulette-target="<?= e((string) ($demon['id'] ?? 0)) ?>"
                data-roulette-bucket="<?= e($bucket) ?>"
            >
                <a
                    class="thumb ratio-16-9"
                    href="<?= e(base_url((string) ((int) $demon['position']))) ?>"
                    style="position: relative; <?= e($thumbStyle) ?>"
                ></a>
                <div class="flex demon-info" style="align-items: center;">
                    <div class="demon-byline">
                        <h2 style="text-align: left; margin-bottom: 0;">
                            <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                                <?= e($positionedName) ?>
                            </a>
                        </h2>
                        <h3 class="demon-card-byline" style="text-align: left; margin-bottom: 0;">
                            <?= e(t('list.published_by')) ?> <?= render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) ?><?php if ($verifier !== ''): ?>, <?= e(t('list.verified_by')) ?> <?= render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) ?><?php endif; ?>
                        </h3>
                        <?php if ($showDemonPoints): ?>
                            <div class="demon-points" style="text-align: left; font-size: 0.8em;">
                                <?= $minimumScore ?> (<?= $requirement ?>%) &#8212; <?= $fullScore ?> (100%) <?= e(t('list.points')) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($isTimeMachineView): ?>
                            <div class="muted" style="text-align: left; font-size: 0.85em; margin-top: 4px;">
                                <?= historical_list_bucket($currentPosition) === 'legacy' ? e(t('list.currently_legacy')) : e(t('list.currently_rank', ['rank' => $currentPosition])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </main>

    <aside class="right">
        <section id="staff-contacts" class="panel fade staff-contact-panel">
            <div class="staff-contact-subsection">
                <h2 class="underlined pad"><?= e(t('home.editors')) ?></h2>
                <p class="staff-contact-note">
                    <?= e(t('home.editors_note')) ?>
                </p>
                <ul class="staff-contact-list">
                    <?php if ($listEditors === []): ?>
                        <li class="staff-contact-empty"><?= e(t('home.no_editors')) ?></li>
                    <?php endif; ?>
                    <?php foreach ($listEditors as $editor): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($editor['country_code'] ?? ''));
                        $prefix = country_flag_html($countryCode, true);
                        $youtubeChannel = trim((string) ($editor['youtube_channel'] ?? ''));
                        $username = e(user_display_name_from_row($editor));
                        ?>
                        <li>
                            <b><?= $prefix ?><?php if ($youtubeChannel !== ''): ?><a target="_blank" rel="noreferrer" href="<?= e($youtubeChannel) ?>" title="<?= e(t('home.youtube_channel')) ?>" style="color: inherit; text-decoration: none;"><?= $username ?></a><?php else: ?><?= $username ?><?php endif; ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="staff-contact-subsection">
                <h2 class="underlined pad"><?= e(t('home.helpers')) ?></h2>
                <p class="staff-contact-note">
                    <?= e(t('home.helpers_note')) ?>
                </p>
                <ul class="staff-contact-list">
                    <?php if ($listHelpers === []): ?>
                        <li class="staff-contact-empty"><?= e(t('home.no_helpers')) ?></li>
                    <?php endif; ?>
                    <?php foreach ($listHelpers as $helper): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($helper['country_code'] ?? ''));
                        $prefix = country_flag_html($countryCode, true);
                        $youtubeChannel = trim((string) ($helper['youtube_channel'] ?? ''));
                        $username = e(user_display_name_from_row($helper));
                        ?>
                        <li>
                            <b><?= $prefix ?><?php if ($youtubeChannel !== ''): ?><a target="_blank" rel="noreferrer" href="<?= e($youtubeChannel) ?>" title="<?= e(t('home.youtube_channel')) ?>" style="color: inherit; text-decoration: none;"><?= $username ?></a><?php else: ?><?= $username ?><?php endif; ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section id="rules" class="panel fade">
            <h2 class="underlined pad clickable"><?= e(t('home.guidelines_title')) ?></h2>
            <p><?= e(t('home.guidelines_text')) ?></p>
            <a class="blue hover button" href="<?= e(base_url('guidelines.php')) ?>"><?= e(t('home.guidelines_button')) ?></a>
        </section>

        <section id="submit" class="panel fade">
            <h2 class="underlined pad"><?= e(t('home.submit_title')) ?></h2>
            <p>
                <?= e(t('home.submit_text')) ?>
            </p>
            <a class="blue hover button" href="<?= e(base_url('submit.php')) ?>"><?= e(t('home.submit_button')) ?></a>
        </section>

        <section id="stats-viewer" class="panel fade">
            <h2 class="underlined pad"><?= e(t('home.stats_title')) ?></h2>
            <p>
                <?= e(t('home.stats_text')) ?>
            </p>
            <a class="blue hover button" href="<?= e(base_url('players.php')) ?>"><?= e(t('home.stats_button')) ?></a>
        </section>

        <?php if ($discordWidgetUrl !== null): ?>
            <section id="discord" class="panel fade">
                <h2 class="underlined pad"><?= e(t('home.discord_title')) ?></h2>
                <div class="discord-widget-wrap">
                    <iframe
                        class="discord-widget-frame"
                        src="<?= e($discordWidgetUrl) ?>"
                        title="<?= e(t('home.discord_title')) ?>"
                        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                    ></iframe>
                </div>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php render_footer(); ?>
