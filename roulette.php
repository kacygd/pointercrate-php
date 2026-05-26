<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/includes/list_page_helpers.php';

$pdo = db();
$allDemons = demonlist_fetch_all_demons($pdo);
$parts = demonlist_partition_demons($allDemons, false);

$main = $parts['main'];
$extended = $parts['extended'];
$legacy = $parts['legacy'];
$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();

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

render_header('Roulette', 'roulette', [
    'title' => 'Roulette',
    'description' => 'Play the Demon Roulette challenge with levels from the Demonlist.',
    'url' => base_url('roulette.php'),
]);
?>

<section class="panel fade m-center roulette-page-intro">
    <h1>Extreme Demon Roulette</h1>
    <p>The Extreme Demon Roulette is a challenge where you must go through as many demons as possible, with the challenge ending when you get 100% or give up.</p>
</section>

<section class="panel fade m-center roulette-panel" id="roulette-panel">
    <div class="panel-head split roulette-panel-head">
        <div>
            <h2>Roulette</h2>
            <p>Play the Demon Roulette challenge against the current list.</p>
        </div>
        <div class="homepage-tool-actions roulette-save-actions">
            <button class="button white hover" type="button" data-roulette-save disabled>Save</button>
            <label class="button white hover roulette-load-button">
                Load
                <input type="file" accept="application/json,.json" data-roulette-load hidden>
            </label>
        </div>
    </div>

    <div class="roulette-toolbar">
        <div class="roulette-list-selector">
            <label class="cb-container roulette-check">
                <input type="checkbox" data-roulette-bucket="main" checked>
                <span class="checkmark"></span>
                Main List
            </label>
            <?php if ($showExtendedList): ?>
                <label class="cb-container roulette-check">
                    <input type="checkbox" data-roulette-bucket="extended" checked>
                    <span class="checkmark"></span>
                    Extended List
                </label>
            <?php endif; ?>
            <?php if ($showLegacyList): ?>
                <label class="cb-container roulette-check">
                    <input type="checkbox" data-roulette-bucket="legacy">
                    <span class="checkmark"></span>
                    Legacy List
                </label>
            <?php endif; ?>
        </div>

        <div class="homepage-tool-actions roulette-start-actions">
            <button class="button blue hover" type="button" data-roulette-start>Start</button>
        </div>
    </div>

    <div class="roulette-game-list" data-roulette-stack></div>

    <article class="roulette-results" data-roulette-results hidden>
        <h3>Results</h3>
        <div class="homepage-tool-actions centered-actions">
            <button class="button blue hover" type="button" data-roulette-show-remaining>Show remaining demons</button>
        </div>
    </article>

    <section class="roulette-remaining" data-roulette-remaining hidden>
        <h3>Remaining Demons</h3>
        <div class="roulette-game-list" data-roulette-remaining-list></div>
    </section>
</section>

<script id="roulette-data" type="application/json"><?= json_encode($rouletteItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php render_footer(); ?>
