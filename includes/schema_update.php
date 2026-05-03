<?php
declare(strict_types=1);

function schema_col_exists(PDO $pdo, string $table, string $column): bool
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

function schema_idx_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table
           AND index_name = :idx"
    );
    $stmt->execute([
        ':table' => $table,
        ':idx' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = :table"
    );
    $stmt->execute([
        ':table' => $table,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function schema_fk_exists(PDO $pdo, string $table, string $constraint): bool
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

function schema_users_role_enum_ready(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        "SELECT column_type
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'users'
           AND column_name = 'role'
         LIMIT 1"
    );
    $stmt->execute();

    $columnType = strtolower((string) ($stmt->fetchColumn() ?: ''));
    if ($columnType === '') {
        return false;
    }

    foreach (["'player'", "'list_helper'", "'list_editor'", "'owner'"] as $expectedValue) {
        if (!str_contains($columnType, $expectedValue)) {
            return false;
        }
    }

    return true;
}

function schema_apply_users_role_enum(PDO $pdo): void
{
    $pdo->exec("UPDATE users SET role = 'owner' WHERE role = 'admin'");
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('player', 'list_helper', 'list_editor', 'owner') NOT NULL DEFAULT 'player'");
}

function schema_needs_update(PDO $pdo): bool
{
    if (!schema_col_exists($pdo, 'users', 'youtube_channel')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'points')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'is_banned')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'bonus_points')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_is_banned')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_points')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_bonus_points')) {
        return true;
    }
    if (!schema_users_role_enum_ready($pdo)) {
        return true;
    }
    if (schema_col_exists($pdo, 'users', 'display_name')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'demons', 'creator')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'demons', 'creator_more')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'demons', 'publisher_user_id')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'demons', 'verifier_user_id')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'demons', 'idx_demons_publisher_user_id')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'demons', 'idx_demons_verifier_user_id')) {
        return true;
    }
    if (!schema_fk_exists($pdo, 'demons', 'fk_demons_publisher_user')) {
        return true;
    }
    if (!schema_fk_exists($pdo, 'demons', 'fk_demons_verifier_user')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'app_settings')) {
        return true;
    }

    return false;
}

function run_schema_update(PDO $pdo): array
{
    $logs = [];

    if (!schema_col_exists($pdo, 'users', 'youtube_channel')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN youtube_channel VARCHAR(255) NULL AFTER country_code');
        $logs[] = '[OK] Added users.youtube_channel';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN youtube_channel VARCHAR(255) NULL');

    if (!schema_col_exists($pdo, 'users', 'points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER role');
        $logs[] = '[OK] Added users.points';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN points DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    if (!schema_col_exists($pdo, 'users', 'is_banned')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER role');
        $logs[] = '[OK] Added users.is_banned';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0');

    if (!schema_col_exists($pdo, 'users', 'bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER points');
        $logs[] = '[OK] Added users.bonus_points';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    if (!schema_idx_exists($pdo, 'users', 'idx_users_is_banned')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_is_banned (is_banned)');
        $logs[] = '[OK] Added idx_users_is_banned';
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_points')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_points (points)');
        $logs[] = '[OK] Added idx_users_points';
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_bonus_points (bonus_points)');
        $logs[] = '[OK] Added idx_users_bonus_points';
    }

    if (!schema_users_role_enum_ready($pdo)) {
        schema_apply_users_role_enum($pdo);
        $logs[] = '[OK] Updated users.role enum values';
    }

    if (schema_col_exists($pdo, 'users', 'display_name')) {
        $pdo->exec('ALTER TABLE users DROP COLUMN display_name');
        $logs[] = '[OK] Removed users.display_name';
    }

    if (!schema_col_exists($pdo, 'demons', 'creator')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN creator VARCHAR(160) NULL AFTER requirement');
        $logs[] = '[OK] Added demons.creator';
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN creator VARCHAR(160) NULL');

    if (!schema_col_exists($pdo, 'demons', 'creator_more')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN creator_more VARCHAR(512) NULL AFTER creator');
        $logs[] = '[OK] Added demons.creator_more';
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN creator_more VARCHAR(512) NULL');

    if (!schema_col_exists($pdo, 'demons', 'publisher_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN publisher_user_id INT UNSIGNED NULL AFTER publisher');
        $logs[] = '[OK] Added demons.publisher_user_id';
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN publisher_user_id INT UNSIGNED NULL');

    if (!schema_col_exists($pdo, 'demons', 'verifier_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN verifier_user_id INT UNSIGNED NULL AFTER verifier');
        $logs[] = '[OK] Added demons.verifier_user_id';
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN verifier_user_id INT UNSIGNED NULL');

    if (!schema_idx_exists($pdo, 'demons', 'idx_demons_publisher_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD INDEX idx_demons_publisher_user_id (publisher_user_id)');
        $logs[] = '[OK] Added idx_demons_publisher_user_id';
    }
    if (!schema_idx_exists($pdo, 'demons', 'idx_demons_verifier_user_id')) {
        $pdo->exec('ALTER TABLE demons ADD INDEX idx_demons_verifier_user_id (verifier_user_id)');
        $logs[] = '[OK] Added idx_demons_verifier_user_id';
    }

    if (!schema_table_exists($pdo, 'app_settings')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(80) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added app_settings table';
    }

    $seededListSettings = (int) $pdo->exec(
        "INSERT IGNORE INTO app_settings (setting_key, setting_value)
         VALUES
            ('list.show_extended', '1'),
            ('list.show_legacy', '1'),
            ('list.main_max_rank', '75'),
            ('list.extended_max_rank', '150')"
    );
    $logs[] = '[OK] Ensured default list settings (Main 1-75, Extended 76-150, Legacy remaining): '
        . $seededListSettings
        . ' inserted key(s)';

    $publisherBackfilled = $pdo->exec(
        "UPDATE demons d
         INNER JOIN users u ON LOWER(u.username) = LOWER(TRIM(d.publisher))
         SET d.publisher_user_id = u.id
         WHERE d.publisher_user_id IS NULL
           AND d.publisher IS NOT NULL
           AND TRIM(d.publisher) <> ''"
    );
    $logs[] = '[OK] Backfilled demons.publisher_user_id: ' . (int) $publisherBackfilled . ' row(s)';

    $verifierBackfilled = $pdo->exec(
        "UPDATE demons d
         INNER JOIN users u ON LOWER(u.username) = LOWER(TRIM(d.verifier))
         SET d.verifier_user_id = u.id
         WHERE d.verifier_user_id IS NULL
           AND d.verifier IS NOT NULL
           AND TRIM(d.verifier) <> ''"
    );
    $logs[] = '[OK] Backfilled demons.verifier_user_id: ' . (int) $verifierBackfilled . ' row(s)';

    $sanitizedPublisher = $pdo->exec(
        "UPDATE demons d
         LEFT JOIN users u ON u.id = d.publisher_user_id
         SET d.publisher_user_id = NULL
         WHERE d.publisher_user_id IS NOT NULL
           AND u.id IS NULL"
    );
    $logs[] = '[OK] Sanitized invalid publisher_user_id: ' . (int) $sanitizedPublisher . ' row(s)';

    $sanitizedVerifier = $pdo->exec(
        "UPDATE demons d
         LEFT JOIN users u ON u.id = d.verifier_user_id
         SET d.verifier_user_id = NULL
         WHERE d.verifier_user_id IS NOT NULL
           AND u.id IS NULL"
    );
    $logs[] = '[OK] Sanitized invalid verifier_user_id: ' . (int) $sanitizedVerifier . ' row(s)';

    if (!schema_fk_exists($pdo, 'demons', 'fk_demons_publisher_user')) {
        $pdo->exec('ALTER TABLE demons
                    ADD CONSTRAINT fk_demons_publisher_user
                    FOREIGN KEY (publisher_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL');
        $logs[] = '[OK] Added fk_demons_publisher_user';
    }

    if (!schema_fk_exists($pdo, 'demons', 'fk_demons_verifier_user')) {
        $pdo->exec('ALTER TABLE demons
                    ADD CONSTRAINT fk_demons_verifier_user
                    FOREIGN KEY (verifier_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL');
        $logs[] = '[OK] Added fk_demons_verifier_user';
    }

    $backfilledCreator = $pdo->exec(
        "UPDATE demons
         SET creator = publisher
         WHERE (creator IS NULL OR TRIM(creator) = '')
           AND publisher IS NOT NULL
           AND TRIM(publisher) <> ''"
    );
    $logs[] = '[OK] Backfilled demons.creator: ' . (int) $backfilledCreator . ' row(s)';

    $logs[] = '[DONE] Schema update completed.';

    return $logs;
}

function schema_config_path(): ?string
{
    $path = $GLOBALS['app_config_path'] ?? null;
    if (!is_string($path) || $path === '' || !is_file($path)) {
        return null;
    }

    return $path;
}

function schema_set_updated_flag(int $value): bool
{
    $value = $value === 1 ? 1 : 0;
    $GLOBALS['app_config']['app']['updated'] = $value;

    $path = schema_config_path();
    if ($path === null || !is_readable($path) || !is_writable($path)) {
        return false;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $replacement = "'updated' => {$value}";
    $updatedRaw = $raw;

    if (preg_match("/'updated'\\s*=>\\s*[01]\\s*,/i", $updatedRaw) === 1) {
        $updatedRaw = (string) preg_replace(
            "/'updated'\\s*=>\\s*[01]\\s*,/i",
            $replacement . ',',
            $updatedRaw,
            1
        );
    } else {
        $count = 0;
        $updatedRaw = (string) preg_replace(
            "/('debug'\\s*=>\\s*(?:true|false)\\s*,(?:\\s*\\/\\/[^\\r\\n]*)?\\r?\\n)/i",
            "$1{$replacement},\n",
            $updatedRaw,
            1,
            $count
        );

        if ($count !== 1) {
            $updatedRaw = (string) preg_replace(
                "/('app'\\s*=>\\s*\\[\\s*\\r?\\n)/i",
                "$1{$replacement},\n",
                $raw,
                1,
                $count
            );

            if ($count !== 1) {
                return false;
            }
        }
    }

    if ($updatedRaw === $raw) {
        return true;
    }

    return file_put_contents($path, $updatedRaw, LOCK_EX) !== false;
}

function ensure_schema_updated_on_bootstrap(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (PHP_SAPI === 'cli') {
        return;
    }

    $scriptName = strtolower((string) basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($scriptName === 'update_db_schema.php') {
        return;
    }

    if ((int) config('app.updated', 0) === 1) {
        return;
    }

    try {
        $pdo = db();

        if (schema_needs_update($pdo)) {
            run_schema_update($pdo);
        }

        schema_set_updated_flag(1);
    } catch (Throwable $e) {
        if ((bool) config('app.debug', false)) {
            error_log('Schema auto-update failed: ' . $e->getMessage());
        }
    }
}
