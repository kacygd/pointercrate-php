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

function youtube_embed_url(string $url): ?string
{
    $id = youtube_video_id($url);
    return $id !== null && $id !== '' ? 'https://www.youtube.com/embed/' . rawurlencode($id) : null;
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

    $safe = str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", '', ''], $url);
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

function video_host_label(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
        return 'YouTube';
    }
    if (str_contains($host, 'twitch.tv')) {
        return 'Twitch';
    }
    if (str_contains($host, 'bilibili.com')) {
        return 'Bilibili';
    }
    return $host !== '' ? $host : 'Video';
}

function render_demon_dropdown(string $id, string $title, string $description, array $demons, int $currentId): void
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
                    <?php $isActive = (int) $demon['id'] === $currentId; ?>
                    <li class="hover white <?= $isActive ? 'active' : '' ?>" title="#<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>">
                        <a href="<?= e(base_url((string) ((int) $demon['position']))) ?>">
                            #<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>
                            <br>
                            <i>by <?= e(demon_creator_name($demon)) ?></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

$requestedRank = (int) ($_GET['rank'] ?? 0);
$requestedId = (int) ($_GET['id'] ?? 0);
if ($requestedRank < 1 && $requestedId < 1) {
    redirect('index.php');
}

if ($requestedRank < 1 && $requestedId > 0) {
    $legacyStmt = db()->prepare('SELECT position FROM demons WHERE id = :id LIMIT 1');
    $legacyStmt->execute([':id' => $requestedId]);
    $legacyPosition = (int) ($legacyStmt->fetchColumn() ?: 0);
    if ($legacyPosition < 1) {
        http_response_code(404);
        render_header('Not Found', 'list');
        ?>
        <section class="panel fade">
            <h1>Level not found</h1>
            <p class="muted">This entry does not exist.</p>
            <a class="button blue hover" href="<?= e(base_url('index.php')) ?>">Back to list</a>
        </section>
        <?php
        render_footer();
        exit;
    }

    redirect((string) $legacyPosition);
}

$rank = $requestedRank;

$hasUserBannedColumn = users_has_is_banned_column();

$allDemons = db()->query('SELECT id, position, name, creator, publisher, legacy
                           FROM demons
                           ORDER BY position ASC')->fetchAll();

$demonSelectSql = $hasUserBannedColumn
    ? 'SELECT d.*,
              COUNT(CASE WHEN banned_users.id IS NULL THEN c.id END) AS completion_count
       FROM demons d
       LEFT JOIN completions c ON c.demon_id = d.id
       LEFT JOIN users banned_users
         ON LOWER(banned_users.username) = LOWER(c.player)
        AND COALESCE(banned_users.is_banned, 0) = 1
       WHERE d.position = :rank
       GROUP BY d.id'
    : 'SELECT d.*, COUNT(c.id) AS completion_count
       FROM demons d
       LEFT JOIN completions c ON c.demon_id = d.id
       WHERE d.position = :rank
       GROUP BY d.id';
$stmt = db()->prepare($demonSelectSql);
$stmt->execute([':rank' => $rank]);
$demon = $stmt->fetch();

if ($demon !== false) {
    $id = (int) ($demon['id'] ?? 0);
} else {
    $id = 0;
}

if ($demon === false) {
    http_response_code(404);
    render_header('Not Found', 'list');
    ?>
    <section class="panel fade">
        <h1>Level not found</h1>
        <p class="muted">This entry does not exist.</p>
        <a class="button blue hover" href="<?= e(base_url('index.php')) ?>">Back to list</a>
    </section>
    <?php
    render_footer();
    exit;
}

$main = [];
$extended = [];
$legacy = [];

foreach ($allDemons as $entry) {
    $position = (int) $entry['position'];
    $isLegacy = (int) ($entry['legacy'] ?? 0) === 1;

    if (!$isLegacy && $position <= 75) {
        $main[] = $entry;
        continue;
    }

    if (!$isLegacy && $position <= 150) {
        $extended[] = $entry;
        continue;
    }

    $legacy[] = $entry;
}

$prevId = null;
$nextId = null;
for ($i = 0, $count = count($allDemons); $i < $count; $i++) {
    if ((int) $allDemons[$i]['id'] === $id) {
        if ($i > 0) {
            $prevId = (int) $allDemons[$i - 1]['position'];
        }
        if ($i < $count - 1) {
            $nextId = (int) $allDemons[$i + 1]['position'];
        }
        break;
    }
}

$completionsSql = $hasUserBannedColumn
    ? 'SELECT c.*, u.country_code
       FROM completions c
       LEFT JOIN users u ON LOWER(u.username) = LOWER(c.player)
       WHERE c.demon_id = :id
         AND (u.id IS NULL OR COALESCE(u.is_banned, 0) = 0)
       ORDER BY c.progress DESC, COALESCE(c.placement, 999999), c.created_at ASC'
    : 'SELECT c.*, u.country_code
       FROM completions c
       LEFT JOIN users u ON LOWER(u.username) = LOWER(c.player)
       WHERE c.demon_id = :id
       ORDER BY c.progress DESC, COALESCE(c.placement, 999999), c.created_at ASC';
$completionsStmt = db()->prepare($completionsSql);
$completionsStmt->execute([':id' => $id]);
$completions = $completionsStmt->fetchAll();

$historyStmt = db()->prepare('SELECT created_at, old_position, new_position, note
                              FROM demon_position_history
                              WHERE demon_id = :demon_id
                              ORDER BY created_at DESC
                              LIMIT 50');
$historyStmt->execute([':demon_id' => $id]);
$positionHistory = $historyStmt->fetchAll();

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

$embed = youtube_embed_url((string) $demon['video_url']);
$thumbUrl = card_thumbnail_url($demon);
$thumbStyle = css_background_image($thumbUrl);

$position = (int) $demon['position'];
$requirement = (int) $demon['requirement'];
$minimumScore = number_format(pointercrate_score($position, $requirement, $requirement), 2);
$fullScore = number_format(pointercrate_score($position, $requirement, 100), 2);
$isLegacyEntry = (int) ($demon['legacy'] ?? 0) === 1 || $position > 150;
$category = $isLegacyEntry ? 'Legacy List' : ($position <= 75 ? 'Main List' : 'Extended List');
$creator = demon_creator_name($demon);
$publisher = trim((string) ($demon['publisher'] ?? ''));
$verifier = trim((string) ($demon['verifier'] ?? ''));
$publisherUserId = isset($demon['publisher_user_id']) ? (int) $demon['publisher_user_id'] : 0;
$verifierUserId = isset($demon['verifier_user_id']) ? (int) $demon['verifier_user_id'] : 0;
$verifiedMetaSuffix = $verifier !== '' ? ', verified by ' . $verifier : '';

$metaDescription = sprintf(
    '#%d - %s by %s, published by %s%s. %d%% to qualify, %s points at 100%%.',
    $position,
    (string) $demon['name'],
    $creator !== '' ? $creator : 'Unknown',
    $publisher !== '' ? $publisher : 'Unknown',
    $verifiedMetaSuffix,
    $requirement,
    $fullScore
);

render_header((string) $demon['name'], 'list', [
    'title' => '#' . $position . ' - ' . (string) $demon['name'],
    'description' => $metaDescription,
    'url' => base_url((string) $position),
    'image' => $thumbUrl,
]);
?>

<nav class="flex wrap m-center fade" id="lists" style="text-align: center;">
    <?php render_demon_dropdown('mainlist', 'Main List', 'Top 1-75 demons in the current list.', $main, $id); ?>
    <?php render_demon_dropdown('extended', 'Extended List', 'Demons ranked 76-150.', $extended, $id); ?>
    <?php render_demon_dropdown('legacy', 'Legacy List', 'Demons outside the current top 150.', $legacy, $id); ?>
</nav>

<div class="flex m-center container">
    <main class="left">
        <section class="panel fade demon-hero-panel">
            <div class="flex mobile-col demon-hero">
                <a class="thumb ratio-16-9 demon-hero-thumb" href="<?= e((string) $demon['video_url']) ?>" target="_blank" rel="noreferrer" style="<?= e($thumbStyle) ?>">
                    <?php if ($thumbUrl !== ''): ?>
                        <img src="<?= e($thumbUrl) ?>" alt="<?= e((string) $demon['name']) ?> thumbnail" loading="lazy">
                    <?php endif; ?>
                </a>
                <div class="demon-hero-content">
                    <h1 class="demon-hero-title">
                        #<?= $position ?> &#8211; <?= e((string) $demon['name']) ?>
                    </h1>
                    <p class="demon-hero-byline">
                        by <?= render_player_role_link($creator) ?>, published by <?= render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) ?><?php if ($verifier !== ''): ?>, verified by <?= render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) ?><?php endif; ?>
                    </p>
                    <p class="demon-hero-score">
                        <?= $minimumScore ?> (<?= $requirement ?>%) &#8212; <?= $fullScore ?> (100%) points
                    </p>
                    <div class="demon-hero-actions">
                        <?php if ($prevId !== null): ?>
                            <a class="button white hover small" href="<?= e(base_url((string) $prevId)) ?>"><i class="fa fa-chevron-left"></i> Prev</a>
                        <?php endif; ?>
                        <?php if ($nextId !== null): ?>
                            <a class="button white hover small" href="<?= e(base_url((string) $nextId)) ?>">Next <i class="fa fa-chevron-right"></i></a>
                        <?php endif; ?>
                        <a class="button blue hover small" href="<?= e((string) $demon['video_url']) ?>" target="_blank" rel="noreferrer">Verification Video</a>
                    </div>
                </div>
            </div>

            <div class="detail-grid demon-detail-grid" style="margin-top: 12px;">
                <div class="panel subtle">
                    <h3>Level Info</h3>
                    <dl class="key-value compact">
                        <div><dt>Position</dt><dd>#<?= $position ?></dd></div>
                        <div><dt>Category</dt><dd><?= e($category) ?></dd></div>
                        <div><dt>Difficulty</dt><dd><?= e((string) $demon['difficulty']) ?></dd></div>
                        <div><dt>Requirement</dt><dd><?= $requirement ?>%</dd></div>
                        <div><dt>Created by</dt><dd><?= render_player_role_link($creator) ?></dd></div>
                        <div><dt>Published by</dt><dd><?= render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) ?></dd></div>
                        <div><dt>Verified by</dt><dd><?= $verifier !== '' ? render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) : '-' ?></dd></div>
                        <div><dt>Level ID</dt><dd><?= e((string) ($demon['level_id'] ?: '-')) ?></dd></div>
                        <div><dt>Level Length</dt><dd><?= e((string) ($demon['level_length'] ?: '-')) ?></dd></div>
                        <div><dt>Song</dt><dd><?= e((string) ($demon['song'] ?: '-')) ?></dd></div>
                        <div><dt>Object Count</dt><dd><?= $demon['object_count'] !== null ? number_format((int) $demon['object_count']) : '-' ?></dd></div>
                    </dl>
                </div>
                <div class="panel subtle">
                    <h3>Scoring</h3>
                    <dl class="key-value compact">
                        <div><dt>At Requirement</dt><dd><?= $minimumScore ?> pts</dd></div>
                        <div><dt>At 100%</dt><dd><?= $fullScore ?> pts</dd></div>
                        <div><dt>Completions</dt><dd><?= (int) $demon['completion_count'] ?></dd></div>
                    </dl>
                </div>
            </div>
        </section>

        <?php if ($embed !== null): ?>
            <section class="panel fade">
                <div class="panel-head">
                    <h2>Verification Preview</h2>
                </div>
                <iframe class="ratio-16-9 demon-preview-frame" allowfullscreen src="<?= e($embed) ?>" title="<?= e((string) $demon['name']) ?> verification"></iframe>
            </section>
        <?php endif; ?>

        <section class="records panel fade">
            <div class="underlined pad">
                <h2>Records</h2>
                <h3><?= $requirement ?>% or better to qualify</h3>
                <h4><?= count($completions) ?> records submitted</h4>
            </div>

            <?php if ($completions === []): ?>
                <h3>No records yet</h3>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th class="blue">#</th>
                                <th class="blue">Record Holder</th>
                                <th class="blue">Progress</th>
                                <th class="blue">Video Proof</th>
                                <th class="blue">Date</th>
                            </tr>
                            <?php foreach ($completions as $completion): ?>
                                <?php
                                $progress = (int) ($completion['progress'] ?? 100);
                                $countryCode = normalize_country_code((string) ($completion['country_code'] ?? ''));
                                $playerFlag = country_flag_html($countryCode, true);
                                $playerName = (string) $completion['player'];
                                $playerProfileUrl = base_url('players.php?user=' . rawurlencode($playerName));
                                ?>
                                <tr style="<?= $progress === 100 ? 'font-weight: bold;' : '' ?>">
                                    <td><?= $completion['placement'] !== null ? '#' . (int) $completion['placement'] : '-' ?></td>
                                    <td>
                                        <span class="player-inline">
                                            <?= $playerFlag ?><a class="player-link" href="<?= e($playerProfileUrl) ?>"><?= e($playerName) ?></a>
                                        </span>
                                    </td>
                                    <td><?= $progress ?>%</td>
                                    <td>
                                        <a class="link" target="_blank" rel="noreferrer" href="<?= e((string) $completion['video_url']) ?>">
                                            <?= e(video_host_label((string) $completion['video_url'])) ?>
                                        </a>
                                    </td>
                                    <td><?= e(date('Y-m-d', strtotime((string) $completion['created_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel fade">
            <details class="history-toggle">
                <summary>Position History</summary>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date Change</th>
                                <th>New Position</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($positionHistory === []): ?>
                                <tr><td colspan="3" class="muted">No position changes recorded yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($positionHistory as $event): ?>
                                <?php
                                $reason = trim((string) ($event['note'] ?? ''));
                                if ($reason === '') {
                                    $reason = $event['old_position'] === null ? 'Initial placement' : 'Position updated';
                                }
                                ?>
                                <tr>
                                    <td><?= e(date('Y-m-d H:i', strtotime((string) $event['created_at']))) ?></td>
                                    <td>#<?= (int) $event['new_position'] ?></td>
                                    <td><?= e($reason) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
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
                        <li><b><?= $prefix ?><?= e((string) $editor['username']) ?></b></li>
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
                        <li><b><?= $prefix ?><?= e((string) $helper['username']) ?></b></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>

        <section id="rules" class="panel fade">
            <h2 class="underlined pad clickable">Guidelines</h2>
            <p>Only legitimate, unedited runs with clear proof are accepted. All records are reviewed manually.</p>
            <a class="blue hover button" href="<?= e(base_url('guidelines.php')) ?>">Read Guidelines</a>
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




