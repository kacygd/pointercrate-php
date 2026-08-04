<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_login();

$user = current_user();
if ($user === null) {
    redirect('login.php');
}

$form = [
    'demon_name' => '',
    'video_url' => '',
    'raw_footage_url' => '',
    'progress' => '100',
    'platform' => 'PC',
    'refresh_rate' => '240',
    'notes' => '',
    'agree' => '0',
];
$errors = [];

$allDemons = db()->query('SELECT id, name, requirement, position, legacy
                          FROM demons
                          ORDER BY position ASC')->fetchAll();
$demons = [];
foreach ($allDemons as $demon) {
    $position = (int) ($demon['position'] ?? 0);
    $legacy = (int) ($demon['legacy'] ?? 0) === 1;
    if (!demonlist_is_ranked_entry($position, $legacy)) {
        continue;
    }

    $demons[] = $demon;
}

$showExtendedList = demonlist_show_extended_list();
$showLegacyList = demonlist_show_legacy_list();
$submitHint = (!$showExtendedList && !$showLegacyList)
    ? t('submit.hint_all')
    : t('submit.hint_current');

function resolve_demon_input(array $demons, string $rawInput): array
{
    $needle = strtolower(trim($rawInput));
    if ($needle === '') {
        return ['demon' => null, 'ambiguous' => false];
    }

    $partial = [];
    foreach ($demons as $demon) {
        $name = strtolower((string) $demon['name']);
        if ($name === $needle) {
            return ['demon' => $demon, 'ambiguous' => false];
        }

        if (str_contains($name, $needle)) {
            $partial[] = $demon;
        }
    }

    if (count($partial) === 1) {
        return ['demon' => $partial[0], 'ambiguous' => false];
    }

    return ['demon' => null, 'ambiguous' => count($partial) > 1];
}

if (method_is_post()) {
    $form['demon_name'] = trim((string) ($_POST['demon_name'] ?? ''));
    $form['video_url'] = trim((string) ($_POST['video_url'] ?? ''));
    $form['raw_footage_url'] = trim((string) ($_POST['raw_footage_url'] ?? ''));
    $form['progress'] = trim((string) ($_POST['progress'] ?? '100'));
    $form['platform'] = trim((string) ($_POST['platform'] ?? 'PC'));
    $form['refresh_rate'] = trim((string) ($_POST['refresh_rate'] ?? '240'));
    $form['notes'] = trim((string) ($_POST['notes'] ?? ''));
    $form['agree'] = isset($_POST['agree']) ? '1' : '0';

    if (!validate_csrf($_POST['_token'] ?? null)) {
        $errors[] = t('submit.error_token');
    }

    if (!recaptcha_verify_response($_POST['g-recaptcha-response'] ?? null, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'submit')) {
        $errors[] = t('common.recaptcha_failed');
    }

    if ($form['agree'] !== '1') {
        $errors[] = t('submit.error_agree');
    }

    $resolution = resolve_demon_input($demons, $form['demon_name']);
    $demon = $resolution['demon'];
    if ($demon === null) {
        $errors[] = $resolution['ambiguous']
            ? t('submit.error_ambiguous')
            : t('submit.error_demon');
    }

    if ($form['video_url'] === '' || filter_var($form['video_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = t('submit.error_video');
    }

    if ($form['raw_footage_url'] !== '' && filter_var($form['raw_footage_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = t('submit.error_raw');
    }

    $progress = (int) $form['progress'];
    if ($progress < 1 || $progress > 100) {
        $errors[] = t('submit.error_progress');
    }

    if ($demon !== null) {
        $req = (int) $demon['requirement'];
        if ($progress < $req) {
            $errors[] = t('submit.error_requirement', ['requirement' => $req]);
        }
    }

    $platforms = ['PC', 'Mobile', 'Tablet', 'Other'];
    if (!in_array($form['platform'], $platforms, true)) {
        $errors[] = t('submit.error_platform');
    }

    $refreshRate = (int) $form['refresh_rate'];
    if ($refreshRate < 30 || $refreshRate > 1000) {
        $errors[] = t('submit.error_refresh');
    }

    if ($errors === [] && $demon !== null) {
        $pdo = db();
        $insert = $pdo->prepare('INSERT INTO submissions
            (type, demon_name, difficulty, publisher, player, submitted_by_user_id, video_url, raw_footage_url, platform, refresh_rate, progress, notes, status)
            VALUES
            ("completion", :demon_name, NULL, NULL, :player, :submitted_by_user_id, :video_url, :raw_footage_url, :platform, :refresh_rate, :progress, :notes, "pending")');

        $insert->execute([
            ':demon_name' => (string) $demon['name'],
            ':player' => (string) $user['username'],
            ':submitted_by_user_id' => (int) $user['id'],
            ':video_url' => $form['video_url'],
            ':raw_footage_url' => $form['raw_footage_url'] !== '' ? $form['raw_footage_url'] : null,
            ':platform' => $form['platform'],
            ':refresh_rate' => $refreshRate,
            ':progress' => $progress,
            ':notes' => $form['notes'] !== '' ? $form['notes'] : null,
        ]);

        $submissionId = (int) $pdo->lastInsertId();
        send_discord_webhook('', [[
            'title' => t('submit.discord_new', ['id' => $submissionId]),
            'color' => 3447003,
            'fields' => [
                ['name' => t('common.player'), 'value' => (string) $user['username'], 'inline' => true],
                ['name' => t('common.demon'), 'value' => '#' . (int) $demon['position'] . ' - ' . (string) $demon['name'], 'inline' => true],
                ['name' => t('common.progress'), 'value' => $progress . '%', 'inline' => true],
                ['name' => t('demon.video_proof'), 'value' => (string) $form['video_url'], 'inline' => false],
            ],
            'timestamp' => gmdate('c'),
        ]]);

        flash('success', t('submit.success'));
        redirect('account.php');
    }
}

render_header(t('submit.title'), 'submit');
?>
<section class="panel fade" id="submission-form">
    <div class="panel-head">
        <h1><?= e(t('submit.heading')) ?></h1>
        <p><?= e(t('submit.as_user')) ?> <b><?= e((string) $user['username']) ?></b></p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e(base_url('submit.php')) ?>" novalidate>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <label class="field">
            <span><?= e(t('submit.level_name')) ?></span>
            <input type="text" name="demon_name" value="<?= e($form['demon_name']) ?>" data-suggest-list="submit-demon-list" placeholder="<?= e(t('submit.type_demon')) ?>" autocomplete="off" required>
            <small class="muted"><?= e($submitHint) ?></small>
            <datalist id="submit-demon-list">
                <?php foreach ($demons as $demon): ?>
                    <option value="<?= e((string) $demon['name']) ?>" label="#<?= (int) $demon['position'] ?> (<?= e(t('level_info.requirement')) ?> <?= (int) $demon['requirement'] ?>%)"></option>
                <?php endforeach; ?>
            </datalist>
        </label>

        <label class="field">
            <span><?= e(t('submit.progress')) ?></span>
            <input type="number" min="1" max="100" name="progress" value="<?= e($form['progress']) ?>" required>
        </label>

        <label class="field">
            <span><?= e(t('submit.video_url')) ?></span>
            <input type="url" name="video_url" value="<?= e($form['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=..." required>
        </label>

        <label class="field">
            <span><?= e(t('submit.raw_url')) ?></span>
            <input type="url" name="raw_footage_url" value="<?= e($form['raw_footage_url']) ?>" placeholder="<?= e(t('submit.raw_placeholder')) ?>">
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('common.platform')) ?></span>
                <select name="platform">
                    <?php foreach (['PC', 'Mobile', 'Tablet', 'Other'] as $platform): ?>
                        <option value="<?= e($platform) ?>" <?= $form['platform'] === $platform ? 'selected' : '' ?>><?= e($platform) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span><?= e(t('submit.refresh_rate')) ?></span>
                <input type="number" min="30" max="1000" name="refresh_rate" value="<?= e($form['refresh_rate']) ?>">
            </label>
        </div>

        <label class="field">
            <span><?= e(t('submit.notes')) ?></span>
            <textarea name="notes" rows="5" placeholder="<?= e(t('submit.notes_placeholder')) ?>"><?= e($form['notes']) ?></textarea>
        </label>

        <div class="submit-guidelines-agreement">
            <label class="cb-container">
                <input type="checkbox" name="agree" value="1" <?= $form['agree'] === '1' ? 'checked' : '' ?>>
                <span class="checkmark"></span>
                <span><?= e(t('submit.agree')) ?></span>
            </label>
            <a class="link" href="<?= e(base_url('guidelines.php')) ?>"><?= e(t('submit.guidelines_link')) ?></a><span>.</span>
        </div>

        <?= recaptcha_widget_html('submit') ?>

        <button class="button blue hover" type="submit" style="margin-top: 10px;"><?= e(t('submit.button')) ?></button>
    </form>
</section>
<?php render_footer(); ?>
