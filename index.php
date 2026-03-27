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

function render_player_role_link(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '-';
    }

    $url = base_url('players.php?user=' . rawurlencode($trimmed));
    return '<a class="player-link" href="' . e($url) . '"><b>' . e($trimmed) . '</b></a>';
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
                        <a href="<?= e(base_url('demon.php?id=' . (int) $demon['id'])) ?>">
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

$allDemons = db()->query('SELECT d.*, COUNT(c.id) AS completion_count
                           FROM demons d
                           LEFT JOIN completions c ON c.demon_id = d.id
                           GROUP BY d.id
                           ORDER BY d.position ASC')->fetchAll();

$main = [];
$extended = [];
$legacy = [];
$showcase = [];

foreach ($allDemons as $demon) {
    $position = (int) $demon['position'];
    $isLegacy = (int) ($demon['legacy'] ?? 0) === 1;

    if (!$isLegacy && $position <= 75) {
        $main[] = $demon;
        $showcase[] = $demon;
        continue;
    }

    if (!$isLegacy && $position <= 150) {
        $extended[] = $demon;
        $showcase[] = $demon;
        continue;
    }

    $legacy[] = $demon;
}

$listEditors = db()->query('SELECT username, country_code
                            FROM users
                            WHERE role = "admin"
                            ORDER BY created_at ASC, username ASC
                            LIMIT 20')->fetchAll();

$discordWidgetUrl = discord_server_widget_url();

render_header('Main List', 'list', [
    'title' => 'Main List',
    'description' => 'Top 150 ranked demons with Main, Extended, and Legacy sections.',
    'url' => base_url('index.php'),
]);
?>

<nav class="flex wrap m-center fade" id="lists" style="text-align: center;">
    <?php render_list_dropdown('mainlist', 'Main List', 'Top 1-75 demons in the current list.', $main); ?>
    <?php render_list_dropdown('extended', 'Extended List', 'These are demons that dont qualify for the main section of the list, but are still of high relevance. (Top 76-150)', $extended); ?>
    <?php render_list_dropdown('legacy', 'Legacy List', 'These are demons that used to be on the list, but got pushed off as new demons were added. (>Top 150)', $legacy); ?>
</nav>

<div class="flex m-center container">
    <main class="left">
        <section class="panel fade">
            <h1>Geometry Dash Demonlist</h1>
            <p style="margin-top: 0;">The main list of the Demonlist with 150 hardest rated levels in the game.</p>
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
            $cardSearchText = strtolower((string) ($demon['name'] . ' ' . $creator . ' ' . $publisher . ' ' . $verifier . ' ' . $demon['difficulty']));
            $requirement = (int) $demon['requirement'];
            $position = (int) $demon['position'];
            $minimumScore = number_format(pointercrate_score($position, $requirement, $requirement), 2);
            $fullScore = number_format(pointercrate_score($position, $requirement, 100), 2);
            ?>
            <section class="panel fade flex mobile-col" style="overflow: hidden;" data-search-value="<?= e($cardSearchText) ?>">
                <a
                    class="thumb ratio-16-9"
                    href="<?= e(base_url('demon.php?id=' . (int) $demon['id'])) ?>"
                    style="position: relative; <?= e($thumbStyle) ?>"
                ></a>
                <div class="flex demon-info" style="align-items: center;">
                    <div class="demon-byline">
                        <h2 style="text-align: left; margin-bottom: 0;">
                            <a href="<?= e(base_url('demon.php?id=' . (int) $demon['id'])) ?>">
                                #<?= $position ?> &#8211; <?= e((string) $demon['name']) ?>
                            </a>
                        </h2>
                        <h3 class="demon-card-byline" style="text-align: left; margin-bottom: 0;">
                            published by <?= render_player_role_link($publisher) ?><?php if ($verifier !== ''): ?>, verified by <?= render_player_role_link($verifier) ?><?php endif; ?>
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
                    <li>
                        <b><?= $prefix ?><?= e((string) $editor['username']) ?></b>
                    </li>
                <?php endforeach; ?>
            </ul>
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

