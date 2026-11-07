<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/includes/list_page_helpers.php';

$pdo = db();
$activeList = demonlist_active_list();
$allDemons = demonlist_fetch_all_demons($pdo);
$listDemons = demonlist_apply_list_context($allDemons, $activeList);
$parts = demonlist_partition_demons($listDemons, false);

$main = $parts['main'];
$extended = $parts['extended'];
$legacy = $parts['legacy'];
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();
$rouletteStorageScope = (is_logged_in() ? ('user:' . (int) current_user_id()) : 'guest') . ':list:' . $activeList;

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
    'url' => demonlist_list_url('roulette.php', $activeList),
]);
?>

<section class="panel fade m-center roulette-page-intro">
    <h1><?= e(t('roulette.heading')) ?></h1>
    <p><?= e(t('roulette.intro')) ?></p>
    <div class="stats-viewer-tabs roulette-list-tabs">
        <?php if (demonlist_ratedplus_enabled()): ?>
            <a class="button <?= $activeList === 'ratedplus' ? 'blue' : 'white' ?> hover small" href="<?= e(demonlist_list_url('roulette.php', 'ratedplus')) ?>"><?= e(t('list.ratedplus')) ?></a>
        <?php endif; ?>
        <a class="button <?= $activeList === 'demonlist' ? 'blue' : 'white' ?> hover small" href="<?= e(demonlist_list_url('roulette.php', 'demonlist')) ?>">Demonlist</a>
    </div>
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

<script nonce="<?= e((string) ($GLOBALS['app_csp_nonce'] ?? '')) ?>" id="roulette-data" type="application/json"><?= json_encode($rouletteItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php render_footer(); ?>
