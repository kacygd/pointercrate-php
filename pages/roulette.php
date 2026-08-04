<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/includes/list_page_helpers.php';

$pdo = db();
$allDemons = demonlist_fetch_all_demons($pdo);
$parts = demonlist_partition_demons($allDemons, false);

$main = $parts['main'];
$extended = $parts['extended'];
$legacy = $parts['legacy'];
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();
$rouletteStorageScope = is_logged_in() ? ('user:' . (int) current_user_id()) : 'guest';

$rouletteItems = [];
foreach ($main as $demon) {
    $rouletteItems[] = roulette_item_from_demon($demon, 'main', true);
}
foreach ($extended as $demon) {
    $rouletteItems[] = roulette_item_from_demon($demon, 'extended', true);
}
foreach ($legacy as $demon) {
    $rouletteItems[] = roulette_item_from_demon($demon, 'legacy', false);
}

render_header(t('roulette.title'), 'roulette', [
    'title' => t('roulette.title'),
    'description' => t('roulette.meta_description'),
    'url' => base_url('roulette.php'),
]);
?>

<section class="panel fade m-center roulette-page-intro">
    <h1><?= e(t('roulette.heading')) ?></h1>
    <p><?= e(t('roulette.intro')) ?></p>
</section>

<section class="panel fade m-center roulette-panel" id="roulette-panel" data-roulette-storage-scope="<?= e($rouletteStorageScope) ?>">
    <div class="panel-head split roulette-panel-head">
        <div>
            <h2><?= e(t('roulette.title')) ?></h2>
            <p><?= e(t('roulette.panel_intro')) ?></p>
        </div>
        <div class="homepage-tool-actions roulette-save-actions">
            <button class="button white hover" type="button" data-roulette-save disabled><?= e(t('roulette.save')) ?></button>
            <label class="button white hover roulette-load-button">
                <?= e(t('roulette.load')) ?>
                <input type="file" accept="application/json,.json" data-roulette-load hidden>
            </label>
        </div>
    </div>

    <div class="roulette-toolbar">
        <div class="roulette-list-selector">
            <label class="cb-container roulette-check">
                <input type="checkbox" data-roulette-bucket="main" checked>
                <span class="checkmark"></span>
                <?= e(t('list.main')) ?>
            </label>
            <?php if ($showExtendedList): ?>
                <label class="cb-container roulette-check">
                    <input type="checkbox" data-roulette-bucket="extended" checked>
                    <span class="checkmark"></span>
                    <?= e(t('list.extended')) ?>
                </label>
            <?php endif; ?>
            <?php if ($showLegacyList): ?>
                <label class="cb-container roulette-check">
                    <input type="checkbox" data-roulette-bucket="legacy">
                    <span class="checkmark"></span>
                    <?= e(t('list.legacy')) ?>
                </label>
            <?php endif; ?>
        </div>

        <div class="homepage-tool-actions roulette-start-actions">
            <button class="button blue hover" type="button" data-roulette-start><?= e(t('roulette.start')) ?></button>
            <button class="button white hover" type="button" data-roulette-reset disabled><?= e(t('roulette.reset')) ?></button>
        </div>
    </div>

    <div class="roulette-game-list" data-roulette-stack></div>

    <article class="roulette-results" data-roulette-results hidden>
        <h3><?= e(t('roulette.results')) ?></h3>
        <div class="homepage-tool-actions centered-actions">
            <button class="button blue hover" type="button" data-roulette-show-remaining><?= e(t('roulette.show_remaining')) ?></button>
        </div>
    </article>

    <section class="roulette-remaining" data-roulette-remaining hidden>
        <h3><?= e(t('roulette.remaining')) ?></h3>
        <div class="roulette-game-list" data-roulette-remaining-list></div>
    </section>
</section>

<script id="roulette-data" type="application/json"><?= json_encode($rouletteItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php render_footer(); ?>
