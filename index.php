<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

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
    $creator = trim((string) ($demon['creator'] ?? ''));
    if ($creator !== '') {
        return $creator;
    }

    return trim((string) ($demon['publisher'] ?? ''));
}

function render_player_role_link(string $name, ?int $userId = null): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '-';
    }

    $label = '<b>' . e($trimmed) . '</b>';
    if ($userId !== null && $userId > 0) {
        $url = base_url('players.php?uid=' . $userId);
        return '<a class="player-link" href="' . e($url) . '">' . $label . '</a>';
    }

    return $label;
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
                    <li class="hover white" title="#<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>">
                        <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                            #<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>
                            <br>
                            <i>published by <?= e((string) $demon['publisher']) ?></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

$hasUserBannedColumn = users_has_is_banned_column();

if ($hasUserBannedColumn) {
    $allDemons = db()->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count
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
    $allDemons = db()->query('SELECT d.*, COALESCE(cc.completion_count, 0) AS completion_count
                              FROM demons d
                              LEFT JOIN (
                                  SELECT c.demon_id, COUNT(*) AS completion_count
                                  FROM completions c
                                  GROUP BY c.demon_id
                              ) cc ON cc.demon_id = d.id
                              ORDER BY d.position ASC')->fetchAll();
}

$main = [];
$extended = [];
$legacy = [];
$showcase = [];
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();

foreach ($allDemons as $demon) {
    $position = (int) $demon['position'];
    $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;
    $listBucket = demonlist_list_bucket($position, $isLegacy);

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

$listEditorsSql = 'SELECT username, country_code
                   FROM users
                   WHERE role IN ("owner", "list_editor")';
if ($hasUserBannedColumn) {
    $listEditorsSql .= ' AND COALESCE(is_banned, 0) = 0';
}
$listEditorsSql .= '
                   ORDER BY created_at ASC, username ASC
                   LIMIT 20';
$listEditors = db()->query($listEditorsSql)->fetchAll();

$listHelpersSql = 'SELECT username, country_code
                   FROM users
                   WHERE role = "list_helper"';
if ($hasUserBannedColumn) {
    $listHelpersSql .= ' AND COALESCE(is_banned, 0) = 0';
}
$listHelpersSql .= '
                   ORDER BY created_at ASC, username ASC
                   LIMIT 20';
$listHelpers = db()->query($listHelpersSql)->fetchAll();

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
            $publisher = trim((string) ($demon['publisher'] ?? ''));
            $verifier = trim((string) ($demon['verifier'] ?? ''));
            $publisherUserId = isset($demon['publisher_user_id']) ? (int) $demon['publisher_user_id'] : 0;
            $verifierUserId = isset($demon['verifier_user_id']) ? (int) $demon['verifier_user_id'] : 0;
            $cardSearchText = strtolower((string) ($demon['name'] . ' ' . $creator . ' ' . $publisher . ' ' . $verifier . ' ' . $demon['difficulty']));
            $requirement = (int) $demon['requirement'];
            $position = (int) $demon['position'];
            $minimumScore = number_format(pointercrate_score($position, $requirement, $requirement), 2);
            $fullScore = number_format(pointercrate_score($position, $requirement, 100), 2);
            ?>
            <section class="panel fade flex mobile-col" style="overflow: hidden;" data-search-value="<?= e($cardSearchText) ?>">
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
                        ?>
                        <li>
                            <b><?= $prefix ?><?= e((string) $editor['username']) ?></b>
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
                        ?>
                        <li>
                            <b><?= $prefix ?><?= e((string) $helper['username']) ?></b>
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
