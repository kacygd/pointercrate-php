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

function schema_app_settings_value_text_ready(PDO $pdo): bool
{
    if (!schema_table_exists($pdo, 'app_settings')) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT data_type
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'app_settings'
           AND column_name = 'setting_value'
         LIMIT 1"
    );
    $stmt->execute();

    $dataType = strtolower((string) ($stmt->fetchColumn() ?: ''));
    return in_array($dataType, ['text', 'mediumtext', 'longtext'], true);
}

function schema_apply_users_role_enum(PDO $pdo): void
{
    $pdo->exec("UPDATE users SET role = 'owner' WHERE role = 'admin'");
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('player', 'list_helper', 'list_editor', 'owner') NOT NULL DEFAULT 'player'");
}

function schema_target_version(): string
{
    return '2026-06-default-badges';
}

function schema_seed_default_badges(PDO $pdo): int
{
    return (int) $pdo->exec(
        "INSERT IGNORE INTO badges
            (name, description, image_url, color, text_color, is_active, created_by_user_id, created_at, updated_at)
         VALUES
            ('Verified', 'For players who regularly verify high difficulty levels.', 'assets/badges/badge-20260614115745-f51b1e80.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 04:57:45', '2026-06-14 04:57:45'),
            ('Moderator', 'This person will monitor the record/verify/comment activities and report any violations to the List Editor.', 'assets/badges/badge-20260614120005-3098aaf8.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:00:05', '2026-06-14 05:00:05'),
            ('Supporter', 'Supporters of the server', 'assets/badges/badge-20260614120118-e7d3ebe3.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:01:18', '2026-06-14 05:01:18'),
            ('Developer', 'Server programmer', 'assets/badges/badge-20260614120332-7697311b.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:03:32', '2026-06-14 05:03:32'),
            ('Owner', 'Owner of server', 'assets/badges/badge-20260614120421-8306852a.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:04:21', '2026-06-14 05:04:21'),
            ('Special', 'For a special person to the owner', 'assets/badges/badge-20260614120504-1a1b2f62.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:05:04', '2026-06-14 05:05:04'),
            ('Editor', 'List administrator', 'assets/badges/badge-20260614121159-ed12789d.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:06:32', '2026-06-14 05:11:59'),
            ('Helper', 'The person who checks the submitted records.', 'assets/badges/badge-20260614121425-3409c439.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:14:25', '2026-06-14 05:14:25')"
    );
}

function schema_needs_update(PDO $pdo): bool
{
    if (!schema_col_exists($pdo, 'users', 'youtube_channel')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'discord_user_id')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'discord_username')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'discord_link_pending_user_id')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'discord_link_code_hash')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'discord_link_code_expires_at')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'discord_link_requested_at')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'points')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'is_banned')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'comments_disabled')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'users', 'bonus_points')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_is_banned')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_comments_disabled')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'uq_users_discord_user_id')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_discord_pending')) {
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
    if (!schema_col_exists($pdo, 'users', 'display_name')) {
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
    if (!schema_app_settings_value_text_ready($pdo)) {
        return true;
    }
    if (app_setting_get('schema.version', '') !== schema_target_version()) {
        return true;
    }
    if (!schema_col_exists($pdo, 'demons', 'comments_disabled')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'demons', 'idx_demons_comments_disabled')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'demon_level_info_values')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'level_comments')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'level_comments', 'parent_comment_id')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'level_comments', 'is_pinned')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'level_comments', 'pinned_by_user_id')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'level_comments', 'pinned_at')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'level_comments', 'idx_level_comments_parent')) {
        return true;
    }
    if (!schema_idx_exists($pdo, 'level_comments', 'idx_level_comments_pinned')) {
        return true;
    }
    if (!schema_fk_exists($pdo, 'level_comments', 'fk_level_comments_parent')) {
        return true;
    }
    if (!schema_fk_exists($pdo, 'level_comments', 'fk_level_comments_pinned_by')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'level_comment_reactions')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'level_comment_reports')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'badges')) {
        return true;
    }
    if (!schema_col_exists($pdo, 'badges', 'image_url')) {
        return true;
    }
    if (!schema_table_exists($pdo, 'user_badges')) {
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

    if (!schema_col_exists($pdo, 'users', 'discord_user_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN discord_user_id VARCHAR(32) NULL AFTER youtube_channel');
        $logs[] = '[OK] Added users.discord_user_id';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN discord_user_id VARCHAR(32) NULL');

    if (!schema_col_exists($pdo, 'users', 'discord_username')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN discord_username VARCHAR(120) NULL AFTER discord_user_id');
        $logs[] = '[OK] Added users.discord_username';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN discord_username VARCHAR(120) NULL');

    if (!schema_col_exists($pdo, 'users', 'discord_link_pending_user_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN discord_link_pending_user_id VARCHAR(32) NULL AFTER discord_username');
        $logs[] = '[OK] Added users.discord_link_pending_user_id';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN discord_link_pending_user_id VARCHAR(32) NULL');

    if (!schema_col_exists($pdo, 'users', 'discord_link_code_hash')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN discord_link_code_hash VARCHAR(255) NULL AFTER discord_link_pending_user_id');
        $logs[] = '[OK] Added users.discord_link_code_hash';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN discord_link_code_hash VARCHAR(255) NULL');

    if (!schema_col_exists($pdo, 'users', 'discord_link_code_expires_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN discord_link_code_expires_at TIMESTAMP NULL DEFAULT NULL AFTER discord_link_code_hash');
        $logs[] = '[OK] Added users.discord_link_code_expires_at';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN discord_link_code_expires_at TIMESTAMP NULL DEFAULT NULL');

    if (!schema_col_exists($pdo, 'users', 'discord_link_requested_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN discord_link_requested_at TIMESTAMP NULL DEFAULT NULL AFTER discord_link_code_expires_at');
        $logs[] = '[OK] Added users.discord_link_requested_at';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN discord_link_requested_at TIMESTAMP NULL DEFAULT NULL');

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

    if (!schema_col_exists($pdo, 'users', 'comments_disabled')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_banned');
        $logs[] = '[OK] Added users.comments_disabled';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0');

    if (!schema_col_exists($pdo, 'users', 'bonus_points')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER points');
        $logs[] = '[OK] Added users.bonus_points';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    if (!schema_idx_exists($pdo, 'users', 'idx_users_is_banned')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_is_banned (is_banned)');
        $logs[] = '[OK] Added idx_users_is_banned';
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_comments_disabled')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_comments_disabled (comments_disabled)');
        $logs[] = '[OK] Added idx_users_comments_disabled';
    }
    if (!schema_idx_exists($pdo, 'users', 'uq_users_discord_user_id')) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_discord_user_id (discord_user_id)');
        $logs[] = '[OK] Added uq_users_discord_user_id';
    }
    if (!schema_idx_exists($pdo, 'users', 'idx_users_discord_pending')) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_discord_pending (discord_link_pending_user_id)');
        $logs[] = '[OK] Added idx_users_discord_pending';
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

    if (!schema_col_exists($pdo, 'users', 'display_name')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN display_name VARCHAR(60) NULL AFTER username');
        $logs[] = '[OK] Added users.display_name';
    }
    $pdo->exec('ALTER TABLE users MODIFY COLUMN display_name VARCHAR(60) NULL');

    $clearedDisplayNames = (int) $pdo->exec(
        "UPDATE users
         SET display_name = NULL
         WHERE display_name IS NOT NULL
           AND TRIM(display_name) = ''"
    );
    if ($clearedDisplayNames > 0) {
        $logs[] = '[OK] Cleared blank users.display_name values: ' . $clearedDisplayNames . ' row(s)';
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
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added app_settings table';
    }
    if (!schema_app_settings_value_text_ready($pdo)) {
        $pdo->exec('ALTER TABLE app_settings MODIFY COLUMN setting_value TEXT NOT NULL');
        $logs[] = '[OK] Expanded app_settings.setting_value to TEXT';
    }

    if (!schema_col_exists($pdo, 'demons', 'comments_disabled')) {
        $pdo->exec('ALTER TABLE demons ADD COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy');
        $logs[] = '[OK] Added demons.comments_disabled';
    }
    $pdo->exec('ALTER TABLE demons MODIFY COLUMN comments_disabled TINYINT(1) NOT NULL DEFAULT 0');

    if (!schema_idx_exists($pdo, 'demons', 'idx_demons_comments_disabled')) {
        $pdo->exec('ALTER TABLE demons ADD INDEX idx_demons_comments_disabled (comments_disabled)');
        $logs[] = '[OK] Added idx_demons_comments_disabled';
    }

    if (!schema_table_exists($pdo, 'demon_level_info_values')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS demon_level_info_values (
                demon_id INT UNSIGNED NOT NULL,
                row_key VARCHAR(64) NOT NULL,
                row_value TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (demon_id, row_key),
                CONSTRAINT fk_level_info_values_demon
                    FOREIGN KEY (demon_id)
                    REFERENCES demons (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added demon_level_info_values table';
    }

    if (!schema_table_exists($pdo, 'level_comments')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS level_comments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                demon_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                parent_comment_id BIGINT UNSIGNED NULL,
                body TEXT NOT NULL,
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                pinned_by_user_id INT UNSIGNED NULL,
                pinned_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                KEY idx_level_comments_demon_created (demon_id, created_at),
                KEY idx_level_comments_user (user_id),
                KEY idx_level_comments_parent (parent_comment_id, created_at),
                KEY idx_level_comments_pinned (demon_id, is_pinned, pinned_at),
                CONSTRAINT fk_level_comments_demon
                    FOREIGN KEY (demon_id)
                    REFERENCES demons (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_level_comments_user
                    FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_level_comments_parent
                    FOREIGN KEY (parent_comment_id)
                    REFERENCES level_comments (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_level_comments_pinned_by
                    FOREIGN KEY (pinned_by_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added level_comments table';
    }
    if (!schema_col_exists($pdo, 'level_comments', 'parent_comment_id')) {
        $pdo->exec('ALTER TABLE level_comments ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL AFTER user_id');
        $logs[] = '[OK] Added level_comments.parent_comment_id';
    }
    $pdo->exec('ALTER TABLE level_comments MODIFY COLUMN parent_comment_id BIGINT UNSIGNED NULL');

    if (!schema_col_exists($pdo, 'level_comments', 'is_pinned')) {
        $pdo->exec('ALTER TABLE level_comments ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER body');
        $logs[] = '[OK] Added level_comments.is_pinned';
    }
    $pdo->exec('ALTER TABLE level_comments MODIFY COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0');

    if (!schema_col_exists($pdo, 'level_comments', 'pinned_by_user_id')) {
        $pdo->exec('ALTER TABLE level_comments ADD COLUMN pinned_by_user_id INT UNSIGNED NULL AFTER is_pinned');
        $logs[] = '[OK] Added level_comments.pinned_by_user_id';
    }
    $pdo->exec('ALTER TABLE level_comments MODIFY COLUMN pinned_by_user_id INT UNSIGNED NULL');

    if (!schema_col_exists($pdo, 'level_comments', 'pinned_at')) {
        $pdo->exec('ALTER TABLE level_comments ADD COLUMN pinned_at TIMESTAMP NULL DEFAULT NULL AFTER pinned_by_user_id');
        $logs[] = '[OK] Added level_comments.pinned_at';
    }
    $pdo->exec('ALTER TABLE level_comments MODIFY COLUMN pinned_at TIMESTAMP NULL DEFAULT NULL');

    if (!schema_idx_exists($pdo, 'level_comments', 'idx_level_comments_parent')) {
        $pdo->exec('ALTER TABLE level_comments ADD INDEX idx_level_comments_parent (parent_comment_id, created_at)');
        $logs[] = '[OK] Added idx_level_comments_parent';
    }
    if (!schema_idx_exists($pdo, 'level_comments', 'idx_level_comments_pinned')) {
        $pdo->exec('ALTER TABLE level_comments ADD INDEX idx_level_comments_pinned (demon_id, is_pinned, pinned_at)');
        $logs[] = '[OK] Added idx_level_comments_pinned';
    }
    if (!schema_fk_exists($pdo, 'level_comments', 'fk_level_comments_parent')) {
        $pdo->exec('ALTER TABLE level_comments
                    ADD CONSTRAINT fk_level_comments_parent
                    FOREIGN KEY (parent_comment_id)
                    REFERENCES level_comments (id)
                    ON DELETE CASCADE');
        $logs[] = '[OK] Added fk_level_comments_parent';
    }
    if (!schema_fk_exists($pdo, 'level_comments', 'fk_level_comments_pinned_by')) {
        $pdo->exec('ALTER TABLE level_comments
                    ADD CONSTRAINT fk_level_comments_pinned_by
                    FOREIGN KEY (pinned_by_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL');
        $logs[] = '[OK] Added fk_level_comments_pinned_by';
    }

    if (!schema_table_exists($pdo, 'level_comment_reactions')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS level_comment_reactions (
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                reaction TINYINT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (comment_id, user_id),
                KEY idx_level_comment_reactions_user (user_id),
                KEY idx_level_comment_reactions_reaction (reaction),
                CONSTRAINT fk_level_comment_reactions_comment
                    FOREIGN KEY (comment_id)
                    REFERENCES level_comments (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_level_comment_reactions_user
                    FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added level_comment_reactions table';
    }

    if (!schema_table_exists($pdo, 'level_comment_reports')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS level_comment_reports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                reason VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_level_comment_reports_user (comment_id, user_id),
                KEY idx_level_comment_reports_comment_created (comment_id, created_at),
                KEY idx_level_comment_reports_user (user_id),
                CONSTRAINT fk_level_comment_reports_comment
                    FOREIGN KEY (comment_id)
                    REFERENCES level_comments (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_level_comment_reports_user
                    FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added level_comment_reports table';
    }

    if (!schema_table_exists($pdo, 'badges')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS badges (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(36) NOT NULL,
                description VARCHAR(140) NULL,
                image_url VARCHAR(255) NULL,
                color CHAR(7) NOT NULL DEFAULT '#465A7A',
                text_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_badges_name (name),
                KEY idx_badges_active (is_active),
                KEY idx_badges_created_by (created_by_user_id),
                CONSTRAINT fk_badges_created_by
                    FOREIGN KEY (created_by_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added badges table';
    }
    if (!schema_col_exists($pdo, 'badges', 'image_url')) {
        $pdo->exec('ALTER TABLE badges ADD COLUMN image_url VARCHAR(255) NULL AFTER description');
        $logs[] = '[OK] Added badges.image_url';
    }
    $pdo->exec('ALTER TABLE badges MODIFY COLUMN image_url VARCHAR(255) NULL');

    $seededDefaultBadges = schema_seed_default_badges($pdo);
    $logs[] = '[OK] Ensured default badges: ' . $seededDefaultBadges . ' inserted badge(s)';

    if (!schema_table_exists($pdo, 'user_badges')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_badges (
                user_id INT UNSIGNED NOT NULL,
                badge_id INT UNSIGNED NOT NULL,
                assigned_by_user_id INT UNSIGNED NULL,
                assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, badge_id),
                KEY idx_user_badges_badge (badge_id),
                KEY idx_user_badges_assigned_by (assigned_by_user_id),
                CONSTRAINT fk_user_badges_user
                    FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_user_badges_badge
                    FOREIGN KEY (badge_id)
                    REFERENCES badges (id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_user_badges_assigned_by
                    FOREIGN KEY (assigned_by_user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $logs[] = '[OK] Added user_badges table';
    }

    $seededListSettings = (int) $pdo->exec(
        "INSERT IGNORE INTO app_settings (setting_key, setting_value)
         VALUES
            ('list.show_extended', '1'),
            ('list.show_legacy', '1'),
            ('list.main_max_rank', '75'),
            ('list.extended_max_rank', '150'),
            ('level_comments.enabled', '1')"
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

    $optimizeTables = [
        'app_settings',
        'users',
        'demons',
        'completions',
        'submissions',
        'demon_position_history',
        'demon_level_info_values',
        'level_comments',
        'level_comment_reactions',
        'level_comment_reports',
        'badges',
        'user_badges',
    ];
    $optimizedTables = 0;
    foreach ($optimizeTables as $tableName) {
        if (!schema_table_exists($pdo, $tableName)) {
            continue;
        }

        try {
            $pdo->query('OPTIMIZE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetchAll();
            $optimizedTables++;
        } catch (Throwable $throwable) {
            $logs[] = '[WARN] Could not optimize ' . $tableName . ': ' . $throwable->getMessage();
        }
    }
    $logs[] = '[OK] Optimized existing database tables: ' . $optimizedTables . ' table(s)';

    if (app_setting_set('schema.version', schema_target_version())) {
        $logs[] = '[OK] Updated schema.version to ' . schema_target_version();
    }

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

function schema_config_needs_update(): bool
{
    return !app_setting_truthy((string) config('app.updated', '0'), false);
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

    try {
        $pdo = db();
        $configNeedsUpdate = schema_config_needs_update();
        $schemaVersionReady = app_setting_get('schema.version', '') === schema_target_version();

        if ($schemaVersionReady && !$configNeedsUpdate) {
            return;
        }

        if ($configNeedsUpdate || schema_needs_update($pdo)) {
            run_schema_update($pdo);
        }

        app_setting_set('schema.version', schema_target_version());
        schema_set_updated_flag(1);
    } catch (Throwable $e) {
        if ((bool) config('app.debug', false)) {
            error_log('Schema auto-update failed: ' . $e->getMessage());
        }
    }
}
