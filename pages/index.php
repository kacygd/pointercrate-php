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
    
    $html .= ' and <span class="tooltip underdotted">';
    $html .= 'more';
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
                <input placeholder="Filter..." type="text">
            </div>
            <p style="margin: 10px;"><?= e($description) ?></p>
            <ul class="flex wrap space">
                <?php if ($demons === []): ?>
                    <li class="white" style="min-width: 100%; width: 100%;">No entries in this list.</li>
                <?php endif; ?>

                <?php foreach ($demons as $demon): ?>
                    <?php
                    $dropdownVerifier = trim((string) ($demon['verifier'] ?? ''));
                    $dropdownPublisher = trim((string) ($demon['publisher'] ?? ''));
                    $dropdownPublisherLabel = user_public_name_by_id((int) ($demon['publisher_user_id'] ?? 0), $dropdownPublisher) ?? $dropdownPublisher;
                    $dropdownVerifierLabel = user_public_name_by_id((int) ($demon['verifier_user_id'] ?? 0), $dropdownVerifier) ?? $dropdownVerifier;
                    ?>
                    <li class="hover white" title="#<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>">
                        <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                            #<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>
                            <br>
                            <i>published by <?= e($dropdownPublisherLabel) ?><?php if ($dropdownVerifier !== ''): ?>, verified by <?= e($dropdownVerifierLabel) ?><?php endif; ?></i>
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

    return [
        'id' => (int) ($demon['id'] ?? 0),
        'bucket' => $bucket,
        'shown' => $shown,
        'position' => $position,
        'currentPosition' => (int) ($demon['current_position'] ?? $position),
        'name' => (string) ($demon['name'] ?? ''),
        'creator' => $creator !== '' ? $creator : $publisherLabel,
        'url' => base_url((string) $position),
        'videoUrl' => (string) ($demon['video_url'] ?? ''),
        'thumb' => card_thumbnail_url($demon),
        'levelId' => $levelId,
        'byline' => 'published by ' . $publisherLabel . ($verifierLabel !== '' ? ', verified by ' . $verifierLabel : ''),
        'score' => number_format(pointercrate_score($position, $requirement, $requirement), 2) . ' (' . $requirement . '%) - '
            . number_format(pointercrate_score($position, $requirement, 100), 2) . ' (100%) points',
    ];
}

$pdo = db();
$hasUserBannedColumn = users_has_is_banned_column($pdo);

if ($hasUserBannedColumn) {
    $allDemons = $pdo->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count
                              FROM demons d
                              LEFT JOIN (
                                  SELECT c.demon_id, COUNT(*) AS completion_count
                                  FROM completions c
                                  LEFT JOIN users banned_users
                                    ON LOWER(banned_users.username) = LOWER(c.player)
                                   AND COALESCE(banned_users.is_banned, 0) = 1
                                  WHERE banned_users.id IS NULL
                                  GROUP BY c.demon_id
                              ) cc ON cc.demon_id = d.id
                              ORDER BY d.position ASC')->fetchAll();
} else {
    $allDemons = $pdo->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count
                              FROM demons d
                              LEFT JOIN (
                                  SELECT c.demon_id, COUNT(*) AS completion_count
                                  FROM completions c
                                  GROUP BY c.demon_id
                              ) cc ON cc.demon_id = d.id
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
    ? 'The main list currently shows every ranked demon with no section limits.'
    : 'The main list of the Demonlist with ranked hardest levels in the game.';

render_header('Main List', 'list', [
    'title' => 'Main List',
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
        <section class="panel fade">
            <h1>Geometry Dash Demonlist</h1>
            <p style="margin-top: 0;"><?= e($mainIntro) ?></p>
            <div class="search seperated" style="margin: 10px 0;">
                <input placeholder="Filter shown demons..." type="text" data-live-search>
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
            $cardSearchText = strtolower((string) ($demon['name'] . ' ' . $creatorSearchText . ' ' . $publisher . ' ' . $publisherLabel . ' ' . $verifier . ' ' . $verifierLabel . ' ' . $demon['difficulty']));
            $requirement = (int) $demon['requirement'];
            $position = (int) $demon['position'];
            $currentPosition = (int) ($demon['current_position'] ?? $position);
            $minimumScore = number_format(pointercrate_score($position, $requirement, $requirement), 2);
            $fullScore = number_format(pointercrate_score($position, $requirement, 100), 2);
            $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
            $bucket = $isTimeMachineView
                ? historical_list_bucket($position)
                : demonlist_list_bucket($position, $isLegacy);
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
                                #<?= $position ?> &#8211; <?= e((string) $demon['name']) ?>
                            </a>
                        </h2>
                        <h3 class="demon-card-byline" style="text-align: left; margin-bottom: 0;">
                            published by <?= render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) ?><?php if ($verifier !== ''): ?>, verified by <?= render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) ?><?php endif; ?>
                        </h3>
                        <div class="demon-points" style="text-align: left; font-size: 0.8em;">
                            <?= $minimumScore ?> (<?= $requirement ?>%) &#8212; <?= $fullScore ?> (100%) points
                        </div>
                        <?php if ($isTimeMachineView): ?>
                            <div class="muted" style="text-align: left; font-size: 0.85em; margin-top: 4px;">
                                <?= historical_list_bucket($currentPosition) === 'legacy' ? 'Currently Legacy' : 'Currently #' . e((string) $currentPosition) ?>
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
                <h2 class="underlined pad">List Editors</h2>
                <p class="staff-contact-note">
                    Contact any of these people if you have problems with the list or want to see a specific thing changed.
                </p>
                <ul class="staff-contact-list">
                    <?php if ($listEditors === []): ?>
                        <li class="staff-contact-empty">No list editors yet.</li>
                    <?php endif; ?>
                    <?php foreach ($listEditors as $editor): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($editor['country_code'] ?? ''));
                        $prefix = country_flag_html($countryCode, true);
                        $youtubeChannel = trim((string) ($editor['youtube_channel'] ?? ''));
                        $username = e(user_display_name_from_row($editor));
                        ?>
                        <li>
                            <b><?= $prefix ?><?php if ($youtubeChannel !== ''): ?><a target="_blank" rel="noreferrer" href="<?= e($youtubeChannel) ?>" title="YouTube Channel" style="color: inherit; text-decoration: none;"><?= $username ?></a><?php else: ?><?= $username ?><?php endif; ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="staff-contact-subsection">
                <h2 class="underlined pad">List Helpers</h2>
                <p class="staff-contact-note">
                    Contact these people if you have any questions regarding why a specific record was rejected.
                    Do not needlessly bug them about checking submissions though!
                </p>
                <ul class="staff-contact-list">
                    <?php if ($listHelpers === []): ?>
                        <li class="staff-contact-empty">No list helpers yet.</li>
                    <?php endif; ?>
                    <?php foreach ($listHelpers as $helper): ?>
                        <?php
                        $countryCode = normalize_country_code((string) ($helper['country_code'] ?? ''));
                        $prefix = country_flag_html($countryCode, true);
                        $youtubeChannel = trim((string) ($helper['youtube_channel'] ?? ''));
                        $username = e(user_display_name_from_row($helper));
                        ?>
                        <li>
                            <b><?= $prefix ?><?php if ($youtubeChannel !== ''): ?><a target="_blank" rel="noreferrer" href="<?= e($youtubeChannel) ?>" title="YouTube Channel" style="color: inherit; text-decoration: none;"><?= $username ?></a><?php else: ?><?= $username ?><?php endif; ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section id="rules" class="panel fade">
            <h2 class="underlined pad clickable">Guidelines</h2>
            <p>Only legitimate, unedited runs with clear proof are accepted. All records are reviewed manually.</p>
            <a class="blue hover button" href="<?= e(base_url('guidelines.php')) ?>">Submission Guidelines</a>
        </section>

        <section id="submit" class="panel fade">
            <h2 class="underlined pad">Submit Record</h2>
            <p>
                Note: Please do not submit nonsense, it only makes it harder for us all and will get you banned. 
                Also note that the form rejects duplicate submissions.
            </p>
            <a class="blue hover button" href="<?= e(base_url('submit.php')) ?>">Open Submit</a>
        </section>

        <section id="stats-viewer" class="panel fade">
            <h2 class="underlined pad">Stats Viewer</h2>
            <p>
                Get a detailed overview of who completed the most, created the most demons, or beat the hardest demons.
                Compare your progress and climb the leaderboard.
            </p>
            <a class="blue hover button" href="<?= e(base_url('players.php')) ?>">Open stats viewer!</a>
        </section>

        <?php if ($discordWidgetUrl !== null): ?>
            <section id="discord" class="panel fade">
                <h2 class="underlined pad">Discord Server</h2>
                <div class="discord-widget-wrap">
                    <iframe
                        class="discord-widget-frame"
                        src="<?= e($discordWidgetUrl) ?>"
                        title="Discord Server"
                        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                    ></iframe>
                </div>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php render_footer(); ?>
