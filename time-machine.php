<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/includes/list_page_helpers.php';

$pdo = db();
$allDemons = demonlist_fetch_all_demons($pdo);
$now = new DateTimeImmutable('now', time_machine_timezone());
$availableSince = time_machine_available_since($pdo);
$requestedAt = time_machine_parse_input((string) ($_GET['at'] ?? ''));

$viewAt = $requestedAt ?? $now;
if ($availableSince instanceof DateTimeImmutable && $viewAt < $availableSince) {
    $viewAt = $availableSince;
}
if ($viewAt > $now) {
    $viewAt = $now;
}

$isTimeMachineView = $requestedAt instanceof DateTimeImmutable;
$snapshotDemons = $allDemons;

if ($isTimeMachineView) {
    $futureEventsStmt = $pdo->prepare(
        'SELECT id, demon_id, old_position, new_position, created_at
         FROM demon_position_history
         WHERE created_at > :at
         ORDER BY created_at DESC, id DESC'
    );
    $futureEventsStmt->execute([
        ':at' => $viewAt->format('Y-m-d H:i:s'),
    ]);
    $snapshotDemons = time_machine_reconstruct_demons($snapshotDemons, $futureEventsStmt->fetchAll());
    $snapshotDemons = time_machine_filter_demons_created_by($snapshotDemons, $viewAt);
}

$parts = demonlist_partition_demons($snapshotDemons, $isTimeMachineView);
$main = $parts['main'];
$extended = $parts['extended'];
$legacy = $parts['legacy'];
$showcase = $parts['showcase'];
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();

$mainListDescription = $isTimeMachineView
    ? 'Main List entries in this historical snapshot.'
    : demonlist_main_list_dropdown_description($showExtendedList, $showLegacyList);
$extendedListDescription = $isTimeMachineView
    ? 'Extended List entries in this historical snapshot.'
    : demonlist_extended_list_dropdown_description(true);
$legacyListDescription = $isTimeMachineView
    ? 'Legacy entries in this historical snapshot.'
    : demonlist_legacy_list_dropdown_description();

$timeMachineInputValue = time_machine_format_input($viewAt);
$timeMachineMinValue = $availableSince instanceof DateTimeImmutable
    ? time_machine_format_input($availableSince)
    : time_machine_format_input($now);
$timeMachineBanner = time_machine_format_banner($viewAt);
render_header('Time Machine', 'time_machine', [
    'title' => 'Time Machine',
    'description' => 'View the Demonlist at a previous point in time.',
    'url' => $isTimeMachineView
        ? (base_url('time-machine.php') . '?at=' . rawurlencode(time_machine_format_input($viewAt)))
        : base_url('time-machine.php'),
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
        <?php if ($isTimeMachineView): ?>
            <section class="panel fade time-machine-banner">
                <div class="time-machine-banner-copy">
                    You are currently looking at the demonlist how it was on <b><?= e($timeMachineBanner) ?></b>
                </div>
                <a class="button white hover" href="<?= e(base_url('time-machine.php')) ?>">Go to present</a>
            </section>
        <?php endif; ?>

        <section class="panel fade time-machine-tool" id="time-machine">
            <form class="stack-form" id="time-machine-form" method="get" action="<?= e(base_url('time-machine.php')) ?>">
                <div class="underlined pad">
                    <h1>Time Machine</h1>
                </div>
                <p>Enter the date you want to view the list at below.</p>
                <span class="form-input" id="time-machine-destination" data-type="datetime-local">
                    <h3>Destination</h3>
                    <input
                        type="datetime-local"
                        name="at"
                        value="<?= e($timeMachineInputValue) ?>"
                        min="<?= e($timeMachineMinValue) ?>"
                        max="<?= e(time_machine_format_input($now)) ?>"
                        required
                    >
                    <p class="error"></p>
                </span>
                <div class="homepage-tool-actions centered-actions">
                    <button class="button blue hover" type="submit">Go</button>
                    <?php if ($isTimeMachineView): ?>
                        <a class="button white hover" href="<?= e(base_url('time-machine.php')) ?>">Go to present</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <?php if ($showcase === []): ?>
            <section class="panel fade">
                <h2>No Demons Yet</h2>
                <p>No levels existed at this destination.</p>
            </section>
        <?php endif; ?>

        <?php foreach ($showcase as $demon): ?>
            <?php
            $thumb = card_thumbnail_url($demon);
            $thumbStyle = css_background_image($thumb);
            $creatorSearchText = implode(' ', demon_creator_names($demon));
            $publisher = trim((string) ($demon['publisher'] ?? ''));
            $verifier = trim((string) ($demon['verifier'] ?? ''));
            $publisherUserId = isset($demon['publisher_user_id']) ? (int) $demon['publisher_user_id'] : 0;
            $verifierUserId = isset($demon['verifier_user_id']) ? (int) $demon['verifier_user_id'] : 0;
            $publisherLabel = user_public_name_by_id($publisherUserId > 0 ? $publisherUserId : null, $publisher) ?? $publisher;
            $verifierLabel = user_public_name_by_id($verifierUserId > 0 ? $verifierUserId : null, $verifier) ?? $verifier;
            $cardSearchText = strtolower((string) (($demon['name'] ?? '') . ' ' . $creatorSearchText . ' ' . $publisher . ' ' . $publisherLabel . ' ' . $verifier . ' ' . $verifierLabel . ' ' . ($demon['difficulty'] ?? '')));
            $requirement = (int) ($demon['requirement'] ?? 100);
            $position = (int) ($demon['position'] ?? 0);
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
                    href="<?= e(base_url((string) $position)) ?>"
                    style="position: relative; <?= e($thumbStyle) ?>"
                ></a>
                <div class="flex demon-info" style="align-items: center;">
                    <div class="demon-byline">
                        <h2 style="text-align: left; margin-bottom: 0;">
                            <a href="<?= e(base_url((string) $position)) ?>">
                                #<?= $position ?> &#8211; <?= e((string) ($demon['name'] ?? '')) ?>
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
        <section class="panel fade">
            <h2 class="underlined pad">About</h2>
            <p>Historical positions are reconstructed from recorded list changes. The earliest selectable date is the date the first level was added to this list.</p>
            <a class="blue hover button" href="<?= e(base_url('index.php')) ?>">Back to Main List</a>
        </section>
    </aside>
</div>

<?php render_footer(); ?>
