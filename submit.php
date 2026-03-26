<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

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

$demons = db()->query('SELECT id, name, requirement, position
                      FROM demons
                      WHERE legacy = 0
                        AND position <= 150
                      ORDER BY position ASC')->fetchAll();

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
        $errors[] = 'Invalid form token. Reload page and try again.';
    }

    if ($form['agree'] !== '1') {
        $errors[] = 'You must confirm that you read the guidelines.';
    }

    $resolution = resolve_demon_input($demons, $form['demon_name']);
    $demon = $resolution['demon'];
    if ($demon === null) {
        $errors[] = $resolution['ambiguous']
            ? 'Your search matches multiple demons. Please type the full level name.'
            : 'Please type a valid demon name from the top 150 list.';
    }

    if ($form['video_url'] === '' || filter_var($form['video_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'A valid video proof URL is required.';
    }

    if ($form['raw_footage_url'] !== '' && filter_var($form['raw_footage_url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Raw footage URL must be a valid URL if provided.';
    }

    $progress = (int) $form['progress'];
    if ($progress < 1 || $progress > 100) {
        $errors[] = 'Progress must be between 1 and 100.';
    }

    if ($demon !== null) {
        $req = (int) $demon['requirement'];
        if ($progress < $req) {
            $errors[] = 'Progress is below this demon requirement (' . $req . '%).';
        }
    }

    $platforms = ['PC', 'Mobile', 'Tablet', 'Other'];
    if (!in_array($form['platform'], $platforms, true)) {
        $errors[] = 'Invalid platform value.';
    }

    $refreshRate = (int) $form['refresh_rate'];
    if ($refreshRate < 30 || $refreshRate > 1000) {
        $errors[] = 'Refresh rate must be between 30 and 1000.';
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
            'title' => 'New Pending Submission #' . $submissionId,
            'color' => 3447003,
            'fields' => [
                ['name' => 'Player', 'value' => (string) $user['username'], 'inline' => true],
                ['name' => 'Demon', 'value' => '#' . (int) $demon['position'] . ' - ' . (string) $demon['name'], 'inline' => true],
                ['name' => 'Progress', 'value' => $progress . '%', 'inline' => true],
                ['name' => 'Video', 'value' => (string) $form['video_url'], 'inline' => false],
            ],
            'timestamp' => gmdate('c'),
        ]]);

        flash('success', 'Record submission queued for admin review.');
        redirect('account.php');
    }
}

render_header('Submit', 'submit');
?>
<section class="panel fade" id="submission-form">
    <div class="panel-head">
        <h1>Submit a Record</h1>
        <p>Submitting as <b><?= e((string) $user['username']) ?></b></p>
    </div>

    <?php if ($errors !== []): ?>
        <div class="info-red"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e(base_url('submit.php')) ?>" novalidate>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <label class="field">
            <span>Level Name (type to search)</span>
            <input type="text" name="demon_name" value="<?= e($form['demon_name']) ?>" data-suggest-list="submit-demon-list" placeholder="Type demon name..." autocomplete="off" required>
            <small class="muted">Top 150 only. Type any part of the name to get suggestions.</small>
            <datalist id="submit-demon-list">
                <?php foreach ($demons as $demon): ?>
                    <option value="<?= e((string) $demon['name']) ?>" label="#<?= (int) $demon['position'] ?> (Req <?= (int) $demon['requirement'] ?>%)"></option>
                <?php endforeach; ?>
            </datalist>
        </label>

        <label class="field">
            <span>Progress (%)</span>
            <input type="number" min="1" max="100" name="progress" value="<?= e($form['progress']) ?>" required>
        </label>

        <label class="field">
            <span>Video Proof URL</span>
            <input type="url" name="video_url" value="<?= e($form['video_url']) ?>" placeholder="https://www.youtube.com/watch?v=..." required>
        </label>

        <label class="field">
            <span>Raw Footage URL (optional)</span>
            <input type="url" name="raw_footage_url" value="<?= e($form['raw_footage_url']) ?>" placeholder="Drive/YouTube unlisted link">
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span>Platform</span>
                <select name="platform">
                    <?php foreach (['PC', 'Mobile', 'Tablet', 'Other'] as $platform): ?>
                        <option value="<?= e($platform) ?>" <?= $form['platform'] === $platform ? 'selected' : '' ?>><?= e($platform) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Refresh Rate (Hz)</span>
                <input type="number" min="30" max="1000" name="refresh_rate" value="<?= e($form['refresh_rate']) ?>">
            </label>
        </div>

        <label class="field">
            <span>Moderator Notes (optional)</span>
            <textarea name="notes" rows="5" placeholder="Attempts, run context, bugs, clicks, etc."><?= e($form['notes']) ?></textarea>
        </label>

        <label class="cb-container" style="text-align: left; margin-top: 5px;">
            <input type="checkbox" name="agree" value="1" <?= $form['agree'] === '1' ? 'checked' : '' ?>>
            <span class="checkmark"></span>
            I have read and agree with the <a class="link" href="<?= e(base_url('guidelines.php')) ?>">submission guidelines</a>.
        </label>

        <button class="button blue hover" type="submit" style="margin-top: 10px;">Send Submission</button>
    </form>
</section>
<?php render_footer(); ?>
