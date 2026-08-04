<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/includes/list_page_helpers.php';

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
    ? t('time.main_snapshot')
    : demonlist_main_list_dropdown_description($showExtendedList, $showLegacyList);
$extendedListDescription = $isTimeMachineView
    ? t('time.extended_snapshot')
    : demonlist_extended_list_dropdown_description(true);
$legacyListDescription = $isTimeMachineView
    ? t('time.legacy_snapshot')
    : demonlist_legacy_list_dropdown_description();

$timeMachineInputValue = time_machine_format_input($viewAt);
$timeMachineMinValue = $availableSince instanceof DateTimeImmutable
    ? time_machine_format_input($availableSince)
    : time_machine_format_input($now);
$timeMachineBanner = time_machine_format_banner($viewAt);
render_header(t('time.title'), 'time_machine', [
    'title' => t('time.title'),
    'description' => t('time.meta_description'),
    'url' => $isTimeMachineView
        ? (base_url('time-machine.php') . '?at=' . rawurlencode(time_machine_format_input($viewAt)))
        : base_url('time-machine.php'),
]);
?>

<nav class="flex wrap m-center fade" id="lists" style="text-align: center;">
                    <?php render_list_dropdown('mainlist', t('list.main'), $mainListDescription, $main); ?>
    <?php if ($showExtendedList): ?>
        <?php render_list_dropdown('extended', t('list.extended'), $extendedListDescription, $extended); ?>
    <?php endif; ?>
    <?php if ($showLegacyList): ?>
        <?php render_list_dropdown('legacy', t('list.legacy'), $legacyListDescription, $legacy); ?>
    <?php endif; ?>
</nav>

<div class="flex m-center container">
    <main class="left">
        <?php if ($isTimeMachineView): ?>
            <section class="panel fade time-machine-banner">
                <div class="time-machine-banner-copy">
                    <?= e(t('time.banner')) ?> <b><?= e($timeMachineBanner) ?></b>
                </div>
                <a class="button white hover" href="<?= e(base_url('time-machine.php')) ?>"><?= e(t('time.present')) ?></a>
            </section>
        <?php endif; ?>

        <section class="panel fade time-machine-tool" id="time-machine">
            <form class="stack-form" id="time-machine-form" method="get" action="<?= e(base_url('time-machine.php')) ?>">
                <div class="underlined pad">
                    <h1><?= e(t('time.title')) ?></h1>
                </div>
                <p><?= e(t('time.intro')) ?></p>
                <span class="form-input" id="time-machine-destination" data-type="datetime-local">
                    <h3><?= e(t('time.destination')) ?></h3>
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
                    <button class="button blue hover" type="submit"><?= e(t('time.go')) ?></button>
                    <?php if ($isTimeMachineView): ?>
                        <a class="button white hover" href="<?= e(base_url('time-machine.php')) ?>"><?= e(t('time.present')) ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <?php if ($showcase === []): ?>
            <section class="panel fade">
                <h2><?= e(t('time.no_demons_title')) ?></h2>
                <p><?= e(t('time.no_demons_text')) ?></p>
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
                            <?= e(t('list.published_by')) ?> <?= render_player_role_link($publisher, $publisherUserId > 0 ? $publisherUserId : null) ?><?php if ($verifier !== ''): ?>, <?= e(t('list.verified_by')) ?> <?= render_player_role_link($verifier, $verifierUserId > 0 ? $verifierUserId : null) ?><?php endif; ?>
                        </h3>
                        <div class="demon-points" style="text-align: left; font-size: 0.8em;">
                            <?= $minimumScore ?> (<?= $requirement ?>%) &#8212; <?= $fullScore ?> (100%) <?= e(t('list.points')) ?>
                        </div>
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
        <section class="panel fade">
            <h2 class="underlined pad"><?= e(t('time.about')) ?></h2>
            <p><?= e(t('time.about_text')) ?></p>
            <a class="blue hover button" href="<?= e(base_url('index.php')) ?>"><?= e(t('common.back_to_main_list')) ?></a>
        </section>
    </aside>
</div>

<?php render_footer(); ?>
