<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';


function record_position_event(PDO $pdo, int $demonId, ?int $oldPosition, int $newPosition, ?int $changedByUserId, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO demon_position_history
        (demon_id, old_position, new_position, changed_by_user_id, note)
        VALUES
        (:demon_id, :old_position, :new_position, :changed_by_user_id, :note)');

    $stmt->execute([
        ':demon_id' => $demonId,
        ':old_position' => $oldPosition,
        ':new_position' => $newPosition,
        ':changed_by_user_id' => $changedByUserId,
        ':note' => $note !== null && trim($note) !== '' ? trim($note) : null,
    ]);
}

function admin_safe_transaction_commit(PDO $pdo, bool $transactionStarted, bool $success): void
{
    if (!$transactionStarted || !$pdo->inTransaction()) {
        return;
    }

    try {
        if ($success) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $exception) {
        if (stripos($exception->getMessage(), 'There is no active transaction') === false) {
            throw $exception;
        }
    }
}

function admin_generate_temporary_password(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

function admin_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND column_name = :column"
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensure_bonus_points_column(PDO $pdo): void
{
    if (!admin_column_exists($pdo, 'users', 'bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER points');
    }

    $pdo->exec('ALTER TABLE users MODIFY COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00');
}

function ensure_user_banned_column(PDO $pdo): void
{
    if (!admin_column_exists($pdo, 'users', 'is_banned')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER role');
    }

    $pdo->exec('ALTER TABLE users MODIFY COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0');
}

function ensure_user_comments_disabled_column(PDO $pdo): void
{
    if (!admin_column_exists($pdo, 'users', 'comments_disabled')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_banned');
    }

    $pdo->exec('ALTER TABLE users MODIFY COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0');

    if (!admin_index_exists($pdo, 'users', 'idx_users_comments_disabled')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_comments_disabled (comments_disabled)');
    }
}

function admin_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :index_name"
    );
    $stmt->execute([
        ':table' => $table,
        ':index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function admin_fk_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = :table
           AND constraint_name = :constraint_name"
    );
    $stmt->execute([
        ':table' => $table,
        ':constraint_name' => $constraint,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensure_demon_claim_columns(PDO $pdo): void
{
    if (!admin_column_exists($pdo, 'demons', 'publisher_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN publisher_user_id INT UNSIGNED NULL AFTER publisher');
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN publisher_user_id INT UNSIGNED NULL');

    if (!admin_column_exists($pdo, 'demons', 'verifier_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN verifier_user_id INT UNSIGNED NULL AFTER verifier');
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN verifier_user_id INT UNSIGNED NULL');

    if (!admin_index_exists($pdo, 'demons', 'idx_demons_publisher_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD INDEX idx_demons_publisher_user_id (publisher_user_id)');
    }
    if (!admin_index_exists($pdo, 'demons', 'idx_demons_verifier_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD INDEX idx_demons_verifier_user_id (verifier_user_id)');
    }

    if (!admin_fk_exists($pdo, 'demons', 'fk_demons_publisher_user')) {
        $pdo->exec('ALTER TABLE demons
                    ADD CONSTRAINT fk_demons_publisher_user
                    FOREIGN KEY (publisher_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL');
    }

    if (!admin_fk_exists($pdo, 'demons', 'fk_demons_verifier_user')) {
        $pdo->exec('ALTER TABLE demons
                    ADD CONSTRAINT fk_demons_verifier_user
                    FOREIGN KEY (verifier_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL');
    }
}

function admin_user_id_by_username(PDO $pdo, string $username): ?int
{
    $normalized = trim($username);
    if ($normalized === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $stmt->execute([':username' => $normalized]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }

    $userId = (int) $value;
    return $userId > 0 ? $userId : null;
}

function admin_badge_uploaded_image_url(?array $file): ?string
{
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Badge image upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Badge image must be smaller than 2MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded badge image.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }

    $allowedExtensions = ['png', 'jpg', 'gif', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Badge upload must be PNG, JPG, GIF, or WEBP.');
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('Uploaded badge file is not a valid image.');
    }

    $targetDir = dirname(__DIR__) . '/assets/badges';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Could not create badge image folder.');
    }

    $fileName = 'badge-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Could not save badge image.');
    }

    return 'assets/badges/' . $fileName;
}

function admin_webhook_actor_label(): string
{
    $name = trim((string) (current_user_display_name() ?? 'System'));
    if ($name === '') {
        $name = 'System';
    }

    $id = current_user_id();
    return $id !== null ? $name . ' (#' . $id . ')' : $name;
}

function admin_webhook_text(mixed $value): string
{
    if ($value === null) {
        return '-';
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    $text = trim((string) $value);
    return $text !== '' ? $text : '-';
}

function admin_webhook_list_label(int $legacy): string
{
    return $legacy === 1 ? 'Legacy' : 'Main';
}

function admin_webhook_add_change(array &$changes, string $label, string $before, string $after, bool $inline = false): void
{
    if ($before === $after) {
        return;
    }

    $changes[] = [
        'name' => $label,
        'value' => $before . ' -> ' . $after,
        'inline' => $inline,
    ];
}

function admin_webhook_changes_text(array $changes, int $limit = 8): string
{
    $lines = [];
    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }

        $name = admin_webhook_text($change['name'] ?? null);
        $value = admin_webhook_text($change['value'] ?? null);
        $lines[] = $name . ': ' . $value;

        if (count($lines) >= $limit) {
            break;
        }
    }

    $remaining = count($changes) - count($lines);
    if ($remaining > 0) {
        $lines[] = '+' . $remaining . ' more';
    }

    return $lines !== [] ? implode("\n", $lines) : '-';
}

function admin_notify_level_added(array $level): void
{
    $embed = [
        'title' => 'Level added',
        'color' => 5814783,
        'fields' => [
            ['name' => 'Level', 'value' => '#' . (int) $level['position'] . ' - ' . admin_webhook_text($level['name']), 'inline' => false],
            ['name' => 'Info', 'value' => admin_webhook_list_label((int) $level['legacy']) . ' / ' . (int) $level['requirement'] . '%', 'inline' => true],
            ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ],
        'timestamp' => gmdate('c'),
    ];

    if ((int) ($level['id'] ?? 0) > 0) {
        $embed['url'] = absolute_url((string) (int) $level['position']);
    }

    send_discord_webhook('', [$embed]);
    admin_notify_level_added_linked_users($level);
}

function admin_notify_level_added_linked_users(array $level): void
{
    if (!users_has_discord_link_columns()) {
        return;
    }

    $recipients = [];
    $publisherUserId = (int) ($level['publisher_user_id'] ?? 0);
    if ($publisherUserId > 0) {
        $recipients[$publisherUserId][] = 'Publisher';
    }
    $verifierUserId = (int) ($level['verifier_user_id'] ?? 0);
    if ($verifierUserId > 0) {
        $recipients[$verifierUserId][] = 'Verifier';
    }
    if ($recipients === []) {
        return;
    }

    $position = (int) ($level['position'] ?? 0);
    $levelName = admin_webhook_text($level['name'] ?? null);
    $levelUrl = $position > 0 ? absolute_url((string) $position) : absolute_url('index.php');
    $baseFields = [
        ['name' => 'Level', 'value' => '#' . $position . ' - ' . $levelName, 'inline' => false],
        ['name' => 'Requirement', 'value' => (int) ($level['requirement'] ?? 0) . '%', 'inline' => true],
        ['name' => 'List', 'value' => admin_webhook_list_label((int) ($level['legacy'] ?? 0)), 'inline' => true],
        ['name' => 'Added By', 'value' => admin_webhook_actor_label(), 'inline' => true],
    ];

    $pdo = db();
    foreach ($recipients as $userId => $roles) {
        $embed = [
            'title' => 'Level added',
            'description' => 'A level connected to your account was added.',
            'url' => $levelUrl,
            'color' => 5814783,
            'fields' => array_merge(
                [['name' => 'Role', 'value' => implode(', ', array_unique($roles)), 'inline' => true]],
                $baseFields
            ),
            'timestamp' => gmdate('c'),
        ];

        send_discord_user_notification(
            $pdo,
            (int) $userId,
            app_name() . ': level added.',
            [$embed]
        );
    }
}

function admin_creator_parts_from_input(string $input): array
{
    $names = split_creator_names($input);
    if ($names === []) {
        return [
            'creator' => '',
            'creator_more' => '',
            'creator_display' => '',
        ];
    }

    $primary = array_shift($names);

    return [
        'creator' => $primary,
        'creator_more' => implode(', ', $names),
        'creator_display' => implode(', ', array_filter([$primary, ...$names], static fn(string $name): bool => $name !== '')),
    ];
}

function admin_notify_level_updated(array $before, array $after, string $moveNote = ''): void
{
    $changes = [];

    admin_webhook_add_change($changes, 'Name', admin_webhook_text($before['name'] ?? null), admin_webhook_text($after['name'] ?? null));
    admin_webhook_add_change($changes, 'Position', '#' . (int) ($before['position'] ?? 0), '#' . (int) ($after['position'] ?? 0), true);
    admin_webhook_add_change($changes, 'Difficulty', admin_webhook_text($before['difficulty'] ?? null), admin_webhook_text($after['difficulty'] ?? null), true);
    admin_webhook_add_change($changes, 'Requirement', (int) ($before['requirement'] ?? 0) . '%', (int) ($after['requirement'] ?? 0) . '%', true);
    admin_webhook_add_change($changes, 'Creator(s)', admin_webhook_text($before['creator_display'] ?? null), admin_webhook_text($after['creator_display'] ?? null));
    admin_webhook_add_change($changes, 'Publisher', admin_webhook_text($before['publisher'] ?? null), admin_webhook_text($after['publisher'] ?? null), true);
    admin_webhook_add_change($changes, 'Verifier', admin_webhook_text($before['verifier'] ?? null), admin_webhook_text($after['verifier'] ?? null), true);
    admin_webhook_add_change($changes, 'Description', admin_webhook_text($before['description'] ?? null), admin_webhook_text($after['description'] ?? null));
    admin_webhook_add_change($changes, 'Video URL', admin_webhook_text($before['video_url'] ?? null), admin_webhook_text($after['video_url'] ?? null));
    admin_webhook_add_change($changes, 'Thumbnail URL', admin_webhook_text($before['thumbnail_url'] ?? null), admin_webhook_text($after['thumbnail_url'] ?? null));
    admin_webhook_add_change($changes, 'Level ID', admin_webhook_text($before['level_id'] ?? null), admin_webhook_text($after['level_id'] ?? null), true);
    admin_webhook_add_change($changes, 'Level Length', admin_webhook_text($before['level_length'] ?? null), admin_webhook_text($after['level_length'] ?? null), true);
    admin_webhook_add_change($changes, 'Song', admin_webhook_text($before['song'] ?? null), admin_webhook_text($after['song'] ?? null));

    $beforeObjects = ($before['object_count'] ?? null) !== null ? (string) (int) $before['object_count'] : '-';
    $afterObjects = ($after['object_count'] ?? null) !== null ? (string) (int) $after['object_count'] : '-';
    admin_webhook_add_change($changes, 'Object Count', $beforeObjects, $afterObjects, true);

    admin_webhook_add_change(
        $changes,
        'List Type',
        admin_webhook_list_label((int) ($before['legacy'] ?? 0)),
        admin_webhook_list_label((int) ($after['legacy'] ?? 0)),
        true
    );
    admin_webhook_add_change(
        $changes,
        'Comments',
        (int) ($before['comments_disabled'] ?? 0) === 1 ? 'Disabled' : 'Enabled',
        (int) ($after['comments_disabled'] ?? 0) === 1 ? 'Disabled' : 'Enabled',
        true
    );

    if ($changes === []) {
        return;
    }

    $fields = [
        ['name' => 'Level', 'value' => '#' . (int) ($after['position'] ?? 0) . ' - ' . admin_webhook_text($after['name'] ?? null), 'inline' => false],
        ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ['name' => 'Changes', 'value' => admin_webhook_changes_text($changes), 'inline' => false],
    ];

    if ($moveNote !== '') {
        $fields[] = ['name' => 'Note', 'value' => $moveNote, 'inline' => false];
    }

    $embed = [
        'title' => 'Level updated',
        'color' => 15105570,
        'fields' => $fields,
        'timestamp' => gmdate('c'),
    ];

    if ((int) ($after['id'] ?? 0) > 0) {
        $embed['url'] = absolute_url((string) (int) $after['position']);
    }

    send_discord_webhook('', [$embed]);
}

function admin_notify_level_moved(string $name, int $demonId, int $oldPosition, int $newPosition, string $note = ''): void
{
    if ($newPosition === $oldPosition) {
        return;
    }

    $embed = [
        'title' => 'Rank changed',
        'color' => 15105570,
        'fields' => [
            ['name' => 'Level', 'value' => admin_webhook_text($name), 'inline' => false],
            ['name' => 'Rank', 'value' => '#' . $oldPosition . ' -> #' . $newPosition, 'inline' => true],
            ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ],
        'timestamp' => gmdate('c'),
    ];
    if ($note !== '') {
        $embed['fields'][] = ['name' => 'Note', 'value' => $note, 'inline' => false];
    }

    if ($demonId > 0) {
        $embed['url'] = absolute_url((string) $newPosition);
    }

    send_discord_webhook('', [$embed]);
}

function admin_notify_level_deleted(array $level): void
{
    $embed = [
        'title' => 'Level deleted',
        'color' => 15548997,
        'fields' => [
            ['name' => 'Level', 'value' => '#' . (int) ($level['position'] ?? 0) . ' - ' . admin_webhook_text($level['name'] ?? null), 'inline' => false],
            ['name' => 'Records', 'value' => (string) (int) ($level['records_removed'] ?? 0), 'inline' => true],
            ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ],
        'timestamp' => gmdate('c'),
    ];

    send_discord_webhook('', [$embed]);
}

function admin_notify_user_updated(array $before, array $after, float $bonusDelta): void
{
    $changes = [];

    admin_webhook_add_change($changes, 'Role', strtoupper((string) $before['role']), strtoupper((string) $after['role']), true);
    admin_webhook_add_change(
        $changes,
        'Banned',
        ((int) $before['is_banned'] === 1 ? 'Yes' : 'No'),
        ((int) $after['is_banned'] === 1 ? 'Yes' : 'No'),
        true
    );
    admin_webhook_add_change(
        $changes,
        'Commenting Disabled',
        ((int) ($before['comments_disabled'] ?? 0) === 1 ? 'Yes' : 'No'),
        ((int) ($after['comments_disabled'] ?? 0) === 1 ? 'Yes' : 'No'),
        true
    );
    admin_webhook_add_change(
        $changes,
        'Bonus Points',
        number_format((float) $before['bonus_points'], 2, '.', ''),
        number_format((float) $after['bonus_points'], 2, '.', ''),
        true
    );
    admin_webhook_add_change(
        $changes,
        'Total Points',
        number_format((float) $before['points'], 2, '.', ''),
        number_format((float) $after['points'], 2, '.', ''),
        true
    );

    if ($changes === []) {
        return;
    }

    $fields = [
        ['name' => 'User', 'value' => admin_webhook_text($after['username']) . ' (#' . (int) $after['id'] . ')', 'inline' => true],
        ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
        ['name' => 'Changes', 'value' => admin_webhook_changes_text($changes), 'inline' => false],
    ];

    $embed = [
        'title' => 'User updated',
        'color' => (int) $after['is_banned'] === 1 ? 15158332 : 3447003,
        'fields' => $fields,
        'timestamp' => gmdate('c'),
    ];

    send_discord_webhook('', [$embed]);
}

function admin_notify_submission_reviewed(array $submission, string $decision, string $reviewNote, string $playerName, array $recordFields = []): void
{
    $decision = strtolower($decision);
    $decisionLabel = strtoupper($decision);
    $decisionTitle = $decision === 'approved' ? 'Submission approved' : 'Submission rejected';

    $fields = [
        ['name' => 'Submission', 'value' => '#' . (int) ($submission['id'] ?? 0), 'inline' => true],
        ['name' => 'Demon', 'value' => admin_webhook_text($submission['demon_name'] ?? null), 'inline' => true],
        ['name' => 'Player', 'value' => admin_webhook_text($playerName), 'inline' => true],
        ['name' => 'Progress', 'value' => (int) ($submission['progress'] ?? 0) . '%', 'inline' => true],
        ['name' => 'By', 'value' => admin_webhook_actor_label(), 'inline' => true],
    ];

    $details = [];
    if ($reviewNote !== '') {
        $details[] = 'Note: ' . $reviewNote;
    }

    foreach ($recordFields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $name = trim((string) ($field['name'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));
        if ($name === '' || $value === '') {
            continue;
        }

        $details[] = $name . ': ' . $value;
        if (count($details) >= 6) {
            break;
        }
    }

    if ($details !== []) {
        $fields[] = ['name' => 'Details', 'value' => implode("\n", $details), 'inline' => false];
    }

    $embed = [
        'title' => $decisionTitle,
        'color' => $decision === 'approved' ? 5763719 : 15548997,
        'fields' => $fields,
        'timestamp' => gmdate('c'),
    ];

    send_discord_webhook('', [$embed]);
    admin_notify_submission_submitter_discord($submission, $decision, $reviewNote, $playerName, $recordFields);
}

function admin_notify_submission_submitter_discord(array $submission, string $decision, string $reviewNote, string $playerName, array $recordFields = []): void
{
    if (!users_has_discord_link_columns()) {
        return;
    }

    $submitterUserId = (int) ($submission['submitted_by_user_id'] ?? 0);
    if ($submitterUserId < 1) {
        return;
    }

    $decision = strtolower($decision);
    $decisionLabel = strtoupper($decision);
    $fields = [
        ['name' => 'Submission', 'value' => '#' . (int) ($submission['id'] ?? 0), 'inline' => true],
        ['name' => 'Decision', 'value' => $decisionLabel, 'inline' => true],
        ['name' => 'Demon', 'value' => admin_webhook_text($submission['demon_name'] ?? null), 'inline' => false],
        ['name' => 'Player', 'value' => admin_webhook_text($playerName), 'inline' => true],
        ['name' => 'Progress', 'value' => (int) ($submission['progress'] ?? 0) . '%', 'inline' => true],
        ['name' => 'Reviewed By', 'value' => admin_webhook_actor_label(), 'inline' => true],
    ];

    if ($reviewNote !== '') {
        $fields[] = ['name' => 'Review Note', 'value' => $reviewNote, 'inline' => false];
    }

    foreach ($recordFields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = trim((string) ($field['name'] ?? ''));
        $value = trim((string) ($field['value'] ?? ''));
        if ($name === '' || $value === '') {
            continue;
        }
        $fields[] = [
            'name' => $name,
            'value' => $value,
            'inline' => !empty($field['inline']),
        ];
        if (count($fields) >= 25) {
            break;
        }
    }

    $embed = [
        'title' => 'Your Submission Was ' . $decisionLabel,
        'description' => $decision === 'approved'
            ? 'Record accepted.'
            : 'Record rejected.',
        'url' => absolute_url('account.php'),
        'color' => $decision === 'approved' ? 5763719 : 15548997,
        'fields' => $fields,
        'timestamp' => gmdate('c'),
    ];

    send_discord_user_notification(
        db(),
        $submitterUserId,
        app_name() . ': submission #' . (int) ($submission['id'] ?? 0) . ' ' . $decision . '.',
        [$embed]
    );
}

if (method_is_post()) {
    $action = (string) ($_POST['action'] ?? '');


    if ($action === 'update_scoring' && can_manage_scoring()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-scoring'));
        }

        $topOneInput = trim((string) ($_POST['top1_points'] ?? ''));
        if ($topOneInput === '' || !is_numeric($topOneInput)) {
            flash('error', 'Top #1 points must be a valid number.');
            redirect(admin_section_url('admin-scoring'));
        }

        $topOnePoints = round((float) $topOneInput, 2);
        if (!demonlist_set_top1_points($topOnePoints)) {
            flash(
                'error',
                'Top #1 points must be between '
                . number_format(demonlist_top1_points_min(), 2)
                . ' and '
                . number_format(demonlist_top1_points_max(), 2)
                . '.'
            );
            redirect(admin_section_url('admin-scoring'));
        }

        $syncMessage = '. Demon score values are now scaled proportionally.';
        try {
            $syncSummary = demonlist_sync_user_points();
            $syncMessage .= ' Updated '
                . (int) ($syncSummary['updated_users'] ?? 0)
                . ' / '
                . (int) ($syncSummary['processed_users'] ?? 0)
                . ' user point totals.';
        } catch (Throwable) {
            $syncMessage .= ' Point sync failed in this request. Open Stats Viewer once to refresh points.';
        }

        flash(
            'success',
            'Updated top #1 points to '
            . number_format($topOnePoints, 2)
            . $syncMessage
        );
        redirect(admin_section_url('admin-scoring'));
    }

    if ($action === 'update_list_visibility' && can_manage_list_visibility()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-list-visibility'));
        }

        $showExtendedList = isset($_POST['show_extended_list']);
        $showLegacyList = isset($_POST['show_legacy_list']);
        $mainListLimitInput = trim((string) ($_POST['main_list_limit'] ?? ''));
        $extendedListLimitInput = trim((string) ($_POST['extended_list_limit'] ?? ''));

        if (
            $mainListLimitInput === ''
            || preg_match('/^\d+$/', $mainListLimitInput) !== 1
            || $extendedListLimitInput === ''
            || preg_match('/^\d+$/', $extendedListLimitInput) !== 1
        ) {
            flash('error', 'Main/Extended max rank must be whole numbers.');
            redirect(admin_section_url('admin-list-visibility'));
        }

        $mainListLimit = (int) $mainListLimitInput;
        $extendedListLimit = (int) $extendedListLimitInput;
        if (!demonlist_list_limits_are_valid($mainListLimit, $extendedListLimit)) {
            flash(
                'error',
                'Main max rank must be between '
                . demonlist_list_limit_min()
                . ' and '
                . demonlist_list_limit_max()
                . '. Extended max rank must be >= Main max rank and <= '
                . demonlist_list_limit_max()
                . '.'
            );
            redirect(admin_section_url('admin-list-visibility'));
        }

        if (
            !demonlist_set_show_extended_list($showExtendedList)
            || !demonlist_set_show_legacy_list($showLegacyList)
            || !demonlist_set_list_limits($mainListLimit, $extendedListLimit)
        ) {
            flash('error', 'Could not save list settings. Please try again.');
            redirect(admin_section_url('admin-list-visibility'));
        }

        $syncMessage = '';
        try {
            $syncSummary = demonlist_sync_user_points();
            $syncMessage = ' Synced '
                . (int) ($syncSummary['updated_users'] ?? 0)
                . ' / '
                . (int) ($syncSummary['processed_users'] ?? 0)
                . ' user point totals.';
        } catch (Throwable) {
            $syncMessage = ' Point sync failed in this request. Open Stats Viewer once to refresh points.';
        }

        flash(
            'success',
            'Saved list settings. Main: #1-#'
            . $mainListLimit
            . ', Extended: '
            . ($extendedListLimit > $mainListLimit
                ? ('#' . ($mainListLimit + 1) . '-#' . $extendedListLimit)
                : 'none')
            . '. Visibility -> Extended: '
            . ($showExtendedList ? 'ON' : 'OFF')
            . ', Legacy: '
            . ($showLegacyList ? 'ON' : 'OFF')
            . '.'
            . $syncMessage
        );
        redirect(admin_section_url('admin-list-visibility'));
    }

    if ($action === 'update_level_info_rows' && can_manage_levels()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-level-info-rows'));
        }

        $mode = strtolower(trim((string) ($_POST['mode'] ?? 'save')));
        if ($mode === 'restore') {
            if (demon_level_info_restore_default_rows()) {
                flash('success', 'Restored Level Info rows to the default layout.');
            } else {
                flash('error', 'Could not restore Level Info rows.');
            }
            redirect(admin_section_url('admin-level-info-rows'));
        }

        $types = $_POST['level_info_type'] ?? [];
        $fields = $_POST['level_info_field'] ?? [];
        $keys = $_POST['level_info_custom_key'] ?? [];
        $labels = $_POST['level_info_label'] ?? [];
        $defaultValues = $_POST['level_info_default_value'] ?? [];
        if (
            !is_array($types)
            || !is_array($fields)
            || !is_array($keys)
            || !is_array($labels)
            || !is_array($defaultValues)
        ) {
            flash('error', 'Invalid Level Info rows payload.');
            redirect(admin_section_url('admin-level-info-rows'));
        }

        $types = array_values($types);
        $fields = array_values($fields);
        $keys = array_values($keys);
        $labels = array_values($labels);
        $defaultValues = array_values($defaultValues);

        $rows = [];
        foreach ($types as $index => $type) {
            $rows[] = [
                'type' => (string) $type,
                'field' => (string) ($fields[$index] ?? ''),
                'key' => (string) ($keys[$index] ?? ''),
                'label' => (string) ($labels[$index] ?? ''),
                'default_value' => (string) ($defaultValues[$index] ?? ''),
            ];
        }

        if (!demon_level_info_set_rows($rows)) {
            flash('error', 'Could not save Level Info rows.');
            redirect(admin_section_url('admin-level-info-rows'));
        }

        flash('success', 'Saved Level Info rows.');
        redirect(admin_section_url('admin-level-info-rows'));
    }

    if ($action === 'update_comment_settings' && can_manage_levels()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-level-comments'));
        }

        $commentsEnabled = isset($_POST['comments_enabled']);
        $levelNameInput = trim((string) ($_POST['comment_level_name'] ?? ''));
        $levelCommentStatus = strtolower(trim((string) ($_POST['level_comment_status'] ?? 'keep')));
        if (!in_array($levelCommentStatus, ['keep', 'enabled', 'disabled'], true)) {
            flash('error', 'Invalid level comment status.');
            redirect(admin_section_url('admin-level-comments'));
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            if (!level_comments_set_enabled($commentsEnabled)) {
                throw new RuntimeException('Could not save global comment setting.');
            }

            $levelMessage = '';
            if ($levelNameInput !== '') {
                if ($levelCommentStatus === 'keep') {
                    throw new RuntimeException('Choose Enable or Disable for the selected level.');
                }

                $targetStmt = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1 FOR UPDATE');
                $targetStmt->execute([':name' => $levelNameInput]);
                $target = $targetStmt->fetch();

                if ($target === false) {
                    $partial = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                    $partial->execute([':query' => '%' . strtolower($levelNameInput) . '%']);
                    $matches = $partial->fetchAll();

                    if (count($matches) === 0) {
                        throw new RuntimeException('Level not found. Please type a valid level name.');
                    }
                    if (count($matches) > 1) {
                        throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                    }

                    $target = $matches[0];
                }

                $levelCommentsDisabled = $levelCommentStatus === 'disabled' ? 1 : 0;
                $updateLevelComments = $pdo->prepare('UPDATE demons SET comments_disabled = :comments_disabled WHERE id = :id');
                $updateLevelComments->execute([
                    ':comments_disabled' => $levelCommentsDisabled,
                    ':id' => (int) $target['id'],
                ]);

                $levelMessage = ' Level "' . (string) $target['name'] . '" comments: '
                    . ($levelCommentsDisabled === 1 ? 'disabled' : 'enabled') . '.';
            }

            $pdo->commit();
            flash('success', 'Comments are now ' . ($commentsEnabled ? 'enabled' : 'disabled') . ' for the list.' . $levelMessage);
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-level-comments'));
    }

    if ($action === 'delete_reported_level_comment' && can_moderate_level_comments()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-level-comments'));
        }

        $commentId = (int) ($_POST['comment_id'] ?? 0);
        if ($commentId < 1) {
            flash('error', 'Choose a reported comment to delete.');
            redirect(admin_section_url('admin-level-comments'));
        }

        try {
            $pdo = db();
            $commentStmt = $pdo->prepare(
                'SELECT lc.id, d.name AS demon_name
                 FROM level_comments lc
                 INNER JOIN demons d ON d.id = lc.demon_id
                 WHERE lc.id = :id
                 LIMIT 1'
            );
            $commentStmt->execute([':id' => $commentId]);
            $comment = $commentStmt->fetch();
            if ($comment === false) {
                throw new RuntimeException('Reported comment not found.');
            }

            $delete = $pdo->prepare('DELETE FROM level_comments WHERE id = :id');
            $delete->execute([':id' => $commentId]);
            flash('success', 'Deleted reported comment from ' . (string) $comment['demon_name'] . '.');
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-level-comments'));
    }

    if ($action === 'update_role_permissions' && can_manage_role_permissions()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-role-permissions'));
        }

        $postedPermissions = $_POST['permissions'] ?? [];
        if (!is_array($postedPermissions)) {
            flash('error', 'Invalid permission payload.');
            redirect(admin_section_url('admin-role-permissions'));
        }

        try {
            $rolesToUpdate = ['list_editor', 'list_helper'];
            $permissionKeys = admin_permission_keys();
            $updatedCount = 0;

            foreach ($rolesToUpdate as $roleKey) {
                foreach ($permissionKeys as $permissionKey) {
                    $rawValue = $postedPermissions[$roleKey][$permissionKey] ?? '0';
                    $allowed = in_array(
                        strtolower(trim((string) $rawValue)),
                        ['1', 'true', 'yes', 'on'],
                        true
                    );

                    if (!admin_set_role_permission($roleKey, $permissionKey, $allowed)) {
                        throw new RuntimeException('Could not save permission matrix. Please try again.');
                    }

                    $updatedCount++;
                }
            }

            flash('success', 'Saved custom permissions for List Editor and List Helper (' . $updatedCount . ' values).');
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-role-permissions'));
    }

    if ($action === 'create_badge' && can_manage_badges()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-badges'));
        }

        $badgeName = normalize_badge_name((string) ($_POST['badge_name'] ?? ''));
        $badgeDescription = normalize_badge_description((string) ($_POST['badge_description'] ?? ''));
        try {
            $badgeImageUrl = admin_badge_uploaded_image_url($_FILES['badge_image_file'] ?? null);
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
            redirect(admin_section_url('admin-badges'));
        }
        if ($badgeName === '') {
            flash('error', 'Badge name is required.');
            redirect(admin_section_url('admin-badges'));
        }
        if ($badgeImageUrl === null || $badgeImageUrl === '') {
            flash('error', 'Badge image is required. Please upload a PNG, JPG, GIF, or WEBP image.');
            redirect(admin_section_url('admin-badges'));
        }

        try {
            $stmt = db()->prepare(
                'INSERT INTO badges (name, description, image_url, created_by_user_id)
                 VALUES (:name, :description, :image_url, :created_by_user_id)'
            );
            $stmt->execute([
                ':name' => $badgeName,
                ':description' => $badgeDescription !== '' ? $badgeDescription : null,
                ':image_url' => $badgeImageUrl,
                ':created_by_user_id' => current_user_id(),
            ]);

            flash('success', 'Created badge "' . $badgeName . '".');
        } catch (Throwable $throwable) {
            $message = $throwable instanceof PDOException && (string) $throwable->getCode() === '23000'
                ? 'A badge with that name already exists.'
                : 'Could not create badge: ' . $throwable->getMessage();
            flash('error', $message);
        }

        redirect(admin_section_url('admin-badges'));
    }

    if ($action === 'update_badge' && can_manage_badges()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-badges'));
        }

        $badgeId = (int) ($_POST['badge_id'] ?? 0);
        $badgeName = normalize_badge_name((string) ($_POST['badge_name'] ?? ''));
        $badgeDescription = normalize_badge_description((string) ($_POST['badge_description'] ?? ''));

        if ($badgeId < 1 || $badgeName === '') {
            flash('error', 'Choose a badge and enter a badge name.');
            redirect(admin_section_url('admin-badges'));
        }

        try {
            $pdo = db();
            $badgeStmt = $pdo->prepare('SELECT id, name, image_url FROM badges WHERE id = :id LIMIT 1');
            $badgeStmt->execute([':id' => $badgeId]);
            $badge = $badgeStmt->fetch();
            if ($badge === false) {
                throw new RuntimeException('Badge not found.');
            }

            $uploadedBadgeImageUrl = admin_badge_uploaded_image_url($_FILES['badge_image_file'] ?? null);
            $badgeImageUrl = $uploadedBadgeImageUrl;
            if ($badgeImageUrl === null || $badgeImageUrl === '') {
                $badgeImageUrl = normalize_badge_image_url((string) ($badge['image_url'] ?? ''));
            }
            if ($badgeImageUrl === '') {
                throw new RuntimeException('Badge image is required. Please upload a PNG, JPG, GIF, or WEBP image.');
            }

            $update = $pdo->prepare(
                'UPDATE badges
                 SET name = :name,
                     description = :description,
                     image_url = :image_url,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                ':name' => $badgeName,
                ':description' => $badgeDescription !== '' ? $badgeDescription : null,
                ':image_url' => $badgeImageUrl,
                ':id' => $badgeId,
            ]);

            flash('success', 'Updated badge "' . $badgeName . '".');
        } catch (Throwable $throwable) {
            $message = $throwable instanceof PDOException && (string) $throwable->getCode() === '23000'
                ? 'A badge with that name already exists.'
                : $throwable->getMessage();
            flash('error', $message);
        }

        redirect(admin_section_url('admin-badges'));
    }

    if ($action === 'delete_badge' && can_manage_badges()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-badges'));
        }

        $badgeId = (int) ($_POST['badge_id'] ?? 0);
        if ($badgeId < 1) {
            flash('error', 'Choose a badge to delete.');
            redirect(admin_section_url('admin-badges'));
        }

        try {
            $pdo = db();
            $pdo->beginTransaction();

            $badgeStmt = $pdo->prepare('SELECT id, name FROM badges WHERE id = :id LIMIT 1 FOR UPDATE');
            $badgeStmt->execute([':id' => $badgeId]);
            $badge = $badgeStmt->fetch();
            if ($badge === false) {
                throw new RuntimeException('Badge not found.');
            }

            $removeAssignments = $pdo->prepare('DELETE FROM user_badges WHERE badge_id = :badge_id');
            $removeAssignments->execute([':badge_id' => $badgeId]);

            $deleteBadge = $pdo->prepare('DELETE FROM badges WHERE id = :id');
            $deleteBadge->execute([':id' => $badgeId]);

            $pdo->commit();
            flash('success', 'Deleted badge "' . (string) $badge['name'] . '".');
        } catch (Throwable $throwable) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Could not delete badge: ' . $throwable->getMessage());
        }

        redirect(admin_section_url('admin-badges'));
    }

    if ($action === 'assign_badge' && can_manage_badges()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-badges'));
        }

        $badgeId = (int) ($_POST['badge_id'] ?? 0);
        $targetUsername = trim((string) ($_POST['badge_username'] ?? ''));
        $mode = strtolower(trim((string) ($_POST['badge_mode'] ?? 'assign')));

        if ($badgeId < 1 || $targetUsername === '' || !in_array($mode, ['assign', 'remove'], true)) {
            flash('error', 'Choose a badge, user, and valid action.');
            redirect(admin_section_url('admin-badges'));
        }

        try {
            $pdo = db();
            $userId = admin_user_id_by_username($pdo, $targetUsername);
            if ($userId === null) {
                throw new RuntimeException('User not found.');
            }

            $badgeStmt = $pdo->prepare('SELECT id, name FROM badges WHERE id = :id AND COALESCE(is_active, 1) = 1 LIMIT 1');
            $badgeStmt->execute([':id' => $badgeId]);
            $badge = $badgeStmt->fetch();
            if ($badge === false) {
                throw new RuntimeException('Badge not found.');
            }

            if ($mode === 'assign') {
                $assign = $pdo->prepare(
                    'INSERT IGNORE INTO user_badges (user_id, badge_id, assigned_by_user_id)
                     VALUES (:user_id, :badge_id, :assigned_by_user_id)'
                );
                $assign->execute([
                    ':user_id' => $userId,
                    ':badge_id' => $badgeId,
                    ':assigned_by_user_id' => current_user_id(),
                ]);
                flash('success', 'Assigned "' . (string) $badge['name'] . '" to ' . $targetUsername . '.');
            } else {
                $remove = $pdo->prepare('DELETE FROM user_badges WHERE user_id = :user_id AND badge_id = :badge_id');
                $remove->execute([
                    ':user_id' => $userId,
                    ':badge_id' => $badgeId,
                ]);
                flash('success', 'Removed "' . (string) $badge['name'] . '" from ' . $targetUsername . '.');
            }
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-badges'));
    }

    if ($action === 'claim_contributor' && can_claim_contributors()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-claims'));
        }

        $demonNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $claimRole = strtolower(trim((string) ($_POST['claim_role'] ?? '')));
        $claimUsernameInput = trim((string) ($_POST['claim_username'] ?? ''));

        if ($demonNameInput === '') {
            flash('error', 'Please enter a level name to claim.');
            redirect(admin_section_url('admin-claims'));
        }
        if (!in_array($claimRole, ['publisher', 'verifier'], true)) {
            flash('error', 'Invalid claim role.');
            redirect(admin_section_url('admin-claims'));
        }

        $column = $claimRole === 'publisher' ? 'publisher_user_id' : 'verifier_user_id';
        $roleLabel = ucfirst($claimRole);
        $pdo = db();

        try {
            ensure_demon_claim_columns($pdo);
            $pdo->beginTransaction();

            $targetStmt = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':name' => $demonNameInput]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                $partial = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                $partial->execute([':query' => '%' . strtolower($demonNameInput) . '%']);
                $matches = $partial->fetchAll();

                if (count($matches) === 0) {
                    throw new RuntimeException('Level not found. Please type a valid level name.');
                }
                if (count($matches) > 1) {
                    throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                }

                $target = $matches[0];
            }

            $demonId = (int) ($target['id'] ?? 0);
            $demonName = trim((string) ($target['name'] ?? ''));
            if ($demonId < 1) {
                throw new RuntimeException('Level not found.');
            }

            $claimedUserId = null;
            $claimedUsername = '';
            if ($claimUsernameInput !== '') {
                $userStmt = $pdo->prepare('SELECT id, username FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
                $userStmt->execute([':username' => $claimUsernameInput]);
                $user = $userStmt->fetch();

                if ($user === false) {
                    throw new RuntimeException('User not found. Please type an existing username exactly.');
                }

                $claimedUserId = (int) ($user['id'] ?? 0);
                $claimedUsername = trim((string) ($user['username'] ?? ''));
                if ($claimedUserId < 1 || $claimedUsername === '') {
                    throw new RuntimeException('Invalid user selected for claim.');
                }
            }

            $update = $pdo->prepare('UPDATE demons SET ' . $column . ' = :user_id WHERE id = :id');
            $update->execute([
                ':user_id' => $claimedUserId,
                ':id' => $demonId,
            ]);

            $pdo->commit();

            if ($claimedUserId !== null) {
                flash('success', $roleLabel . ' claim updated: ' . $demonName . ' -> ' . $claimedUsername . '.');
            } else {
                flash('success', $roleLabel . ' claim cleared for ' . $demonName . '.');
            }
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-claims'));
    }

    if ($action === 'add_level' && can_manage_levels()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-add-level'));
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $difficulty = trim((string) ($_POST['difficulty'] ?? 'Extreme Demon'));
        $positionInput = trim((string) ($_POST['position'] ?? ''));
        $requirement = (int) ($_POST['requirement'] ?? 100);
        $creatorsInput = trim((string) ($_POST['creators'] ?? ''));
        $creatorParts = admin_creator_parts_from_input($creatorsInput);
        $publisher = trim((string) ($_POST['publisher'] ?? ''));
        $verifier = trim((string) ($_POST['verifier'] ?? ''));
        $description = normalize_demon_description((string) ($_POST['description'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnail = trim((string) ($_POST['thumbnail_url'] ?? ''));
        $levelId = trim((string) ($_POST['level_id'] ?? ''));
        $levelLength = trim((string) ($_POST['level_length'] ?? ''));
        $song = trim((string) ($_POST['song'] ?? ''));
        $objectCountInput = trim((string) ($_POST['object_count'] ?? ''));
        $legacy = isset($_POST['legacy']) ? 1 : 0;
        $commentsDisabled = isset($_POST['comments_disabled']) ? 1 : 0;
        $customLevelInfoUpdates = demon_level_info_custom_value_updates_from_post(
            $_POST['custom_level_info'] ?? [],
            $_POST['custom_level_info_clear'] ?? []
        );

        $errors = [];
        if ($name === '') {
            $errors[] = 'Level name is required.';
        }
        if ($creatorParts['creator'] === '') {
            $errors[] = 'Creator is required.';
        }
        if ($publisher === '') {
            $errors[] = 'Publisher is required.';
        }
        if ($videoUrl === '' || filter_var($videoUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Valid verification video URL is required.';
        }
        if ($thumbnail !== '' && filter_var($thumbnail, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Thumbnail URL must be valid when provided.';
        }
        if ($requirement < 1 || $requirement > 100) {
            $errors[] = 'Requirement must be between 1 and 100.';
        }

        $objectCount = null;
        if ($objectCountInput !== '') {
            if (!ctype_digit($objectCountInput)) {
                $errors[] = 'Object count must be a non-negative integer.';
            } else {
                $objectCount = (int) $objectCountInput;
            }
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect(admin_section_url('admin-add-level'));
        }

        $pdo = db();
        $transactionStarted = false;

        try {
            // All DDL / schema ensures MUST run before beginTransaction().
            // MySQL implicitly commits on DDL, which would otherwise leave
            // the later commit() throwing "There is no active transaction".
            ensure_demon_claim_columns($pdo);

            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }

            $dupStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
            $dupStmt->execute([':name' => $name]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException('A level with this name already exists.');
            }

            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            if ($positionInput !== '') {
                $position = (int) $positionInput;
                if ($position < 1) {
                    throw new RuntimeException('Position must be >= 1.');
                }
                if ($position > $maxPosition + 1) {
                    throw new RuntimeException('Position cannot be greater than ' . ($maxPosition + 1) . '.');
                }

                $positionOffset = $maxPosition + 1;

                $lift = $pdo->prepare('UPDATE demons
                    SET position = position + :offset
                    WHERE position >= :position');
                $lift->execute([
                    ':offset' => $positionOffset,
                    ':position' => $position,
                ]);

                $drop = $pdo->prepare('UPDATE demons
                    SET position = position - :shift
                    WHERE position >= :lifted_from');
                $drop->execute([
                    ':shift' => $positionOffset - 1,
                    ':lifted_from' => $position + $positionOffset,
                ]);
            } else {
                $position = $maxPosition + 1;
            }

            $publisherUserId = admin_user_id_by_username($pdo, $publisher);
            $verifierUserId = admin_user_id_by_username($pdo, $verifier);

            $insert = $pdo->prepare('INSERT INTO demons
                (position, name, difficulty, requirement, creator, creator_more, publisher, publisher_user_id, verifier, verifier_user_id, description, video_url, thumbnail_url, level_id, level_length, song, object_count, legacy, comments_disabled)
                VALUES
                (:position, :name, :difficulty, :requirement, :creator, :creator_more, :publisher, :publisher_user_id, :verifier, :verifier_user_id, :description, :video_url, :thumbnail_url, :level_id, :level_length, :song, :object_count, :legacy, :comments_disabled)');

            $insert->execute([
                ':position' => $position,
                ':name' => $name,
                ':difficulty' => $difficulty !== '' ? $difficulty : 'Extreme Demon',
                ':requirement' => $requirement,
                ':creator' => $creatorParts['creator'],
                ':creator_more' => $creatorParts['creator_more'] !== '' ? $creatorParts['creator_more'] : null,
                ':publisher' => $publisher,
                ':publisher_user_id' => $publisherUserId,
                ':verifier' => $verifier !== '' ? $verifier : null,
                ':verifier_user_id' => $verifierUserId,
                ':description' => $description !== '' ? $description : null,
                ':video_url' => $videoUrl,
                ':thumbnail_url' => $thumbnail !== '' ? $thumbnail : null,
                ':level_id' => $levelId !== '' ? $levelId : null,
                ':level_length' => $levelLength !== '' ? $levelLength : null,
                ':song' => $song !== '' ? $song : null,
                ':object_count' => $objectCount,
                ':legacy' => $legacy,
                ':comments_disabled' => $commentsDisabled,
            ]);

            $newDemonId = (int) $pdo->lastInsertId();
            if (!demon_level_info_save_custom_values(
                $pdo,
                $newDemonId,
                $customLevelInfoUpdates['values'],
                $customLevelInfoUpdates['clears']
            )) {
                throw new RuntimeException('Could not save custom Level Info values.');
            }

            record_position_event($pdo, $newDemonId, null, $position, current_user_id(), 'Level added');

            $createdLevelData = [
                'id' => $newDemonId,
                'position' => $position,
                'name' => $name,
                'difficulty' => $difficulty !== '' ? $difficulty : 'Extreme Demon',
                'requirement' => $requirement,
                'creator' => $creatorParts['creator'],
                'creator_more' => $creatorParts['creator_more'],
                'creator_display' => $creatorParts['creator_display'],
                'publisher' => $publisher,
                'publisher_user_id' => $publisherUserId,
                'verifier' => $verifier,
                'verifier_user_id' => $verifierUserId,
                'description' => $description,
                'video_url' => $videoUrl,
                'thumbnail_url' => $thumbnail,
                'level_id' => $levelId,
                'level_length' => $levelLength,
                'song' => $song,
                'object_count' => $objectCount,
                'legacy' => $legacy,
                'comments_disabled' => $commentsDisabled,
            ];

            admin_safe_transaction_commit($pdo, $transactionStarted, true);
            admin_notify_level_added($createdLevelData);

            flash('success', 'Level added at position #' . $position . '.');
        } catch (Throwable $throwable) {
            admin_safe_transaction_commit($pdo, $transactionStarted, false);
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-add-level'));
    }
    if ($action === 'edit_level' && can_manage_levels()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-edit-level'));
        }

        $targetNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $newNameInput = trim((string) ($_POST['name'] ?? ''));
        $difficultyInput = trim((string) ($_POST['difficulty'] ?? ''));
        $requirementInput = trim((string) ($_POST['requirement'] ?? ''));
        $creatorsInput = trim((string) ($_POST['creators'] ?? ''));
        $creatorPartsInput = admin_creator_parts_from_input($creatorsInput);
        $publisherInput = trim((string) ($_POST['publisher'] ?? ''));
        $verifierInput = trim((string) ($_POST['verifier'] ?? ''));
        $descriptionInput = normalize_demon_description((string) ($_POST['description'] ?? ''));
        $clearDescription = !empty($_POST['clear_description']);
        $videoUrlInput = trim((string) ($_POST['video_url'] ?? ''));
        $thumbnailInput = trim((string) ($_POST['thumbnail_url'] ?? ''));
        $levelIdInput = trim((string) ($_POST['level_id'] ?? ''));
        $levelLengthInput = trim((string) ($_POST['level_length'] ?? ''));
        $songInput = trim((string) ($_POST['song'] ?? ''));
        $objectCountInput = trim((string) ($_POST['object_count'] ?? ''));
        $legacyStatus = (string) ($_POST['legacy_status'] ?? 'keep');
        $commentStatus = strtolower(trim((string) ($_POST['comment_status'] ?? 'keep')));
        $newPositionInput = trim((string) ($_POST['new_position'] ?? ''));
        $moveNote = trim((string) ($_POST['move_note'] ?? ''));
        $customLevelInfoUpdates = demon_level_info_custom_value_updates_from_post(
            $_POST['custom_level_info'] ?? [],
            $_POST['custom_level_info_clear'] ?? []
        );

        if ($targetNameInput === '') {
            flash('error', 'Level name is required for editing.');
            redirect(admin_section_url('admin-edit-level'));
        }

        $errors = [];
        if ($requirementInput !== '' && !ctype_digit($requirementInput)) {
            $errors[] = 'Requirement must be a whole number.';
        }
        if ($objectCountInput !== '' && !ctype_digit($objectCountInput)) {
            $errors[] = 'Object count must be a non-negative integer.';
        }
        if ($newPositionInput !== '' && !ctype_digit($newPositionInput)) {
            $errors[] = 'New position must be a positive integer.';
        }
        if ($videoUrlInput !== '' && filter_var($videoUrlInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Verification video URL must be valid.';
        }
        if ($thumbnailInput !== '' && filter_var($thumbnailInput, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Thumbnail URL must be valid.';
        }
        if (!in_array($legacyStatus, ['keep', 'normal', 'legacy'], true)) {
            $errors[] = 'Invalid legacy status option.';
        }
        if (!in_array($commentStatus, ['keep', 'enabled', 'disabled'], true)) {
            $errors[] = 'Invalid comment status option.';
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect(admin_section_url('admin-edit-level'));
        }

        $pdo = db();
        $transactionStarted = false;

        try {
            ensure_demon_claim_columns($pdo);
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }

            $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':name' => $targetNameInput]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                $partial = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                $partial->execute([':query' => '%' . strtolower($targetNameInput) . '%']);
                $matches = $partial->fetchAll();

                if (count($matches) === 0) {
                    throw new RuntimeException('Level not found. Please type a valid level name.');
                }
                if (count($matches) > 1) {
                    throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                }

                $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
                $targetStmt->execute([':id' => (int) $matches[0]['id']]);
                $target = $targetStmt->fetch();
            }

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $demonId = (int) $target['id'];
            $oldPosition = (int) $target['position'];
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            $beforeLevelData = [
                'id' => $demonId,
                'position' => (int) $target['position'],
                'name' => (string) $target['name'],
                'difficulty' => (string) $target['difficulty'],
                'requirement' => (int) $target['requirement'],
                'creator' => (string) ($target['creator'] ?? ''),
                'creator_more' => (string) ($target['creator_more'] ?? ''),
                'creator_display' => implode(', ', demon_creator_names($target)),
                'publisher' => (string) $target['publisher'],
                'verifier' => (string) ($target['verifier'] ?? ''),
                'description' => (string) ($target['description'] ?? ''),
                'video_url' => (string) $target['video_url'],
                'thumbnail_url' => (string) ($target['thumbnail_url'] ?? ''),
                'level_id' => (string) ($target['level_id'] ?? ''),
                'level_length' => (string) ($target['level_length'] ?? ''),
                'song' => (string) ($target['song'] ?? ''),
                'object_count' => $target['object_count'] !== null ? (int) $target['object_count'] : null,
                'legacy' => (int) $target['legacy'],
                'comments_disabled' => (int) ($target['comments_disabled'] ?? 0),
            ];

            $currentPublisherUserId = isset($target['publisher_user_id']) ? (int) $target['publisher_user_id'] : 0;
            $currentVerifierUserId = isset($target['verifier_user_id']) ? (int) $target['verifier_user_id'] : 0;

            $finalName = $newNameInput !== '' ? $newNameInput : (string) $target['name'];
            $finalDifficulty = $difficultyInput !== '' ? $difficultyInput : (string) $target['difficulty'];
            $finalRequirement = $requirementInput !== '' ? (int) $requirementInput : (int) $target['requirement'];
            $finalCreator = $creatorsInput !== '' ? $creatorPartsInput['creator'] : (string) ($target['creator'] ?? '');
            $finalCreatorMore = $creatorsInput !== '' ? $creatorPartsInput['creator_more'] : (string) ($target['creator_more'] ?? '');
            $finalCreatorDisplay = $creatorsInput !== ''
                ? $creatorPartsInput['creator_display']
                : implode(', ', demon_creator_names($target));
            $finalPublisher = $publisherInput !== '' ? $publisherInput : (string) $target['publisher'];
            if ($finalCreator === '' && $creatorsInput === '') {
                $finalCreator = $finalPublisher;
                $finalCreatorDisplay = $finalPublisher;
            }
            $finalVerifier = $verifierInput !== '' ? $verifierInput : (string) ($target['verifier'] ?? '');
            $finalDescription = $clearDescription
                ? ''
                : ($descriptionInput !== '' ? $descriptionInput : (string) ($target['description'] ?? ''));
            $finalPublisherUserId = $publisherInput !== ''
                ? admin_user_id_by_username($pdo, $finalPublisher)
                : ($currentPublisherUserId > 0 ? $currentPublisherUserId : null);
            $finalVerifierUserId = $verifierInput !== ''
                ? admin_user_id_by_username($pdo, $finalVerifier)
                : ($currentVerifierUserId > 0 ? $currentVerifierUserId : null);
            $finalVideoUrl = $videoUrlInput !== '' ? $videoUrlInput : (string) $target['video_url'];
            $finalThumbnail = $thumbnailInput !== '' ? $thumbnailInput : (string) ($target['thumbnail_url'] ?? '');
            $finalLevelId = $levelIdInput !== '' ? $levelIdInput : (string) ($target['level_id'] ?? '');
            $finalLevelLength = $levelLengthInput !== '' ? $levelLengthInput : (string) ($target['level_length'] ?? '');
            $finalSong = $songInput !== '' ? $songInput : (string) ($target['song'] ?? '');
            $finalObjectCount = $objectCountInput !== ''
                ? (int) $objectCountInput
                : ($target['object_count'] !== null ? (int) $target['object_count'] : null);

            $finalLegacy = (int) $target['legacy'];
            if ($legacyStatus === 'normal') {
                $finalLegacy = 0;
            }
            if ($legacyStatus === 'legacy') {
                $finalLegacy = 1;
            }

            $finalCommentsDisabled = (int) ($target['comments_disabled'] ?? 0);
            if ($commentStatus === 'enabled') {
                $finalCommentsDisabled = 0;
            }
            if ($commentStatus === 'disabled') {
                $finalCommentsDisabled = 1;
            }

            if ($finalName === '') {
                throw new RuntimeException('Level name cannot be empty.');
            }
            if ($finalCreator === '') {
                throw new RuntimeException('Creator cannot be empty.');
            }
            if ($finalPublisher === '') {
                throw new RuntimeException('Publisher cannot be empty.');
            }
            if ($finalRequirement < 1 || $finalRequirement > 100) {
                throw new RuntimeException('Requirement must be between 1 and 100.');
            }
            if ($finalVideoUrl === '' || filter_var($finalVideoUrl, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Verification video URL must be valid.');
            }
            if ($finalThumbnail !== '' && filter_var($finalThumbnail, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Thumbnail URL must be valid.');
            }

            $dupStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1');
            $dupStmt->execute([
                ':name' => $finalName,
                ':id' => $demonId,
            ]);
            if ($dupStmt->fetch() !== false) {
                throw new RuntimeException('Another level already uses this name.');
            }

            $newPosition = $oldPosition;
            if ($newPositionInput !== '') {
                $newPosition = (int) $newPositionInput;
                if ($newPosition < 1 || $newPosition > $maxPosition) {
                    throw new RuntimeException('New position must be between 1 and ' . $maxPosition . '.');
                }
            }

            if ($newPosition !== $oldPosition) {
                $positionOffset = $maxPosition + 1;

                $parkTarget = $pdo->prepare('UPDATE demons SET position = :temporary_position WHERE id = :id');
                $parkTarget->execute([
                    ':temporary_position' => $positionOffset,
                    ':id' => $demonId,
                ]);

                if ($newPosition < $oldPosition) {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position >= :new_position
                          AND position < :old_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':new_position' => $newPosition,
                        ':old_position' => $oldPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position >= :lifted_from
                          AND position < :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset - 1,
                        ':lifted_from' => $newPosition + $positionOffset,
                        ':lifted_to' => $oldPosition + $positionOffset,
                    ]);
                } else {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position > :old_position
                          AND position <= :new_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':old_position' => $oldPosition,
                        ':new_position' => $newPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position > :lifted_from
                          AND position <= :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset + 1,
                        ':lifted_from' => $oldPosition + $positionOffset,
                        ':lifted_to' => $newPosition + $positionOffset,
                    ]);
                }

                record_position_event(
                    $pdo,
                    $demonId,
                    $oldPosition,
                    $newPosition,
                    current_user_id(),
                    $moveNote !== '' ? $moveNote : 'Position updated in level edit'
                );
            }

            $update = $pdo->prepare('UPDATE demons
                SET position = :position,
                    name = :name,
                    difficulty = :difficulty,
                    requirement = :requirement,
                    creator = :creator,
                    creator_more = :creator_more,
                    publisher = :publisher,
                    publisher_user_id = :publisher_user_id,
                    verifier = :verifier,
                    verifier_user_id = :verifier_user_id,
                    description = :description,
                    video_url = :video_url,
                    thumbnail_url = :thumbnail_url,
                    level_id = :level_id,
                    level_length = :level_length,
                    song = :song,
                    object_count = :object_count,
                    legacy = :legacy,
                    comments_disabled = :comments_disabled
                WHERE id = :id');

            $update->execute([
                ':position' => $newPosition,
                ':name' => $finalName,
                ':difficulty' => $finalDifficulty,
                ':requirement' => $finalRequirement,
                ':creator' => $finalCreator,
                ':creator_more' => $finalCreatorMore !== '' ? $finalCreatorMore : null,
                ':publisher' => $finalPublisher,
                ':publisher_user_id' => $finalPublisherUserId,
                ':verifier' => $finalVerifier !== '' ? $finalVerifier : null,
                ':verifier_user_id' => $finalVerifierUserId,
                ':description' => $finalDescription !== '' ? $finalDescription : null,
                ':video_url' => $finalVideoUrl,
                ':thumbnail_url' => $finalThumbnail !== '' ? $finalThumbnail : null,
                ':level_id' => $finalLevelId !== '' ? $finalLevelId : null,
                ':level_length' => $finalLevelLength !== '' ? $finalLevelLength : null,
                ':song' => $finalSong !== '' ? $finalSong : null,
                ':object_count' => $finalObjectCount,
                ':legacy' => $finalLegacy,
                ':comments_disabled' => $finalCommentsDisabled,
                ':id' => $demonId,
            ]);

            if (!demon_level_info_save_custom_values(
                $pdo,
                $demonId,
                $customLevelInfoUpdates['values'],
                $customLevelInfoUpdates['clears']
            )) {
                throw new RuntimeException('Could not save custom Level Info values.');
            }

            $afterLevelData = [
                'id' => $demonId,
                'position' => $newPosition,
                'name' => $finalName,
                'difficulty' => $finalDifficulty,
                'requirement' => $finalRequirement,
                'creator' => $finalCreator,
                'creator_more' => $finalCreatorMore,
                'creator_display' => $finalCreatorDisplay,
                'publisher' => $finalPublisher,
                'verifier' => $finalVerifier,
                'description' => $finalDescription,
                'video_url' => $finalVideoUrl,
                'thumbnail_url' => $finalThumbnail,
                'level_id' => $finalLevelId,
                'level_length' => $finalLevelLength,
                'song' => $finalSong,
                'object_count' => $finalObjectCount,
                'legacy' => $finalLegacy,
                'comments_disabled' => $finalCommentsDisabled,
            ];

            admin_safe_transaction_commit($pdo, $transactionStarted, true);
            admin_notify_level_updated($beforeLevelData, $afterLevelData, $moveNote);

            flash('success', 'Updated level #' . $newPosition . ' - ' . $finalName . '.');
        } catch (Throwable $throwable) {
            admin_safe_transaction_commit($pdo, $transactionStarted, false);
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-edit-level'));
    }

    if ($action === 'delete_level' && can_manage_levels()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-delete-level'));
        }

        $targetNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $confirmNameInput = trim((string) ($_POST['confirm_name'] ?? ''));

        if ($targetNameInput === '' || $confirmNameInput === '') {
            flash('error', 'Level name and confirmation are required for deletion.');
            redirect(admin_section_url('admin-delete-level'));
        }

        $pdo = db();
        $transactionStarted = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }

            $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':name' => $targetNameInput]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                $partial = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                $partial->execute([':query' => '%' . strtolower($targetNameInput) . '%']);
                $matches = $partial->fetchAll();

                if (count($matches) === 0) {
                    throw new RuntimeException('Level not found. Please type a valid level name.');
                }
                if (count($matches) > 1) {
                    throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                }

                $targetStmt = $pdo->prepare('SELECT * FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
                $targetStmt->execute([':id' => (int) $matches[0]['id']]);
                $target = $targetStmt->fetch();
            }

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $demonId = (int) $target['id'];
            $demonName = (string) $target['name'];
            $oldPosition = (int) $target['position'];

            if (strcasecmp($confirmNameInput, $demonName) !== 0) {
                throw new RuntimeException('Confirmation does not match the selected level name.');
            }

            $recordsStmt = $pdo->prepare('SELECT COUNT(*) FROM completions WHERE demon_id = :id');
            $recordsStmt->execute([':id' => $demonId]);
            $recordsRemoved = (int) $recordsStmt->fetchColumn();
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            $deleteRecords = $pdo->prepare('DELETE FROM completions WHERE demon_id = :id');
            $deleteRecords->execute([':id' => $demonId]);

            $deleteHistory = $pdo->prepare('DELETE FROM demon_position_history WHERE demon_id = :id');
            $deleteHistory->execute([':id' => $demonId]);

            $delete = $pdo->prepare('DELETE FROM demons WHERE id = :id');
            $delete->execute([':id' => $demonId]);

            if ($oldPosition < $maxPosition) {
                $positionOffset = $maxPosition + 1;

                $lift = $pdo->prepare('UPDATE demons
                    SET position = position + :offset
                    WHERE position > :old_position');
                $lift->execute([
                    ':offset' => $positionOffset,
                    ':old_position' => $oldPosition,
                ]);

                $drop = $pdo->prepare('UPDATE demons
                    SET position = position - :shift
                    WHERE position > :lifted_from');
                $drop->execute([
                    ':shift' => $positionOffset + 1,
                    ':lifted_from' => $oldPosition + $positionOffset,
                ]);
            }

            $deletedLevelData = [
                'id' => $demonId,
                'position' => $oldPosition,
                'name' => $demonName,
                'records_removed' => $recordsRemoved,
            ];

            admin_safe_transaction_commit($pdo, $transactionStarted, true);
            admin_notify_level_deleted($deletedLevelData);

            flash('success', 'Deleted #' . $oldPosition . ' - ' . $demonName . ' and shifted later positions down.');
        } catch (Throwable $throwable) {
            admin_safe_transaction_commit($pdo, $transactionStarted, false);
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-delete-level'));
    }

    if ($action === 'move_level' && can_manage_levels()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-edit-level'));
        }

        $demonId = (int) ($_POST['demon_id'] ?? 0);
        $demonNameInput = trim((string) ($_POST['demon_name'] ?? ''));
        $newPosition = (int) ($_POST['new_position'] ?? 0);
        $note = trim((string) ($_POST['move_note'] ?? ''));

        if ($newPosition < 1 || ($demonId < 1 && $demonNameInput === '')) {
            flash('error', 'Invalid level move request.');
            redirect(admin_section_url('admin-edit-level'));
        }

        $pdo = db();
        $transactionStarted = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $transactionStarted = true;
            }

            if ($demonId < 1) {
                $exactByName = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                $exactByName->execute([':name' => $demonNameInput]);
                $matchId = $exactByName->fetchColumn();

                if ($matchId === false) {
                    $partialByName = $pdo->prepare('SELECT id, name FROM demons WHERE LOWER(name) LIKE :query ORDER BY position ASC LIMIT 2');
                    $partialByName->execute([':query' => '%' . strtolower($demonNameInput) . '%']);
                    $matches = $partialByName->fetchAll();

                    if (count($matches) === 0) {
                        throw new RuntimeException('Level not found. Please type a valid level name.');
                    }
                    if (count($matches) > 1) {
                        throw new RuntimeException('Multiple levels match this name. Please type the full level name.');
                    }

                    $matchId = (int) $matches[0]['id'];
                }

                $demonId = (int) $matchId;
            }

            $targetStmt = $pdo->prepare('SELECT id, name, position FROM demons WHERE id = :id LIMIT 1 FOR UPDATE');
            $targetStmt->execute([':id' => $demonId]);
            $target = $targetStmt->fetch();

            if ($target === false) {
                throw new RuntimeException('Level not found.');
            }

            $oldPosition = (int) $target['position'];
            $maxPosition = (int) $pdo->query('SELECT COALESCE(MAX(position), 0) FROM demons')->fetchColumn();

            if ($newPosition > $maxPosition) {
                throw new RuntimeException('New position cannot exceed ' . $maxPosition . '.');
            }

            if ($newPosition !== $oldPosition) {
                $positionOffset = $maxPosition + 1;

                $parkTarget = $pdo->prepare('UPDATE demons SET position = :temporary_position WHERE id = :id');
                $parkTarget->execute([
                    ':temporary_position' => $positionOffset,
                    ':id' => $demonId,
                ]);

                if ($newPosition < $oldPosition) {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position >= :new_position
                          AND position < :old_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':new_position' => $newPosition,
                        ':old_position' => $oldPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position >= :lifted_from
                          AND position < :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset - 1,
                        ':lifted_from' => $newPosition + $positionOffset,
                        ':lifted_to' => $oldPosition + $positionOffset,
                    ]);
                } else {
                    $lift = $pdo->prepare('UPDATE demons
                        SET position = position + :offset
                        WHERE position > :old_position
                          AND position <= :new_position');
                    $lift->execute([
                        ':offset' => $positionOffset,
                        ':old_position' => $oldPosition,
                        ':new_position' => $newPosition,
                    ]);

                    $drop = $pdo->prepare('UPDATE demons
                        SET position = position - :shift
                        WHERE position > :lifted_from
                          AND position <= :lifted_to');
                    $drop->execute([
                        ':shift' => $positionOffset + 1,
                        ':lifted_from' => $oldPosition + $positionOffset,
                        ':lifted_to' => $newPosition + $positionOffset,
                    ]);
                }

                $updateTarget = $pdo->prepare('UPDATE demons SET position = :position WHERE id = :id');
                $updateTarget->execute([
                    ':position' => $newPosition,
                    ':id' => $demonId,
                ]);

                record_position_event(
                    $pdo,
                    $demonId,
                    $oldPosition,
                    $newPosition,
                    current_user_id(),
                    $note !== '' ? $note : 'Position moved in admin panel'
                );
            }

            admin_safe_transaction_commit($pdo, $transactionStarted, true);

            if ($newPosition !== $oldPosition) {
                admin_notify_level_moved((string) $target['name'], $demonId, $oldPosition, $newPosition, $note);
            }

            if ($newPosition === $oldPosition) {
                flash('success', 'No change made. Level is already at #' . $oldPosition . '.');
            } else {
                flash('success', 'Moved ' . (string) $target['name'] . ' from #' . $oldPosition . ' to #' . $newPosition . '.');
            }
        } catch (Throwable $throwable) {
            admin_safe_transaction_commit($pdo, $transactionStarted, false);
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-edit-level'));
    }

    if ($action === 'update_user' && can_manage_users()) {
        $usersQueryRedirect = trim((string) ($_POST['users_q'] ?? ''));
        $redirectTarget = admin_section_url(
            'admin-user-management',
            $usersQueryRedirect !== '' ? ['users_q' => $usersQueryRedirect] : []
        );

        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect($redirectTarget);
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $roleInput = strtolower(trim((string) ($_POST['role'] ?? '')));
        $allowedRoleInputs = ['player', 'list_helper', 'list_editor', 'owner'];
        $role = normalize_user_role($roleInput);
        $isBannedInput = (string) ($_POST['is_banned'] ?? '0');
        $commentsDisabledInput = (string) ($_POST['comments_disabled'] ?? '0');
        $bonusInput = trim((string) ($_POST['bonus_delta'] ?? '0'));

        if ($userId < 1 || !in_array($roleInput, $allowedRoleInputs, true)) {
            flash('error', 'Invalid user update request.');
            redirect($redirectTarget);
        }
        if (!in_array($isBannedInput, ['0', '1'], true)) {
            flash('error', 'Invalid banned status value.');
            redirect($redirectTarget);
        }
        if (!in_array($commentsDisabledInput, ['0', '1'], true)) {
            flash('error', 'Invalid comment status value.');
            redirect($redirectTarget);
        }
        if (!is_numeric($bonusInput)) {
            flash('error', 'Bonus value must be a valid number.');
            redirect($redirectTarget);
        }

        $isBanned = $isBannedInput === '1' ? 1 : 0;
        $commentsDisabled = $commentsDisabledInput === '1' ? 1 : 0;
        $bonusDelta = round((float) $bonusInput, 2);
        if ($bonusDelta < -999999 || $bonusDelta > 999999) {
            flash('error', 'Bonus value is out of range.');
            redirect($redirectTarget);
        }

        $pdo = db();
        ensure_bonus_points_column($pdo);
        ensure_user_banned_column($pdo);
        ensure_user_comments_disabled_column($pdo);
        if (!schema_users_role_enum_ready($pdo)) {
            schema_apply_users_role_enum($pdo);
        }

        try {
            $pdo->beginTransaction();

            $userStmt = $pdo->prepare('SELECT id, username, role, is_banned, comments_disabled, bonus_points, points FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $userStmt->execute([':id' => $userId]);
            $target = $userStmt->fetch();
            if ($target === false) {
                throw new RuntimeException('User not found.');
            }

            $currentRole = (string) $target['role'];
            $currentRoleNormalized = normalize_user_role($currentRole);
            $currentBanned = (int) ($target['is_banned'] ?? 0) === 1 ? 1 : 0;
            $currentCommentsDisabled = (int) ($target['comments_disabled'] ?? 0) === 1 ? 1 : 0;

            if (!can_manage_user_roles() && $role !== $currentRoleNormalized) {
                throw new RuntimeException(t('admin.error_role_permission'));
            }
            if (!can_manage_user_roles()) {
                $role = $currentRoleNormalized;
            }

            if ($currentRoleNormalized === 'owner' && $role !== 'owner') {
                $ownerCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "owner"')->fetchColumn();
                if ($ownerCount <= 1) {
                    throw new RuntimeException('Cannot demote the last owner account.');
                }
            }

            $currentBonus = round((float) ($target['bonus_points'] ?? 0.0), 2);
            $newBonus = round($currentBonus + $bonusDelta, 2);
            if ($newBonus < -999999 || $newBonus > 999999) {
                throw new RuntimeException('Bonus points value is out of range after applying changes.');
            }

            $currentPoints = round((float) ($target['points'] ?? 0.0), 2);
            $newPoints = round($currentPoints + $bonusDelta, 2);

            $beforeUserData = [
                'id' => (int) $target['id'],
                'username' => (string) $target['username'],
                'role' => $currentRoleNormalized,
                'is_banned' => $currentBanned,
                'comments_disabled' => $currentCommentsDisabled,
                'bonus_points' => $currentBonus,
                'points' => $currentPoints,
            ];

            $updateUser = $pdo->prepare('UPDATE users
                                         SET role = :role,
                                             is_banned = :is_banned,
                                             comments_disabled = :comments_disabled,
                                             bonus_points = :bonus_points,
                                             points = ROUND(COALESCE(points, 0.00) + :bonus_delta, 2)
                                         WHERE id = :id');
            $updateUser->execute([
                ':role' => $role,
                ':is_banned' => $isBanned,
                ':comments_disabled' => $commentsDisabled,
                ':bonus_points' => $newBonus,
                ':bonus_delta' => $bonusDelta,
                ':id' => $userId,
            ]);

            $afterUserData = [
                'id' => (int) $target['id'],
                'username' => (string) $target['username'],
                'role' => $role,
                'is_banned' => $isBanned,
                'comments_disabled' => $commentsDisabled,
                'bonus_points' => $newBonus,
                'points' => $newPoints,
            ];

            $pdo->commit();
            admin_notify_user_updated($beforeUserData, $afterUserData, $bonusDelta);

            flash('success', 'Updated user settings for ' . (string) $target['username'] . '. Bonus delta: ' . ($bonusDelta >= 0 ? '+' : '') . number_format($bonusDelta, 2) . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect($redirectTarget);
    }
    if ($action === 'reset_password' && can_reset_passwords()) {
        $usersQueryRedirect = trim((string) ($_POST['users_q'] ?? ''));
        $redirectTarget = admin_section_url(
            'admin-user-management',
            $usersQueryRedirect !== '' ? ['users_q' => $usersQueryRedirect] : []
        );

        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect($redirectTarget);
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId < 1) {
            flash('error', t('admin.error_invalid_reset_user'));
            redirect($redirectTarget);
        }

        try {
            $pdo = db();
            $userStmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute([':id' => $userId]);
            $user = $userStmt->fetch();

            if ($user === false) {
                flash('error', t('admin.user_not_found'));
                redirect($redirectTarget);
            }

            $tempPassword = admin_generate_temporary_password();
            $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

            $updateStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $updateStmt->execute([
                ':password_hash' => $passwordHash,
                ':id' => $userId,
            ]);

            if (users_has_login_lockout_columns()) {
                login_clear_failed_attempts($userId);
            }

            flash('success', t('admin.password_reset_success', ['user' => (string) $user['username'], 'password' => $tempPassword]));
        } catch (Throwable $throwable) {
            flash('error', t('admin.password_reset_failed', ['error' => $throwable->getMessage()]));
        }

        redirect($redirectTarget);
    }
    if ($action === 'review' && can_review_submissions()) {
        if (!validate_csrf($_POST['_token'] ?? null)) {
            flash('error', t('flash.invalid_token'));
            redirect(admin_section_url('admin-pending-submissions'));
        }

        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewNote = trim((string) ($_POST['review_note'] ?? ''));

        if ($submissionId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
            flash('error', 'Invalid review request.');
            redirect(admin_section_url('admin-pending-submissions'));
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            $submissionStmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id FOR UPDATE');
            $submissionStmt->execute([':id' => $submissionId]);
            $submission = $submissionStmt->fetch();

            if ($submission === false) {
                throw new RuntimeException('Submission not found.');
            }

            if ((string) $submission['status'] !== 'pending') {
                throw new RuntimeException('Submission already reviewed.');
            }

            $submissionPlayer = trim((string) ($submission['player'] ?? ''));
            if ($submissionPlayer === '' && (int) ($submission['submitted_by_user_id'] ?? 0) > 0) {
                $playerLookup = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
                $playerLookup->execute([':id' => (int) $submission['submitted_by_user_id']]);
                $submissionPlayer = (string) ($playerLookup->fetchColumn() ?: '');
            }
            if ($submissionPlayer === '') {
                $submissionPlayer = 'Unknown';
            }

            $recordWebhookFields = [];

            if ($decision === 'approved') {
                if ((string) $submission['type'] !== 'completion') {
                    throw new RuntimeException('Demon submissions are disabled. Add levels manually from the admin form.');
                }

                $demonStmt = $pdo->prepare('SELECT id FROM demons WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                $demonStmt->execute([':name' => (string) $submission['demon_name']]);
                $demonId = $demonStmt->fetchColumn();

                if ($demonId === false) {
                    throw new RuntimeException('Cannot approve completion: demon not found.');
                }

                $progress = max(1, min(100, (int) ($submission['progress'] ?? 100)));
                $submittedVideo = (string) ($submission['video_url'] ?: '#');
                $submittedNotes = trim((string) ($submission['notes'] ?? ''));

                $existingStmt = $pdo->prepare('SELECT id, progress, video_url, notes, placement FROM completions WHERE demon_id = :demon_id AND player = :player LIMIT 1');
                $existingStmt->execute([
                    ':demon_id' => (int) $demonId,
                    ':player' => $submissionPlayer,
                ]);
                $existing = $existingStmt->fetch();

                if ($existing !== false) {
                    $oldProgress = (int) ($existing['progress'] ?? 0);
                    $newProgress = max($oldProgress, $progress);
                    $oldVideo = (string) ($existing['video_url'] ?? '#');
                    $oldNotes = trim((string) ($existing['notes'] ?? ''));

                    $updateRecord = $pdo->prepare('UPDATE completions
                        SET video_url = :video_url,
                            progress = :progress,
                            notes = :notes
                        WHERE id = :id');

                    $updateRecord->execute([
                        ':video_url' => $submittedVideo,
                        ':progress' => $newProgress,
                        ':notes' => $submittedNotes !== '' ? $submittedNotes : null,
                        ':id' => (int) $existing['id'],
                    ]);

                    $recordWebhookFields[] = [
                        'name' => 'Record Action',
                        'value' => 'Updated completion #' . (int) $existing['id'],
                        'inline' => false,
                    ];
                    $recordWebhookFields[] = [
                        'name' => 'Placement',
                        'value' => '#' . (int) ($existing['placement'] ?? 0),
                        'inline' => true,
                    ];

                    $hasCompletionFieldChange = false;
                    if ($newProgress !== $oldProgress) {
                        $recordWebhookFields[] = [
                            'name' => 'Completion Progress',
                            'value' => $oldProgress . '% -> ' . $newProgress . '%',
                            'inline' => true,
                        ];
                        $hasCompletionFieldChange = true;
                    }

                    if ($oldVideo !== $submittedVideo) {
                        $recordWebhookFields[] = [
                            'name' => 'Video URL',
                            'value' => $oldVideo . ' -> ' . $submittedVideo,
                            'inline' => false,
                        ];
                        $hasCompletionFieldChange = true;
                    }

                    $oldNotesLabel = $oldNotes !== '' ? $oldNotes : '-';
                    $newNotesLabel = $submittedNotes !== '' ? $submittedNotes : '-';
                    if ($oldNotesLabel !== $newNotesLabel) {
                        $recordWebhookFields[] = [
                            'name' => 'Notes',
                            'value' => $oldNotesLabel . ' -> ' . $newNotesLabel,
                            'inline' => false,
                        ];
                        $hasCompletionFieldChange = true;
                    }

                    if (!$hasCompletionFieldChange) {
                        $recordWebhookFields[] = [
                            'name' => 'Record Delta',
                            'value' => 'No completion field changed (duplicate or equivalent proof).',
                            'inline' => false,
                        ];
                    }
                } else {
                    $placementStmt = $pdo->prepare('SELECT COALESCE(MAX(placement), 0) + 1 AS next_placement
                                                    FROM completions
                                                    WHERE demon_id = :demon_id');
                    $placementStmt->execute([':demon_id' => (int) $demonId]);
                    $nextPlacement = (int) $placementStmt->fetchColumn();

                    $insertCompletion = $pdo->prepare('INSERT INTO completions
                        (demon_id, player, video_url, progress, placement, notes)
                        VALUES
                        (:demon_id, :player, :video_url, :progress, :placement, :notes)');

                    $insertCompletion->execute([
                        ':demon_id' => (int) $demonId,
                        ':player' => $submissionPlayer,
                        ':video_url' => $submittedVideo,
                        ':progress' => $progress,
                        ':placement' => $nextPlacement,
                        ':notes' => $submittedNotes !== '' ? $submittedNotes : null,
                    ]);

                    $newCompletionId = (int) $pdo->lastInsertId();
                    $recordWebhookFields[] = [
                        'name' => 'Record Action',
                        'value' => 'Created completion #' . $newCompletionId,
                        'inline' => false,
                    ];
                    $recordWebhookFields[] = [
                        'name' => 'Placement',
                        'value' => '#' . $nextPlacement,
                        'inline' => true,
                    ];
                    $recordWebhookFields[] = [
                        'name' => 'Completion Progress',
                        'value' => $progress . '%',
                        'inline' => true,
                    ];
                }
            }

            $updateStmt = $pdo->prepare('UPDATE submissions
                                         SET status = :status,
                                             review_note = :review_note,
                                             reviewed_at = NOW()
                                         WHERE id = :id');
            $updateStmt->execute([
                ':status' => $decision,
                ':review_note' => $reviewNote !== '' ? $reviewNote : null,
                ':id' => $submissionId,
            ]);

            $pdo->commit();
            admin_notify_submission_reviewed($submission, $decision, $reviewNote, $submissionPlayer, $recordWebhookFields);

            flash('success', 'Submission #' . $submissionId . ' marked as ' . $decision . '.');
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $throwable->getMessage());
        }

        redirect(admin_section_url('admin-pending-submissions'));
    }
    flash('error', t('flash.no_permission'));
    redirect(admin_section_url('overview'));

}
if (!is_admin()) {
    http_response_code(403);
    render_header(t('admin.access_denied'), 'admin');
    ?>
    <section class="panel panel-narrow fade">
        <div class="panel-head">
            <h1><?= e(t('admin.access_denied')) ?></h1>
            <p><?= e(t('flash.no_permission')) ?></p>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

$adminPdo = db();
$hasBonusPoints = admin_column_exists($adminPdo, 'users', 'bonus_points');
$hasUserBanned = admin_column_exists($adminPdo, 'users', 'is_banned');
$hasUserCommentsDisabled = admin_column_exists($adminPdo, 'users', 'comments_disabled');

$stats = [
    'pending' => (int) $adminPdo->query('SELECT COUNT(*) FROM submissions WHERE status = "pending"')->fetchColumn(),
    'approved' => (int) $adminPdo->query('SELECT COUNT(*) FROM submissions WHERE status = "approved"')->fetchColumn(),
    'rejected' => (int) $adminPdo->query('SELECT COUNT(*) FROM submissions WHERE status = "rejected"')->fetchColumn(),
    'players' => (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE role = "player"')->fetchColumn(),
    'owners' => (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE role = "owner"')->fetchColumn(),
    'list_editors' => (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE role = "list_editor"')->fetchColumn(),
    'list_helpers' => (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE role = "list_helper"')->fetchColumn(),
];
$stats['banned'] = $hasUserBanned
    ? (int) $adminPdo->query('SELECT COUNT(*) FROM users WHERE COALESCE(is_banned, 0) = 1')->fetchColumn()
    : 0;

$adminRoleLabel = role_label(current_user_role());
$canManageLevels = can_manage_levels();
$canManageUsers = can_manage_users();
$canManageScoring = can_manage_scoring();
$canClaimContributors = can_claim_contributors();
$canReviewSubmissions = can_review_submissions();
$canManageListVisibility = can_manage_list_visibility();
$canManageRolePermissions = can_manage_role_permissions();
$canManageUserRoles = can_manage_user_roles();
$canManageBadges = can_manage_badges();
$canModerateLevelComments = can_moderate_level_comments();
$canResetPasswords = can_reset_passwords();

$hasGeneralQuickActions = $canManageLevels
    || $canManageUsers
    || $canManageScoring
    || $canClaimContributors
    || $canReviewSubmissions
    || $canManageBadges
    || $canModerateLevelComments;
$hasOwnerOnlyQuickActions = $canManageRolePermissions || $canManageListVisibility;

$sectionCapability = [
    'admin-role-permissions'     => $canManageRolePermissions,
    'admin-list-visibility'      => $canManageListVisibility,
    'admin-scoring'              => $canManageScoring,
    'admin-level-info-rows'      => $canManageLevels,
    'admin-level-comments'       => $canManageLevels || $canModerateLevelComments,
    'admin-claims'               => $canClaimContributors,
    'admin-add-level'            => $canManageLevels,
    'admin-edit-level'           => $canManageLevels,
    'admin-delete-level'         => $canManageLevels,
    'admin-user-management'      => $canManageUsers,
    'admin-pending-submissions'  => $canReviewSubmissions,
    'admin-reviewed-submissions' => $canReviewSubmissions,
    'admin-badges'               => $canManageBadges,
];
$requestedSection = (string) ($_GET['section'] ?? 'overview');
$activeSection = ($requestedSection !== 'overview'
    && array_key_exists($requestedSection, $sectionCapability)
    && $sectionCapability[$requestedSection])
    ? $requestedSection
    : 'overview';
$needsDemonList = in_array($activeSection, ['admin-edit-level', 'admin-claims', 'admin-level-comments', 'admin-delete-level'], true);
$needsLevelInfoCustomRows = in_array($activeSection, ['admin-add-level', 'admin-edit-level', 'admin-level-info-rows'], true);

$openCommentReportCount = 0;
if ($canModerateLevelComments && schema_table_exists($adminPdo, 'level_comment_reports')) {
    $openCommentReportCount = (int) $adminPdo->query('SELECT COUNT(DISTINCT comment_id) FROM level_comment_reports')->fetchColumn();
}

$pending = [];
if ($activeSection === 'admin-pending-submissions') {
    $pending = db()->query('SELECT s.*, u.username AS submitter_username
                            FROM submissions s
                            LEFT JOIN users u ON u.id = s.submitted_by_user_id
                            WHERE s.status = "pending"
                            ORDER BY s.created_at ASC')->fetchAll();
}

$reviewed = [];
if ($activeSection === 'admin-reviewed-submissions') {
    $reviewed = db()->query('SELECT s.*, u.username AS submitter_username
                             FROM submissions s
                             LEFT JOIN users u ON u.id = s.submitted_by_user_id
                             WHERE s.status <> "pending"
                             ORDER BY s.reviewed_at DESC
                             LIMIT 30')->fetchAll();
}

$topOnePoints = 0.0;
$topOnePointsInput = '';
$topOnePointsMinInput = '';
$topOnePointsMaxInput = '';
if ($activeSection === 'admin-scoring') {
    $topOnePoints = demonlist_top1_points();
    $topOnePointsInput = number_format($topOnePoints, 2, '.', '');
    $topOnePointsMinInput = number_format(demonlist_top1_points_min(), 2, '.', '');
    $topOnePointsMaxInput = number_format(demonlist_top1_points_max(), 2, '.', '');
}

$showExtendedList = false;
$showLegacyList = false;
$mainListLimit = 0;
$extendedListLimit = 0;
$listLimitMinInput = '';
$listLimitMaxInput = '';
if ($activeSection === 'admin-list-visibility') {
    $showExtendedList = demonlist_show_extended_list();
    $showLegacyList = demonlist_show_legacy_list();
    $mainListLimit = demonlist_main_list_limit();
    $extendedListLimit = demonlist_extended_list_limit();
    $listLimitMinInput = (string) demonlist_list_limit_min();
    $listLimitMaxInput = (string) demonlist_list_limit_max();
}

$hasPublisherClaimColumn = false;
$hasVerifierClaimColumn = false;
$claimColumnsReady = false;
$maxPosition = 1;
$editableDemons = [];
if ($needsDemonList) {
    $hasPublisherClaimColumn = admin_column_exists($adminPdo, 'demons', 'publisher_user_id');
    $hasVerifierClaimColumn = admin_column_exists($adminPdo, 'demons', 'verifier_user_id');
    $claimColumnsReady = $hasPublisherClaimColumn && $hasVerifierClaimColumn;
    $maxPosition = (int) $adminPdo->query('SELECT COALESCE(MAX(position), 1) FROM demons')->fetchColumn();
    $editableDemonFields = [
        'id',
        'name',
        'position',
        'requirement',
        'publisher',
        'verifier',
        $claimColumnsReady ? 'publisher_user_id' : 'NULL AS publisher_user_id',
        $claimColumnsReady ? 'verifier_user_id' : 'NULL AS verifier_user_id',
    ];
    $editableDemons = $adminPdo->query('SELECT ' . implode(', ', $editableDemonFields) . '
                                   FROM demons
                                   ORDER BY position ASC, name ASC')->fetchAll();
}

$claimUsers = $adminPdo->query('SELECT username FROM users ORDER BY username ASC LIMIT 500')->fetchAll();

$usersQuery = trim((string) ($_GET['users_q'] ?? ''));
$usersQuery = function_exists('mb_substr')
    ? (string) mb_substr($usersQuery, 0, 80)
    : (string) substr($usersQuery, 0, 80);

$users = [];
if ($activeSection === 'admin-user-management' && $usersQuery !== '') {
    $usersSelectFields = [
        'id',
        'username',
        'email',
        'country_code',
        'role',
        'points',
        $hasBonusPoints ? 'bonus_points' : '0.00 AS bonus_points',
        $hasUserBanned ? 'is_banned' : '0 AS is_banned',
        $hasUserCommentsDisabled ? 'comments_disabled' : '0 AS comments_disabled',
        'created_at',
    ];
    $usersSql = 'SELECT ' . implode(', ', $usersSelectFields) . '
                 FROM users
                 WHERE username LIKE :users_query
                 ORDER BY created_at DESC
                 LIMIT 20';
    $usersStmt = $adminPdo->prepare($usersSql);
    $usersStmt->execute([':users_query' => '%' . $usersQuery . '%']);
    $users = $usersStmt->fetchAll();
}

$levelInfoFieldDefinitions = [];
$levelInfoRows = [];
$levelInfoCustomRows = [];
if ($needsLevelInfoCustomRows) {
    $levelInfoFieldDefinitions = demon_level_info_field_definitions();
    $levelInfoRows = demon_level_info_rows();
    $levelInfoCustomRows = demon_level_info_custom_rows($levelInfoRows);
}

$levelCommentsEnabled = false;
$hasCommentsDisabledColumn = false;
$levelCommentsDisabledCount = 0;
$reportedLevelComments = [];
$reportedLevelCommentsError = '';
if ($activeSection === 'admin-level-comments') {
    $levelCommentsEnabled = level_comments_enabled();
    $hasCommentsDisabledColumn = admin_column_exists($adminPdo, 'demons', 'comments_disabled');
    $levelCommentsDisabledCount = $hasCommentsDisabledColumn
        ? (int) $adminPdo->query('SELECT COUNT(*) FROM demons WHERE COALESCE(comments_disabled, 0) = 1')->fetchColumn()
        : 0;

    if ($canModerateLevelComments && schema_table_exists($adminPdo, 'level_comment_reports')) {
        try {
            $reportedCommentsSql = 'SELECT lc.id,
                       lc.parent_comment_id,
                       lc.body,
                       lc.created_at,
                       lc.updated_at,
                       d.position AS demon_position,
                       d.name AS demon_name,
                       u.id AS user_id,
                       u.username,
                       u.country_code,
                       ' . user_select_display_name_expression('u', 'username', 'display_name') . ',
                       reports.report_count,
                       reports.latest_reported_at,
                       reports.report_summary
                FROM (
                    SELECT lcr.comment_id,
                           COUNT(*) AS report_count,
                           MAX(lcr.created_at) AS latest_reported_at,
                           GROUP_CONCAT(
                               CONCAT(
                                   COALESCE(ru.username, CONCAT(\'User #\', lcr.user_id)),
                                   CASE
                                       WHEN lcr.reason IS NULL OR TRIM(lcr.reason) = \'\' THEN \'\'
                                       ELSE CONCAT(\': \', lcr.reason)
                                   END
                               )
                               ORDER BY lcr.created_at DESC
                               SEPARATOR \'\\n\'
                           ) AS report_summary
                    FROM level_comment_reports lcr
                    LEFT JOIN users ru ON ru.id = lcr.user_id
                    GROUP BY lcr.comment_id
                ) reports
                INNER JOIN level_comments lc ON lc.id = reports.comment_id
                INNER JOIN demons d ON d.id = lc.demon_id
                INNER JOIN users u ON u.id = lc.user_id
                ORDER BY reports.latest_reported_at DESC
                LIMIT 50';
            $reportedLevelComments = $adminPdo->query($reportedCommentsSql)->fetchAll();
        } catch (Throwable $throwable) {
            $reportedLevelComments = [];
            $reportedLevelCommentsError = $throwable->getMessage();
        }
    }
}

$badgeList = [];
if ($activeSection === 'admin-badges' && $canManageBadges) {
    $badgeList = badge_fetch_all($adminPdo);
}

$editableStaffRoles = ['list_editor', 'list_helper'];
$permissionDefinitions = [];
$rolePermissionMatrix = [];
if ($activeSection === 'admin-role-permissions') {
    $permissionDefinitions = admin_permission_definitions();
    foreach ($editableStaffRoles as $staffRole) {
        $rolePermissionMatrix[$staffRole] = admin_role_permissions($staffRole);
    }
}

render_header(t('admin.title'), 'admin');
?>
<div class="admin-dashboard-layout">
    <aside class="admin-sidebar">
        <div class="admin-sidebar-role">
            <span class="muted"><?= e(t('admin.signed_in_as')) ?></span>
            <strong><?= e($adminRoleLabel) ?></strong>
        </div>

        <div class="admin-action-group">
            <div class="admin-quick-actions">
                <a class="admin-action-tile<?= $activeSection === 'overview' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('overview')) ?>">
                    <span class="admin-action-title"><?= e(t('admin.overview')) ?></span>
                    <small><?= e(t('admin.overview_desc')) ?></small>
                </a>
            </div>
        </div>

        <?php if ($canManageLevels || $canModerateLevelComments): ?>
            <div class="admin-action-group">
                <h3 class="admin-action-group-title"><?= e(t('admin.content')) ?></h3>
                <div class="admin-quick-actions">
                    <?php if ($canManageLevels): ?>
                        <a class="admin-action-tile<?= $activeSection === 'admin-add-level' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-add-level')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.add_level')) ?></span>
                            <small><?= e(t('admin.add_level_desc')) ?></small>
                        </a>
                        <a class="admin-action-tile<?= $activeSection === 'admin-edit-level' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-edit-level')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.edit_level')) ?></span>
                            <small><?= e(t('admin.edit_level_desc')) ?></small>
                        </a>
                        <a class="admin-action-tile<?= $activeSection === 'admin-delete-level' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-delete-level')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.delete_level')) ?></span>
                            <small><?= e(t('admin.delete_level_desc')) ?></small>
                        </a>
                        <a class="admin-action-tile<?= $activeSection === 'admin-level-info-rows' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-level-info-rows')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.level_info_rows')) ?></span>
                            <small><?= e(t('admin.level_info_rows_desc')) ?></small>
                        </a>
                    <?php endif; ?>
                    <a class="admin-action-tile<?= $activeSection === 'admin-level-comments' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-level-comments')) ?>">
                        <span class="admin-action-title"><?= e(t('admin.level_comments')) ?><?php if ($openCommentReportCount > 0): ?> <span class="admin-action-badge"><?= (int) $openCommentReportCount ?></span><?php endif; ?></span>
                        <small><?= e($canManageLevels ? t('admin.level_comments_desc') : t('admin.level_comments_moderate_desc')) ?></small>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canReviewSubmissions): ?>
            <div class="admin-action-group">
                <h3 class="admin-action-group-title"><?= e(t('admin.moderation')) ?></h3>
                <div class="admin-quick-actions">
                    <a class="admin-action-tile<?= $activeSection === 'admin-pending-submissions' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-pending-submissions')) ?>">
                        <span class="admin-action-title"><?= e(t('admin.pending_submissions')) ?><?php if ($stats['pending'] > 0): ?> <span class="admin-action-badge"><?= (int) $stats['pending'] ?></span><?php endif; ?></span>
                        <small><?= e(t('admin.pending_submissions_desc')) ?></small>
                    </a>
                    <a class="admin-action-tile<?= $activeSection === 'admin-reviewed-submissions' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-reviewed-submissions')) ?>">
                        <span class="admin-action-title"><?= e(t('admin.recently_reviewed')) ?></span>
                        <small><?= e(t('admin.recently_reviewed_desc')) ?></small>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canManageUsers || $canClaimContributors || $canManageBadges): ?>
            <div class="admin-action-group">
                <h3 class="admin-action-group-title"><?= e(t('admin.people')) ?></h3>
                <div class="admin-quick-actions">
                    <?php if ($canManageUsers): ?>
                        <a class="admin-action-tile<?= $activeSection === 'admin-user-management' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-user-management')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.user_management')) ?><?php if ($stats['banned'] > 0): ?> <span class="admin-action-badge"><?= (int) $stats['banned'] ?></span><?php endif; ?></span>
                            <small><?= e(t('admin.user_management_desc')) ?></small>
                        </a>
                    <?php endif; ?>
                    <?php if ($canClaimContributors): ?>
                        <a class="admin-action-tile<?= $activeSection === 'admin-claims' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-claims')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.claim_contributors')) ?></span>
                            <small><?= e(t('admin.claim_contributors_desc')) ?></small>
                        </a>
                    <?php endif; ?>
                    <?php if ($canManageBadges): ?>
                        <a class="admin-action-tile<?= $activeSection === 'admin-badges' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-badges')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.badges')) ?></span>
                            <small><?= e(t('admin.badges_desc')) ?></small>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasOwnerOnlyQuickActions || $canManageScoring): ?>
            <div class="admin-action-group admin-action-group-owner">
                <h3 class="admin-action-group-title"><?= e(t('admin.restricted')) ?></h3>
                <p class="admin-action-group-note"><?= e(t('admin.restricted_note')) ?></p>
                <div class="admin-quick-actions admin-quick-actions-owner">
                    <?php if ($canManageRolePermissions): ?>
                        <a class="admin-action-tile is-owner<?= $activeSection === 'admin-role-permissions' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-role-permissions')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.role_permissions')) ?></span>
                            <small><?= e(t('admin.role_permissions_desc')) ?></small>
                            <span class="admin-action-meta"><?= e(t('admin.restricted_meta')) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($canManageListVisibility): ?>
                        <a class="admin-action-tile is-owner<?= $activeSection === 'admin-list-visibility' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-list-visibility')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.list_visibility')) ?></span>
                            <small><?= e(t('admin.list_visibility_desc')) ?></small>
                            <span class="admin-action-meta"><?= e(t('admin.restricted_meta')) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($canManageScoring): ?>
                        <a class="admin-action-tile is-owner<?= $activeSection === 'admin-scoring' ? ' is-active' : '' ?>" href="<?= e(admin_section_url('admin-scoring')) ?>">
                            <span class="admin-action-title"><?= e(t('admin.change_score')) ?></span>
                            <small><?= e(t('admin.change_score_desc')) ?></small>
                            <span class="admin-action-meta"><?= e(t('admin.restricted_meta')) ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$hasGeneralQuickActions && !$hasOwnerOnlyQuickActions): ?>
            <div class="muted admin-sidebar-empty"><?= e(t('admin.empty_actions')) ?></div>
        <?php endif; ?>
    </aside>

    <div class="admin-content">
    <?php if ($activeSection === 'overview'): ?>
        <section class="panel fade">
            <div class="panel-head">
                <div>
                    <h1><?= e(t('admin.dashboard')) ?></h1>
                    <p><?= e(t('admin.dashboard_intro')) ?></p>
                </div>
            </div>

            <div class="detail-grid" style="grid-template-columns: repeat(5, 1fr); gap: 10px;">
                <div class="panel subtle"><h3><?= $stats['pending'] ?></h3><p><?= e(t('admin.pending')) ?></p></div>
                <div class="panel subtle"><h3><?= $stats['approved'] ?></h3><p><?= e(t('admin.approved')) ?></p></div>
                <div class="panel subtle"><h3><?= $stats['rejected'] ?></h3><p><?= e(t('admin.rejected')) ?></p></div>
                <div class="panel subtle"><h3><?= $stats['players'] ?></h3><p><?= e(t('admin.players')) ?></p></div>
                <div class="panel subtle"><h3><?= $stats['banned'] ?></h3><p><?= e(t('admin.banned')) ?></p></div>
            </div>
        </section>
    <?php endif; ?>

<?php if ($activeSection === 'admin-role-permissions'): ?>
<section class="panel fade admin-tool-section admin-role-permissions-section" id="admin-role-permissions">
    <div class="panel-head">
        <h2><?= e(t('admin.role_permissions')) ?></h2>
        <p><?= e(t('admin.role_permissions_intro')) ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(admin_section_url('admin-role-permissions')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_role_permissions">

        <div class="table-wrap">
            <table class="data-table role-permission-table">
                <thead>
                    <tr>
                        <th><?= e(t('common.permission')) ?></th>
                        <?php foreach ($editableStaffRoles as $staffRole): ?>
                            <th><?= e(role_label($staffRole)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($permissionDefinitions as $permissionKey => $permissionLabel): ?>
                        <tr>
                            <td class="role-permission-name"><?= e($permissionLabel) ?></td>
                            <?php foreach ($editableStaffRoles as $staffRole): ?>
                                <?php $isAllowed = !empty($rolePermissionMatrix[$staffRole][$permissionKey]); ?>
                                <td class="role-permission-cell">
                                    <input type="hidden" name="permissions[<?= e($staffRole) ?>][<?= e($permissionKey) ?>]" value="0">
                                    <input
                                        class="role-permission-checkbox"
                                        type="checkbox"
                                        name="permissions[<?= e($staffRole) ?>][<?= e($permissionKey) ?>]"
                                        value="1"
                                        aria-label="<?= e(role_label($staffRole) . ' - ' . $permissionLabel) ?>"
                                        <?= $isAllowed ? 'checked' : '' ?>
                                    >
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button class="button blue hover" type="submit"><?= e(t('admin.save_role_permissions')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-badges'): ?>
<section class="panel fade admin-tool-section admin-badges-section" id="admin-badges">
    <div class="panel-head">
        <h2><?= e(t('admin.badges')) ?></h2>
        <p><?= e(t('admin.badges_intro')) ?></p>
    </div>

    <div class="admin-badge-toolbox">
        <div class="admin-badge-card">
            <h3 class="admin-badge-card-title"><?= e(t('admin.create_badge')) ?></h3>
            <form class="admin-badge-form" method="post" action="<?= e(admin_section_url('admin-badges')) ?>" enctype="multipart/form-data">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_badge">

                <label class="field">
                    <span><?= e(t('admin.badge_label')) ?></span>
                    <input type="text" name="badge_name" maxlength="<?= (int) badge_name_max_length() ?>" placeholder="<?= e(t('admin.badge_label_placeholder')) ?>" required>
                </label>
                <label class="field">
                    <span><?= e(t('common.description')) ?></span>
                    <input type="text" name="badge_description" maxlength="<?= (int) badge_description_max_length() ?>" placeholder="<?= e(t('admin.badge_description_placeholder')) ?>">
                </label>
                <label class="field">
                    <span><?= e(t('admin.upload_badge_image')) ?></span>
                    <input type="file" name="badge_image_file" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp" required>
                </label>

                <button class="button blue hover" type="submit"><?= e(t('admin.create_badge')) ?></button>
            </form>
        </div>

        <div class="admin-badge-card">
            <h3 class="admin-badge-card-title"><?= e(t('admin.assign_to_player')) ?></h3>
            <form class="admin-badge-form" method="post" action="<?= e(admin_section_url('admin-badges')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="assign_badge">

                <label class="field">
                    <span><?= e(t('admin.badge')) ?></span>
                    <select name="badge_id" <?= $badgeList === [] ? 'disabled' : '' ?> required>
                        <?php if ($badgeList === []): ?>
                            <option value=""><?= e(t('admin.create_badge_first')) ?></option>
                        <?php endif; ?>
                        <?php foreach ($badgeList as $badge): ?>
                            <option value="<?= (int) $badge['id'] ?>"><?= e((string) $badge['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span><?= e(t('common.username')) ?></span>
                    <input type="text" name="badge_username" data-suggest-list="admin-badge-user-list" placeholder="<?= e(t('admin.exact_username')) ?>" autocomplete="off" required>
                </label>

                <div class="admin-badge-actions">
                    <button class="button blue hover" type="submit" name="badge_mode" value="assign" <?= $badgeList === [] ? 'disabled' : '' ?>><?= e(t('common.assign')) ?></button>
                    <button class="button ghost hover" type="submit" name="badge_mode" value="remove" <?= $badgeList === [] ? 'disabled' : '' ?>><?= e(t('common.remove')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <datalist id="admin-badge-user-list">
        <?php foreach ($claimUsers as $claimUser): ?>
            <option value="<?= e((string) ($claimUser['username'] ?? '')) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <div class="admin-badge-grid">
        <?php if ($badgeList === []): ?>
            <p class="muted"><?= e(t('admin.no_badges')) ?></p>
        <?php endif; ?>
        <?php foreach ($badgeList as $badge): ?>
            <?php $badgePreviewHtml = render_user_badges([$badge], 'admin-badge-card-preview'); ?>
            <article class="admin-badge-item">
                <div class="admin-badge-item-head">
                    <?= $badgePreviewHtml !== '' ? $badgePreviewHtml : '<span class="admin-badge-item-noimage muted">' . e(t('admin.no_image')) . '</span>' ?>
                    <div class="admin-badge-item-info">
                        <strong><?= e((string) $badge['name']) ?></strong>
                        <small><?= e((string) ($badge['description'] !== '' ? $badge['description'] : t('admin.no_description'))) ?></small>
                    </div>
                </div>

                <div class="admin-badge-item-meta">
                    <span class="badge"><?= e(t('admin.assigned_count', ['count' => (int) ($badge['assigned_count'] ?? 0)])) ?></span>
                </div>

                <details class="admin-badge-edit-details">
                    <summary class="button white hover small"><?= e(t('admin.edit_badge')) ?></summary>
                    <form class="admin-badge-edit-form" method="post" action="<?= e(admin_section_url('admin-badges')) ?>" enctype="multipart/form-data">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_badge">
                        <input type="hidden" name="badge_id" value="<?= (int) $badge['id'] ?>">

                        <label class="field">
                            <span><?= e(t('common.label')) ?></span>
                            <input type="text" name="badge_name" maxlength="<?= (int) badge_name_max_length() ?>" value="<?= e((string) $badge['name']) ?>" required>
                        </label>
                        <label class="field">
                            <span><?= e(t('common.description')) ?></span>
                            <input type="text" name="badge_description" maxlength="<?= (int) badge_description_max_length() ?>" value="<?= e((string) $badge['description']) ?>">
                        </label>
                        <label class="field">
                            <span><?= e(t('admin.replace_image')) ?></span>
                            <input type="file" name="badge_image_file" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp">
                        </label>

                        <button class="button blue hover small" type="submit"><?= e(t('admin.save_badge')) ?></button>
                    </form>
                </details>

                <form class="admin-badge-delete-form" method="post" action="<?= e(admin_section_url('admin-badges')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_badge">
                    <input type="hidden" name="badge_id" value="<?= (int) $badge['id'] ?>">
                    <button class="button danger hover small" type="submit" data-confirm="<?= e(t('admin.delete_badge_confirm')) ?>"><?= e(t('common.delete')) ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-list-visibility'): ?>
<section class="panel fade admin-tool-section" id="admin-list-visibility">
    <div class="panel-head">
        <h2><?= e(t('admin.list_visibility')) ?></h2>
        <p><?= e(t('admin.list_visibility_intro')) ?></p>
    </div>

    <form class="stack-form panel-narrow" method="post" action="<?= e(admin_section_url('admin-list-visibility')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_list_visibility">

        <label class="cb-container" style="text-align: left;">
            <input type="checkbox" name="show_extended_list" value="1" <?= $showExtendedList ? 'checked' : '' ?>>
            <span class="checkmark"></span>
            <?= e(t('admin.show_extended_list')) ?>
        </label>

        <label class="cb-container" style="text-align: left;">
            <input type="checkbox" name="show_legacy_list" value="1" <?= $showLegacyList ? 'checked' : '' ?>>
            <span class="checkmark"></span>
            <?= e(t('admin.show_legacy_list')) ?>
        </label>

        <label class="field">
            <span><?= e(t('admin.main_list_max_rank')) ?></span>
            <input
                type="number"
                name="main_list_limit"
                min="<?= e($listLimitMinInput) ?>"
                max="<?= e($listLimitMaxInput) ?>"
                step="1"
                value="<?= e((string) $mainListLimit) ?>"
                required
            >
        </label>

        <label class="field">
            <span><?= e(t('admin.extended_list_max_rank')) ?></span>
            <input
                type="number"
                name="extended_list_limit"
                min="<?= e($listLimitMinInput) ?>"
                max="<?= e($listLimitMaxInput) ?>"
                step="1"
                value="<?= e((string) $extendedListLimit) ?>"
                required
            >
        </label>

        <small class="muted" style="text-align: left;">
            <?= e(t('admin.list_visibility_help', [
                'min' => $listLimitMinInput,
                'max' => $listLimitMaxInput,
                'main' => (int) $mainListLimit,
                'extended' => $extendedListLimit > $mainListLimit ? '#' . (int) ($mainListLimit + 1) . '-#' . (int) $extendedListLimit : t('common.none'),
            ])) ?>
        </small>

        <button class="button blue hover" type="submit"><?= e(t('admin.save_list_settings')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-scoring'): ?>
<section class="panel fade admin-tool-section" id="admin-scoring">
    <div class="panel-head">
        <h2><?= e(t('admin.change_score')) ?></h2>
        <p><?= e(t('admin.scoring_intro')) ?></p>
    </div>

    <form class="stack-form panel-narrow" method="post" action="<?= e(admin_section_url('admin-scoring')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_scoring">

        <label class="field">
            <span><?= e(t('admin.top1_points')) ?></span>
            <input
                type="number"
                name="top1_points"
                min="<?= e($topOnePointsMinInput) ?>"
                max="<?= e($topOnePointsMaxInput) ?>"
                step="0.01"
                value="<?= e($topOnePointsInput) ?>"
                required
            >
        </label>
        <small class="muted" style="text-align: left;">
            <?= e(t('admin.scoring_help', [
                'min' => $topOnePointsMinInput,
                'max' => $topOnePointsMaxInput,
                'current' => number_format(demonlist_score(1, 100, 100), 2),
            ])) ?>
        </small>

        <button class="button blue hover" type="submit"><?= e(t('admin.save_scoring')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-level-info-rows'): ?>
<section class="panel fade admin-tool-section" id="admin-level-info-rows">
    <div class="panel-head">
        <h2><?= e(t('admin.level_info_rows')) ?></h2>
        <p><?= e(t('admin.level_info_rows_intro')) ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(admin_section_url('admin-level-info-rows')) ?>" data-level-info-builder>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_level_info_rows">

        <div class="admin-level-info-builder" data-level-info-rows>
            <?php foreach ($levelInfoRows as $row): ?>
                <?php
                $rowType = (string) ($row['type'] ?? 'field');
                $rowType = $rowType === 'custom' ? 'custom' : 'field';
                $isCustomRow = $rowType === 'custom';
                $rowField = (string) ($row['field'] ?? 'position');
                if (!array_key_exists($rowField, $levelInfoFieldDefinitions)) {
                    $rowField = 'position';
                }
                $rowKey = (string) ($row['key'] ?? '');
                $rowLabel = (string) ($row['label'] ?? '');
                $rowDefaultValue = (string) ($row['default_value'] ?? '');
                ?>
                <div class="admin-level-info-row" data-level-info-row>
                    <label class="field">
                        <span><?= e(t('common.type')) ?></span>
                        <select name="level_info_type[]" data-level-info-type>
                            <option value="field" <?= !$isCustomRow ? 'selected' : '' ?>><?= e(t('admin.built_in_field')) ?></option>
                            <option value="custom" <?= $isCustomRow ? 'selected' : '' ?>><?= e(t('admin.custom_row')) ?></option>
                        </select>
                    </label>
                    <label class="field admin-level-info-field-wrap" data-level-info-field-wrap <?= $isCustomRow ? 'hidden' : '' ?>>
                        <span><?= e(t('common.source')) ?></span>
                        <select name="level_info_field[]">
                            <?php foreach ($levelInfoFieldDefinitions as $field => $defaultLabel): ?>
                                <option value="<?= e($field) ?>" <?= $rowField === $field ? 'selected' : '' ?>><?= e($defaultLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <input type="hidden" name="level_info_custom_key[]" value="<?= e($rowKey) ?>" data-level-info-custom-key>
                    <label class="field">
                        <span><?= e(t('common.label')) ?></span>
                        <input type="text" name="level_info_label[]" value="<?= e($rowLabel) ?>" placeholder="<?= e($isCustomRow ? t('admin.custom_label') : t('admin.use_default_label')) ?>">
                    </label>
                    <label class="field admin-level-info-custom-wrap" data-level-info-custom-wrap <?= !$isCustomRow ? 'hidden' : '' ?>>
                        <span><?= e(t('admin.default_value')) ?></span>
                        <input type="text" name="level_info_default_value[]" value="<?= e($rowDefaultValue) ?>" placeholder="<?= e(t('admin.optional_fallback_value')) ?>">
                    </label>
                    <button class="button white hover admin-level-info-remove" type="button" data-level-info-remove><?= e(t('common.remove')) ?></button>
                </div>
            <?php endforeach; ?>
        </div>

        <template data-level-info-template>
            <div class="admin-level-info-row" data-level-info-row>
                <label class="field">
                    <span><?= e(t('common.type')) ?></span>
                    <select name="level_info_type[]" data-level-info-type>
                        <option value="field" selected><?= e(t('admin.built_in_field')) ?></option>
                        <option value="custom"><?= e(t('admin.custom_row')) ?></option>
                    </select>
                </label>
                <label class="field admin-level-info-field-wrap" data-level-info-field-wrap>
                    <span><?= e(t('common.source')) ?></span>
                    <select name="level_info_field[]">
                        <?php foreach ($levelInfoFieldDefinitions as $field => $defaultLabel): ?>
                            <option value="<?= e($field) ?>"><?= e($defaultLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <input type="hidden" name="level_info_custom_key[]" data-level-info-custom-key>
                <label class="field">
                    <span><?= e(t('common.label')) ?></span>
                    <input type="text" name="level_info_label[]" placeholder="<?= e(t('admin.use_default_label')) ?>">
                </label>
                <label class="field admin-level-info-custom-wrap" data-level-info-custom-wrap hidden>
                    <span><?= e(t('admin.default_value')) ?></span>
                    <input type="text" name="level_info_default_value[]" placeholder="<?= e(t('admin.optional_fallback_value')) ?>">
                </label>
                <button class="button white hover admin-level-info-remove" type="button" data-level-info-remove><?= e(t('common.remove')) ?></button>
            </div>
        </template>

        <div class="homepage-tool-actions admin-level-info-actions">
            <button class="button white hover" type="button" data-level-info-add><?= e(t('admin.add_row')) ?></button>
            <button class="button blue hover" type="submit" name="mode" value="save"><?= e(t('admin.save_rows')) ?></button>
            <button class="button white hover" type="submit" name="mode" value="restore"><?= e(t('admin.restore_defaults')) ?></button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-level-comments'): ?>
<section class="panel fade admin-tool-section" id="admin-level-comments">
    <div class="panel-head">
        <h2><?= e(t('admin.level_comments')) ?></h2>
        <p><?= e(t('admin.level_comments_intro')) ?></p>
    </div>

    <?php if ($canManageLevels): ?>
    <form class="stack-form panel-narrow" method="post" action="<?= e(admin_section_url('admin-level-comments')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_comment_settings">

        <label class="cb-container" style="text-align: left;">
            <input type="checkbox" name="comments_enabled" value="1" <?= $levelCommentsEnabled ? 'checked' : '' ?>>
            <span class="checkmark"></span>
            <?= e(t('admin.enable_all_comments')) ?>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.level_name_optional')) ?></span>
                <input type="text" name="comment_level_name" data-suggest-list="admin-demon-list" placeholder="<?= e(t('admin.type_level_name')) ?>" autocomplete="off">
            </label>
            <label class="field">
                <span><?= e(t('admin.level_comment_status')) ?></span>
                <select name="level_comment_status">
                    <option value="keep"><?= e(t('admin.no_level_change')) ?></option>
                    <option value="enabled"><?= e(t('admin.enable_on_level')) ?></option>
                    <option value="disabled"><?= e(t('admin.disable_on_level')) ?></option>
                </select>
            </label>
        </div>

        <small class="muted" style="text-align: left;">
            <?= e(t('admin.comments_status_help', [
                'status' => $levelCommentsEnabled ? t('common.enabled') : t('common.disabled'),
                'count' => (int) $levelCommentsDisabledCount,
            ])) ?>
        </small>

        <button class="button blue hover" type="submit"><?= e(t('admin.save_comment_settings')) ?></button>
    </form>
    <?php endif; ?>

    <?php if ($canModerateLevelComments): ?>
        <div class="admin-reported-comments">
            <div class="panel-head">
                <h3><?= e(t('admin.reported_comments')) ?></h3>
                <?php if ($reportedLevelCommentsError !== ''): ?>
                    <p><?= e(t('admin.reported_comments_load_failed')) ?></p>
                <?php else: ?>
                    <p><?= e(t_choice('admin.reported_comments_count_one', 'admin.reported_comments_count_many', count($reportedLevelComments))) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($reportedLevelCommentsError !== ''): ?>
                <div class="info-red"><?= e(t('admin.reported_comments_load_error', ['error' => $reportedLevelCommentsError])) ?></div>
            <?php elseif ($reportedLevelComments === []): ?>
                <p class="muted" style="text-align: left;"><?= e(t('admin.no_reported_comments')) ?></p>
            <?php endif; ?>

            <?php foreach ($reportedLevelComments as $reportedComment): ?>
                <?php
                $reportedCommentId = (int) ($reportedComment['id'] ?? 0);
                $reportedPosition = (int) ($reportedComment['demon_position'] ?? 0);
                $reportedCommentUrl = $reportedPosition > 0
                    ? base_url((string) $reportedPosition . '#comment-' . $reportedCommentId)
                    : base_url('index.php');
                $reportedAuthor = user_display_name_from_row($reportedComment);
                $reportedBody = trim((string) ($reportedComment['body'] ?? ''));
                $reportedSummary = trim((string) ($reportedComment['report_summary'] ?? ''));
                ?>
                <article class="moderation-card reported-comment-card">
                    <div class="moderation-head">
                        <strong>#<?= $reportedCommentId ?></strong>
                        <span class="badge error"><?= e(t_choice('admin.report_count_one', 'admin.report_count_many', (int) ($reportedComment['report_count'] ?? 0))) ?></span>
                        <span class="muted"><?= e(t('admin.latest_at', ['time' => date('Y-m-d H:i', strtotime((string) ($reportedComment['latest_reported_at'] ?? 'now')))])) ?></span>
                    </div>
                    <dl class="key-value compact">
                        <div><dt><?= e(t('common.level')) ?></dt><dd><a class="link" href="<?= e($reportedCommentUrl) ?>">#<?= $reportedPosition ?> <?= e((string) ($reportedComment['demon_name'] ?? t('common.unknown'))) ?></a></dd></div>
                        <div><dt><?= e(t('common.author')) ?></dt><dd><?= e($reportedAuthor !== '' ? $reportedAuthor : (string) ($reportedComment['username'] ?? t('common.unknown'))) ?></dd></div>
                        <div><dt><?= e(t('common.comment')) ?></dt><dd><?= e($reportedBody !== '' ? $reportedBody : '-') ?></dd></div>
                        <div><dt><?= e(t('common.reports')) ?></dt><dd><?= nl2br(e($reportedSummary !== '' ? $reportedSummary : '-')) ?></dd></div>
                    </dl>
                    <form method="post" action="<?= e(admin_section_url('admin-level-comments')) ?>">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_reported_level_comment">
                        <input type="hidden" name="comment_id" value="<?= $reportedCommentId ?>">
                        <button class="button danger hover small" type="submit" data-confirm="<?= e(t('admin.delete_reported_comment_confirm')) ?>"><?= e(t('admin.delete_comment')) ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-claims'): ?>
<section class="panel fade admin-tool-section" id="admin-claims">
    <div class="panel-head">
        <h2><?= e(t('admin.claim_contributors')) ?></h2>
        <p><?= e(t('admin.claims_intro')) ?></p>
    </div>

    <?php if (!$claimColumnsReady): ?>
        <div class="info-red"><?= e(t('admin.claim_columns_missing')) ?></div>
    <?php endif; ?>

    <form class="stack-form panel-narrow" method="post" action="<?= e(admin_section_url('admin-claims')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="claim_contributor">

        <label class="field">
            <span><?= e(t('admin.level_name')) ?></span>
            <input type="text" name="demon_name" data-suggest-list="admin-demon-list" placeholder="<?= e(t('admin.type_level_name')) ?>" autocomplete="off" required>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                    <span><?= e(t('common.role')) ?></span>
                <select name="claim_role" required>
                    <option value="publisher"><?= e(t('admin.publisher')) ?></option>
                    <option value="verifier"><?= e(t('admin.verifier')) ?></option>
                </select>
            </label>
            <label class="field">
                <span><?= e(t('admin.account_username_optional')) ?></span>
                <input type="text" name="claim_username" data-suggest-list="admin-user-claim-list" placeholder="<?= e(t('admin.clear_claim_placeholder')) ?>" autocomplete="off">
            </label>
        </div>

        <button class="button blue hover" type="submit"><?= e(t('admin.save_claim')) ?></button>
    </form>

    <datalist id="admin-user-claim-list">
        <?php foreach ($claimUsers as $claimUser): ?>
            <option value="<?= e((string) ($claimUser['username'] ?? '')) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</section>
<?php endif; ?>

<?php if ($needsDemonList): ?>
    <datalist id="admin-demon-list">
        <?php foreach ($editableDemons as $demon): ?>
            <option value="<?= e((string) $demon['name']) ?>" label="#<?= (int) $demon['position'] ?> (Req <?= (int) $demon['requirement'] ?>%)"></option>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<?php if ($activeSection === 'admin-add-level'): ?>
<section class="panel fade admin-tool-section" id="admin-add-level">
    <div class="panel-head">
        <h2><?= e(t('admin.add_level')) ?></h2>
        <p><?= e(t('admin.add_level_intro')) ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(admin_section_url('admin-add-level')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_level">

        <div class="detail-grid" style="grid-template-columns: 2fr 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.level_name')) ?></span>
                <input type="text" name="name" required>
            </label>
            <label class="field">
                <span><?= e(t('admin.position_optional')) ?></span>
                <input type="number" min="1" name="position" placeholder="<?= e(t('admin.auto_end')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.requirement_percent')) ?></span>
                <input type="number" min="1" max="100" name="requirement" value="100" required>
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.creators')) ?></span>
                <input type="text" name="creators" placeholder="e.g. ABC, XYZ" required>
            </label>
            <label class="field">
                <span><?= e(t('admin.publisher')) ?></span>
                <input type="text" name="publisher" required>
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.verifier')) ?></span>
                <input type="text" name="verifier">
            </label>
            <label class="field">
                <span><?= e(t('admin.difficulty')) ?></span>
                <input type="text" name="difficulty" value="Extreme Demon" required>
            </label>
        </div>

        <label class="field">
            <span><?= e(t('admin.verification_video_url')) ?></span>
            <input type="url" name="video_url" required>
        </label>

        <label class="field">
            <span><?= e(t('admin.thumbnail_url_optional')) ?></span>
            <input type="url" name="thumbnail_url">
        </label>

        <label class="field">
            <span><?= e(t('admin.level_description_optional')) ?></span>
            <textarea name="description" maxlength="1000" placeholder="<?= e(t('admin.short_description_placeholder')) ?>"></textarea>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.level_id_optional')) ?></span>
                <input type="text" name="level_id" placeholder="e.g. 12345678">
            </label>
            <label class="field">
                <span><?= e(t('admin.level_length_optional')) ?></span>
                <input type="text" name="level_length" placeholder="e.g. Long">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.song_optional')) ?></span>
                <input type="text" name="song" placeholder="e.g. Creo - Sphere">
            </label>
            <label class="field">
                <span><?= e(t('admin.object_count_optional')) ?></span>
                <input type="number" min="0" name="object_count" placeholder="e.g. 178945">
            </label>
        </div>

        <?php if ($levelInfoCustomRows !== []): ?>
            <div class="admin-custom-level-info">
                <h3><?= e(t('admin.custom_level_info')) ?></h3>
                <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
                    <?php foreach ($levelInfoCustomRows as $customRow): ?>
                        <label class="field">
                            <span><?= e((string) $customRow['label']) ?></span>
                            <input
                                type="text"
                                name="custom_level_info[<?= e((string) $customRow['key']) ?>]"
                                    placeholder="<?= e((string) ($customRow['default_value'] !== '' ? $customRow['default_value'] : t('admin.optional_value'))) ?>"
                            >
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <label class="cb-container" style="text-align: left; margin-top: 6px;">
            <input type="checkbox" name="legacy" value="1">
            <span class="checkmark"></span>
            <?= e(t('admin.mark_legacy')) ?>
        </label>

        <label class="cb-container" style="text-align: left; margin-top: 6px;">
            <input type="checkbox" name="comments_disabled" value="1">
            <span class="checkmark"></span>
            <?= e(t('admin.disable_comments_for_level')) ?>
        </label>

        <button class="button blue hover" type="submit"><?= e(t('admin.add_level')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-edit-level'): ?>
<section class="panel fade admin-tool-section" id="admin-edit-level">
    <div class="panel-head">
        <h2><?= e(t('admin.edit_level')) ?></h2>
        <p><?= e(t('admin.edit_level_intro')) ?></p>
    </div>

    <form class="stack-form" method="post" action="<?= e(admin_section_url('admin-edit-level')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="edit_level">

        <label class="field">
            <span><?= e(t('admin.level_to_edit')) ?></span>
            <input type="text" name="demon_name" data-suggest-list="admin-demon-list" placeholder="<?= e(t('admin.type_level_name')) ?>" autocomplete="off" required>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.new_name_optional')) ?></span>
                <input type="text" name="name" placeholder="<?= e(t('admin.keep_current_long')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.difficulty_optional')) ?></span>
                <input type="text" name="difficulty" placeholder="<?= e(t('admin.keep_current_long')) ?>">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.requirement_optional')) ?></span>
                <input type="number" min="1" max="100" name="requirement" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.creators_optional')) ?></span>
                <input type="text" name="creators" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.publisher_optional')) ?></span>
                <input type="text" name="publisher" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.verifier_optional')) ?></span>
                <input type="text" name="verifier" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.verification_video_url_optional')) ?></span>
                <input type="url" name="video_url" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.thumbnail_url_optional')) ?></span>
                <input type="url" name="thumbnail_url" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
        </div>

        <label class="field">
            <span><?= e(t('admin.level_description_optional')) ?></span>
            <textarea name="description" maxlength="1000" placeholder="<?= e(t('admin.keep_current_long')) ?>"></textarea>
        </label>

        <label class="cb-container" style="text-align: left; margin-top: 6px;">
            <input type="checkbox" name="clear_description" value="1">
            <span class="checkmark"></span>
            <?= e(t('admin.clear_current_description')) ?>
        </label>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.level_id_optional')) ?></span>
                <input type="text" name="level_id" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.level_length_optional')) ?></span>
                <input type="text" name="level_length" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
        </div>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.song_optional')) ?></span>
                <input type="text" name="song" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.object_count_optional')) ?></span>
                <input type="number" min="0" name="object_count" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
        </div>

        <?php if ($levelInfoCustomRows !== []): ?>
            <div class="admin-custom-level-info">
                <h3><?= e(t('admin.custom_level_info')) ?></h3>
                <div class="admin-custom-level-info-grid">
                    <?php foreach ($levelInfoCustomRows as $customRow): ?>
                        <div class="admin-custom-level-info-field">
                            <label class="field">
                                <span><?= e((string) $customRow['label']) ?></span>
                                <input
                                    type="text"
                                    name="custom_level_info[<?= e((string) $customRow['key']) ?>]"
                                    placeholder="<?= e(t('admin.keep_current_default')) ?>"
                                >
                            </label>
                            <label class="cb-container admin-custom-level-info-clear">
                                <input type="checkbox" name="custom_level_info_clear[<?= e((string) $customRow['key']) ?>]" value="1">
                                <span class="checkmark"></span>
                                <?= e(t('common.clear')) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
            <label class="field">
                <span><?= e(t('admin.legacy_status')) ?></span>
                <select name="legacy_status">
                    <option value="keep"><?= e(t('admin.keep_current')) ?></option>
                    <option value="normal"><?= e(t('admin.set_current_list')) ?></option>
                    <option value="legacy"><?= e(t('admin.set_legacy')) ?></option>
                </select>
            </label>
            <label class="field">
                <span><?= e(t('admin.comment_status')) ?></span>
                <select name="comment_status">
                    <option value="keep"><?= e(t('admin.keep_current')) ?></option>
                    <option value="enabled"><?= e(t('admin.enable_comments')) ?></option>
                    <option value="disabled"><?= e(t('admin.disable_comments')) ?></option>
                </select>
            </label>
            <label class="field">
                <span><?= e(t('admin.new_position_optional')) ?></span>
                <input type="number" min="1" max="<?= $maxPosition ?>" name="new_position" placeholder="<?= e(t('admin.keep_current')) ?>">
            </label>
            <label class="field">
                <span><?= e(t('admin.move_note_optional')) ?></span>
                <input type="text" name="move_note" placeholder="<?= e(t('admin.move_note_placeholder')) ?>">
            </label>
        </div>

        <button class="button blue hover" type="submit"><?= e(t('admin.save_level_changes')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-delete-level'): ?>
<section class="panel fade admin-tool-section" id="admin-delete-level">
    <div class="panel-head">
        <h2><?= e(t('admin.delete_level')) ?></h2>
        <p><?= e(t('admin.delete_level_intro')) ?></p>
    </div>

    <form class="stack-form panel-narrow" method="post" action="<?= e(admin_section_url('admin-delete-level')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_level">

        <label class="field">
            <span><?= e(t('admin.level_to_delete')) ?></span>
            <input type="text" name="demon_name" data-suggest-list="admin-demon-list" placeholder="<?= e(t('admin.type_level_name')) ?>" autocomplete="off" required>
        </label>

        <label class="field">
            <span><?= e(t('admin.confirm_level_name')) ?></span>
            <input type="text" name="confirm_name" placeholder="<?= e(t('admin.confirm_level_placeholder')) ?>" autocomplete="off" required>
        </label>

        <small class="muted" style="text-align: left;">
            <?= e(t('admin.delete_level_warning')) ?>
        </small>

        <button class="button red hover" type="submit"><?= e(t('admin.delete_level')) ?></button>
    </form>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-user-management'): ?>
<section class="panel fade admin-tool-section admin-list-section" id="admin-user-management">
    <div class="panel-head">
        <h2><?= e(t('admin.user_management')) ?></h2>
        <p>
            <?= e(t('admin.user_management_intro')) ?>
            <?php if (!$canManageUserRoles): ?>
                <?= e(t('admin.user_management_no_role_access')) ?>
            <?php endif; ?>
        </p>
    </div>

    <form class="admin-user-toolbar" method="get" action="<?= e(base_url('admin.php')) ?>">
        <input type="hidden" name="section" value="admin-user-management">
        <div class="admin-user-search-grid">
            <label class="field">
                <span><?= e(t('admin.search_username')) ?></span>
                <input id="admin-user-search" type="text" name="users_q" value="<?= e($usersQuery) ?>" placeholder="<?= e(t('admin.type_username')) ?>">
            </label>
            <div class="admin-user-search-actions">
                <button class="button white hover" type="submit"><?= e(t('common.search')) ?></button>
                <?php if ($usersQuery !== ''): ?>
                    <a class="button ghost hover" href="<?= e(admin_section_url('admin-user-management')) ?>"><?= e(t('common.clear')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    <div class="table-wrap">
        <table class="data-table admin-user-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= e(t('common.user')) ?></th>
                    <th><?= e(t('common.email')) ?></th>
                    <th><?= e(t('common.role')) ?></th>
                    <th><?= e(t('common.banned')) ?></th>
                    <th><?= e(t('common.comments')) ?></th>
                    <th><?= e(t('common.points')) ?></th>
                    <th><?= e(t('common.bonus')) ?></th>
                    <th><?= e(t('common.joined')) ?></th>
                    <th><?= e(t('admin.adjust')) ?></th>
                </tr>
            </thead>
            <tbody id="admin-user-table-body">
                <?php if ($usersQuery === ''): ?>
                    <tr><td colspan="10" class="muted"><?= e(t('admin.load_users_hint')) ?></td></tr>
                <?php elseif ($users === []): ?>
                    <tr><td colspan="10" class="muted"><?= e(t('admin.no_users_found', ['query' => $usersQuery])) ?></td></tr>
                <?php endif; ?>

                <?php foreach ($users as $member): ?>
                    <?php
                    $memberRole = normalize_user_role((string) ($member['role'] ?? 'player'));
                    $memberIsOwner = $memberRole === 'owner';
                    $isLastOwner = $memberIsOwner && $stats['owners'] <= 1;
                    $memberRoleLabel = role_label($memberRole);
                    $isCurrentUser = current_user_id() !== null && (int) $member['id'] === (int) current_user_id();
                    $isBanned = (int) ($member['is_banned'] ?? 0) === 1;
                    $commentsDisabledForUser = (int) ($member['comments_disabled'] ?? 0) === 1;
                    $countryCode = normalize_country_code((string) ($member['country_code'] ?? ''));
                    $countryText = country_flag_html($countryCode);
                    ?>
                    <tr data-user-row data-search-value="<?= e(strtolower((string) $member['username'] . ' ' . (string) ($member['email'] ?? '') . ' ' . (string) $member['role'] . ' ' . ($isBanned ? 'banned' : 'active') . ' ' . ($commentsDisabledForUser ? 'comments disabled' : 'comments enabled'))) ?>">
                        <td>#<?= (int) $member['id'] ?></td>
                        <td>
                            <div class="admin-user-identity">
                                <?php if ($countryText !== ''): ?>
                                    <span class="admin-user-country"><?= $countryText ?></span>
                                <?php endif; ?>
                                <b title="<?= e((string) $member['username']) ?>"><?= e((string) $member['username']) ?></b>
                            </div>
                        </td>
                        <td><?= e((string) ($member['email'] ?: '-')) ?></td>
                        <td><span class="badge <?= $memberIsOwner ? 'approved' : '' ?>"><?= e(strtoupper($memberRoleLabel)) ?></span></td>
                        <td><span class="badge <?= $isBanned ? 'error' : 'success' ?>"><?= e($isBanned ? t('common.banned') : t('common.active')) ?></span></td>
                        <td><span class="badge <?= $commentsDisabledForUser ? 'error' : 'success' ?>"><?= e($commentsDisabledForUser ? t('common.disabled') : t('common.enabled')) ?></span></td>
                        <td><?= e(number_format((float) ($member['points'] ?? 0.0), 2)) ?></td>
                        <td><?= e(number_format((float) ($member['bonus_points'] ?? 0.0), 2)) ?></td>
                        <td><?= e(date('Y-m-d', strtotime((string) $member['created_at']))) ?></td>
                        <td class="admin-user-action-cell">
                            <form class="admin-user-edit-form" method="post" action="<?= e(admin_section_url('admin-user-management', $usersQuery !== '' ? ['users_q' => $usersQuery] : [])) ?>">
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                <input type="hidden" name="users_q" value="<?= e($usersQuery) ?>">

                                <div class="admin-user-edit-controls">
                                    <label class="admin-user-edit-field">
                                        <span><?= e(t('common.role')) ?></span>
                                        <?php if ($canManageUserRoles): ?>
                                            <select name="role">
                                                <option value="owner" <?= $memberRole === 'owner' ? 'selected' : '' ?>>OWNER</option>
                                                <option value="list_editor" <?= $memberRole === 'list_editor' ? 'selected' : '' ?> <?= $isLastOwner ? 'disabled' : '' ?>>LIST EDITOR</option>
                                                <option value="list_helper" <?= $memberRole === 'list_helper' ? 'selected' : '' ?> <?= $isLastOwner ? 'disabled' : '' ?>>LIST HELPER</option>
                                                <option value="player" <?= $memberRole === 'player' ? 'selected' : '' ?> <?= $isLastOwner ? 'disabled' : '' ?>>PLAYER</option>
                                            </select>
                                        <?php else: ?>
                                            <input type="hidden" name="role" value="<?= e($memberRole) ?>">
                                            <input type="text" value="<?= e(strtoupper($memberRoleLabel)) ?>" readonly>
                                        <?php endif; ?>
                                    </label>
                                    <label class="admin-user-edit-field">
                                        <span><?= e(t('common.banned')) ?></span>
                                        <select name="is_banned">
                                            <option value="0" <?= !$isBanned ? 'selected' : '' ?>><?= e(t('common.no')) ?></option>
                                            <option value="1" <?= $isBanned ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option>
                                        </select>
                                    </label>
                                    <label class="admin-user-edit-field">
                                        <span><?= e(t('common.comments')) ?></span>
                                        <select name="comments_disabled">
                                            <option value="0" <?= !$commentsDisabledForUser ? 'selected' : '' ?>><?= e(t('common.enabled')) ?></option>
                                            <option value="1" <?= $commentsDisabledForUser ? 'selected' : '' ?>><?= e(t('common.disabled')) ?></option>
                                        </select>
                                    </label>
                                    <label class="admin-user-edit-field">
                                        <span><?= e(t('admin.bonus_adjust')) ?></span>
                                        <input type="number" name="bonus_delta" step="0.01" value="0" placeholder="+10 or -5">
                                    </label>
                                </div>

                                <button class="button blue hover small" type="submit"><?= e(t('common.save')) ?></button>
                            </form>
                            <?php if ($canResetPasswords): ?>
                                <form class="admin-user-reset-form" method="post" action="<?= e(admin_section_url('admin-user-management', $usersQuery !== '' ? ['users_q' => $usersQuery] : [])) ?>" style="display: inline;">
                                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                    <input type="hidden" name="users_q" value="<?= e($usersQuery) ?>">
                                    <button class="button orange hover small" type="submit" title="<?= e(t('admin.reset_password_title')) ?>"><?= e(t('admin.reset_password')) ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isCurrentUser): ?>
                                <span class="muted admin-user-note"><?= e(t('common.you')) ?></span>
                            <?php endif; ?>
                            <?php if ($isLastOwner): ?>
                                <span class="muted admin-user-note"><?= e(t('admin.last_owner_note')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-pending-submissions'): ?>
<section class="panel fade admin-tool-section admin-list-section" id="admin-pending-submissions">
    <div class="panel-head">
        <h2><?= e(t('admin.pending_submissions')) ?></h2>
    </div>

    <?php if ($pending === []): ?>
        <p class="muted"><?= e(t('admin.no_pending_submissions')) ?></p>
    <?php endif; ?>

    <?php foreach ($pending as $item): ?>
        <article class="moderation-card">
            <div class="moderation-head">
                <strong>#<?= (int) $item['id'] ?></strong>
                <span class="badge"><?= e(strtoupper((string) $item['type'])) ?></span>
                <span class="muted"><?= e(t('admin.submitted_at', ['time' => date('Y-m-d H:i', strtotime((string) $item['created_at']))])) ?></span>
            </div>

            <dl class="key-value compact">
                <div><dt><?= e(t('common.submitter')) ?></dt><dd><?= e((string) ($item['submitter_username'] ?: $item['player'] ?: t('common.unknown'))) ?></dd></div>
                <div><dt><?= e(t('common.demon')) ?></dt><dd><?= e((string) $item['demon_name']) ?></dd></div>
                <div><dt><?= e(t('common.progress')) ?></dt><dd><?= $item['progress'] !== null ? (int) $item['progress'] . '%' : '-' ?></dd></div>
                <div><dt><?= e(t('common.platform')) ?></dt><dd><?= e((string) ($item['platform'] ?: '-')) ?></dd></div>
                <div><dt><?= e(t('admin.refresh')) ?></dt><dd><?= $item['refresh_rate'] !== null ? (int) $item['refresh_rate'] . 'Hz' : '-' ?></dd></div>
                <div><dt><?= e(t('common.proof')) ?></dt><dd><a class="link" target="_blank" rel="noreferrer" href="<?= e((string) ($item['video_url'] ?: '#')) ?>"><?= e(t('common.open')) ?></a></dd></div>
                <div><dt><?= e(t('common.raw_footage')) ?></dt><dd><?= !empty($item['raw_footage_url']) ? '<a class="link" target="_blank" rel="noreferrer" href="' . e((string) $item['raw_footage_url']) . '">' . e(t('common.open')) . '</a>' : '-' ?></dd></div>
            </dl>

            <?php if (!empty($item['notes'])): ?>
                <p><strong><?= e(t('common.notes')) ?>:</strong> <?= e((string) $item['notes']) ?></p>
            <?php endif; ?>

            <form class="moderation-actions" method="post" action="<?= e(admin_section_url('admin-pending-submissions')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="submission_id" value="<?= (int) $item['id'] ?>">
                <label class="field">
                    <span><?= e(t('admin.review_note')) ?></span>
                    <input type="text" name="review_note" placeholder="<?= e(t('admin.optional_note')) ?>">
                </label>
                <button class="button blue hover small" type="submit" name="decision" value="approved" data-confirm="<?= e(t('admin.approve_confirm')) ?>"><?= e(t('common.approve')) ?></button>
                <button class="button red hover small" type="submit" name="decision" value="rejected" data-confirm="<?= e(t('admin.reject_confirm')) ?>"><?= e(t('common.reject')) ?></button>
            </form>
        </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($activeSection === 'admin-reviewed-submissions'): ?>
<section class="panel fade admin-tool-section admin-list-section" id="admin-reviewed-submissions">
    <div class="panel-head">
        <h2><?= e(t('admin.recently_reviewed')) ?></h2>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= e(t('common.type')) ?></th>
                    <th><?= e(t('common.demon')) ?></th>
                    <th><?= e(t('common.submitter')) ?></th>
                    <th><?= e(t('common.status')) ?></th>
                    <th><?= e(t('common.reviewed_at')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($reviewed === []): ?>
                    <tr><td colspan="6" class="muted"><?= e(t('admin.no_reviewed_submissions')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($reviewed as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['type']) ?></td>
                        <td><?= e((string) $item['demon_name']) ?></td>
                        <td><?= e((string) ($item['submitter_username'] ?: $item['player'] ?: '-')) ?></td>
                        <td><span class="badge <?= $item['status'] === 'approved' ? 'success' : 'error' ?>"><?= e(status_label((string) $item['status'])) ?></span></td>
                        <td><?= e((string) ($item['reviewed_at'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
    </div>
</div>
<?php render_footer(); ?>








