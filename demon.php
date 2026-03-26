<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function pointercrate_beaten_score(int $position): float
{
    return match (true) {
        $position >= 56 && $position <= 150 => 1.039035131 * ((185.7 * exp(-0.02715 * $position)) + 14.84),
        $position >= 36 && $position <= 55 => 1.0371139743 * ((212.61 * pow(1.036, 1 - $position)) + 25.071),
        $position >= 21 && $position <= 35 => ((250 - 83.389) * pow(1.0099685, 2 - $position) - 31.152) * 1.0371139743,
        $position >= 4 && $position <= 20 => ((326.1 * exp(-0.0871 * $position)) + 51.09) * 1.037117142,
        $position >= 1 && $position <= 3 => (-18.2899079915 * $position) + 368.2899079915,
        default => 0.0,
    };
}

function pointercrate_score(int $position, int $requirement, int $progress): float
{
    if ($progress < $requirement) {
        return 0.0;
    }

    $beatenScore = pointercrate_beaten_score($position);
    if ($progress !== 100) {
        return ($beatenScore * pow(5, ($progress - $requirement) / (100 - $requirement))) / 10;
    }

    return $beatenScore;
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
                        <a href="<?= e(base_url('demon.php?id=' . (int) $demon['id'])) ?>">
                            #<?= (int) $demon['position'] ?> - <?= e((string) $demon['name']) ?>
                            <br>
                            <i><?= e((string) $demon['publisher']) ?></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    redirect('index.php');
}

$allDemons = db()->query('SELECT id, position, name, publisher, legacy
                          FROM demons
                          ORDER BY position ASC')->fetchAll();

$stmt = db()->prepare('SELECT d.*, pu.country_code AS publisher_country, COUNT(c.id) AS completion_count
                       FROM demons d
                       LEFT JOIN completions c ON c.demon_id = d.id
                       LEFT JOIN users pu ON LOWER(pu.username) = LOWER(d.publisher)
                       WHERE d.id = :id
                       GROUP BY d.id');
$stmt->execute([':id' => $id]);
$demon = $stmt->fetch();

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
            $prevId = (int) $allDemons[$i - 1]['id'];
        }
        if ($i < $count - 1) {
            $nextId = (int) $allDemons[$i + 1]['id'];
        }
        break;
    }
}

$completionsStmt = db()->prepare('SELECT c.*, u.country_code
                                  FROM completions c
                                  LEFT JOIN users u ON LOWER(u.username) = LOWER(c.player)
                                  WHERE c.demon_id = :id
                                  ORDER BY c.progress DESC, COALESCE(c.placement, 999999), c.created_at ASC');
$completionsStmt->execute([':id' => $id]);
$completions = $completionsStmt->fetchAll();

$historyStmt = db()->prepare('SELECT created_at, old_position, new_position, note
                              FROM demon_position_history
                              WHERE demon_id = :demon_id
                              ORDER BY created_at DESC
                              LIMIT 50');
$historyStmt->execute([':demon_id' => $id]);
$positionHistory = $historyStmt->fetchAll();

$listEditors = db()->query('SELECT username, country_code
                            FROM users
                            WHERE role = "admin"
                            ORDER BY created_at ASC, username ASC
                            LIMIT 20')->fetchAll();

$totalCompletions = (int) db()->query('SELECT COUNT(*)
                                       FROM completions c
                                       INNER JOIN demons d ON d.id = c.demon_id
                                       WHERE d.legacy = 0
                                         AND d.position <= 150')->fetchColumn();
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
$publisherCountry = normalize_country_code((string) ($demon['publisher_country'] ?? ''));
$publisherFlag = country_flag_html($publisherCountry, true);
$publisherProfileUrl = base_url('profile.php?user=' . rawurlencode((string) $demon['publisher']));

$metaDescription = sprintf(
    '#%d - %s by %s. %d%% to qualify, %s points at 100%%.',
    $position,
    (string) $demon['name'],
    (string) $demon['publisher'],
    $requirement,
    $fullScore
);

render_header((string) $demon['name'], 'list', [
    'title' => '#' . $position . ' - ' . (string) $demon['name'],
    'description' => $metaDescription,
    'url' => base_url('demon.php?id=' . $id),
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
                        published by <a class="player-link" href="<?= e($publisherProfileUrl) ?>"><b><?= $publisherFlag ?><?= e((string) $demon['publisher']) ?></b></a>
                    </p>
                    <p class="demon-hero-score">
                        <?= $minimumScore ?> (<?= $requirement ?>%) &#8212; <?= $fullScore ?> (100%) points
                    </p>
                    <div class="demon-hero-actions">
                        <?php if ($prevId !== null): ?>
                            <a class="button white hover small" href="<?= e(base_url('demon.php?id=' . $prevId)) ?>"><i class="fa fa-chevron-left"></i> Prev</a>
                        <?php endif; ?>
                        <?php if ($nextId !== null): ?>
                            <a class="button white hover small" href="<?= e(base_url('demon.php?id=' . $nextId)) ?>">Next <i class="fa fa-chevron-right"></i></a>
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
                            $playerProfileUrl = base_url('profile.php?user=' . rawurlencode($playerName));
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
        <section id="editors" class="panel fade">
            <h2 class="underlined pad">List Editors</h2>
            <ul>
                <?php if ($listEditors === []): ?>
                    <li>No list editors yet.</li>
                <?php endif; ?>
                <?php foreach ($listEditors as $editor): ?>
                    <?php
                    $countryCode = normalize_country_code((string) ($editor['country_code'] ?? ''));
                    $prefix = country_flag_html($countryCode, true);
                    ?>
                    <li><b><?= $prefix ?><?= e((string) $editor['username']) ?></b></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section id="rules" class="panel fade">
            <h2 class="underlined pad clickable">Guidelines</h2>
            <p>Only legitimate, unedited runs with clear proof are accepted. All records are reviewed manually.</p>
            <a class="blue hover button" href="<?= e(base_url('guidelines.php')) ?>">Read Guidelines</a>
        </section>

        <section id="submit" class="panel fade">
            <h2 class="underlined pad">Submit Record</h2>
            <p>Want your run listed here? Submit your proof and wait for moderation approval.</p>
            <a class="blue hover button" href="<?= e(base_url('submit.php')) ?>">Open Submit Form</a>
        </section>

        <section id="stats" class="panel fade">
            <h2 class="underlined pad">Stats</h2>
            <p><b><?= count($allDemons) ?></b> total list entries</p>
            <p><b><?= $totalCompletions ?></b> accepted completions</p>
            <a class="white hover button" href="<?= e(base_url('players.php')) ?>">View Full Rankings</a>
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

