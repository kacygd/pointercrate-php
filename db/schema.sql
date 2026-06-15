CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL,
    display_name VARCHAR(60) NULL,
    email VARCHAR(120) NULL,
    country_code CHAR(2) NULL,
    youtube_channel VARCHAR(255) NULL,
    discord_user_id VARCHAR(32) NULL,
    discord_username VARCHAR(120) NULL,
    discord_link_pending_user_id VARCHAR(32) NULL,
    discord_link_code_hash VARCHAR(255) NULL,
    discord_link_code_expires_at TIMESTAMP NULL DEFAULT NULL,
    discord_link_requested_at TIMESTAMP NULL DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('player', 'list_helper', 'list_editor', 'owner') NOT NULL DEFAULT 'player',
    is_banned TINYINT(1) NOT NULL DEFAULT 0,
    comments_disabled TINYINT(1) NOT NULL DEFAULT 0,
    points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_discord_user_id (discord_user_id),
    KEY idx_users_role (role),
    KEY idx_users_country (country_code),
    KEY idx_users_is_banned (is_banned),
    KEY idx_users_comments_disabled (comments_disabled),
    KEY idx_users_discord_pending (discord_link_pending_user_id),
    KEY idx_users_points (points),
    KEY idx_users_bonus_points (bonus_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    difficulty VARCHAR(50) NOT NULL DEFAULT 'Extreme Demon',
    requirement TINYINT UNSIGNED NOT NULL DEFAULT 100,
    creator VARCHAR(160) NULL,
    creator_more VARCHAR(512) NULL,
    publisher VARCHAR(120) NOT NULL,
    publisher_user_id INT UNSIGNED NULL,
    verifier VARCHAR(120) NULL,
    verifier_user_id INT UNSIGNED NULL,
    video_url VARCHAR(255) NOT NULL,
    thumbnail_url VARCHAR(255) NULL,
    level_id VARCHAR(32) NULL,
    level_length VARCHAR(40) NULL,
    song VARCHAR(120) NULL,
    object_count INT UNSIGNED NULL,
    legacy TINYINT(1) NOT NULL DEFAULT 0,
    comments_disabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demons_position (position),
    UNIQUE KEY uq_demons_name (name),
    KEY idx_demons_legacy (legacy),
    KEY idx_demons_comments_disabled (comments_disabled),
    KEY idx_demons_publisher_user_id (publisher_user_id),
    KEY idx_demons_verifier_user_id (verifier_user_id),
    CONSTRAINT fk_demons_publisher_user
        FOREIGN KEY (publisher_user_id)
        REFERENCES users (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_demons_verifier_user
        FOREIGN KEY (verifier_user_id)
        REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS completions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    demon_id INT UNSIGNED NOT NULL,
    player VARCHAR(120) NOT NULL,
    video_url VARCHAR(255) NOT NULL,
    progress TINYINT UNSIGNED NOT NULL DEFAULT 100,
    placement INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_completions_demon_player (demon_id, player),
    KEY idx_completions_demon (demon_id),
    KEY idx_completions_player (player),
    CONSTRAINT fk_completions_demon
        FOREIGN KEY (demon_id)
        REFERENCES demons (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('demon', 'completion') NOT NULL,
    demon_name VARCHAR(120) NOT NULL,
    difficulty VARCHAR(50) NULL,
    publisher VARCHAR(120) NULL,
    player VARCHAR(120) NULL,
    submitted_by_user_id INT UNSIGNED NULL,
    video_url VARCHAR(255) NULL,
    raw_footage_url VARCHAR(255) NULL,
    platform VARCHAR(50) NULL,
    refresh_rate INT UNSIGNED NULL,
    progress TINYINT UNSIGNED NULL,
    notes TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    review_note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_submissions_status (status),
    KEY idx_submissions_type (type),
    KEY idx_submissions_demon_name (demon_name),
    KEY idx_submissions_user (submitted_by_user_id),
    CONSTRAINT fk_submissions_user
        FOREIGN KEY (submitted_by_user_id)
        REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demon_position_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    demon_id INT UNSIGNED NOT NULL,
    old_position INT UNSIGNED NULL,
    new_position INT UNSIGNED NOT NULL,
    changed_by_user_id INT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_position_history_demon (demon_id),
    KEY idx_position_history_created (created_at),
    KEY idx_position_history_user (changed_by_user_id),
    CONSTRAINT fk_position_history_demon
        FOREIGN KEY (demon_id)
        REFERENCES demons (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_position_history_user
        FOREIGN KEY (changed_by_user_id)
        REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demon_level_info_values (
    demon_id INT UNSIGNED NOT NULL,
    row_key VARCHAR(64) NOT NULL,
    row_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (demon_id, row_key),
    CONSTRAINT fk_level_info_values_demon
        FOREIGN KEY (demon_id)
        REFERENCES demons (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS level_comments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS level_comment_reactions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS level_comment_reports (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS badges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO badges
    (name, description, image_url, color, text_color, is_active, created_by_user_id, created_at, updated_at)
VALUES
    ('Verified', 'For players who regularly verify high difficulty levels.', 'assets/badges/badge-20260614115745-f51b1e80.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 04:57:45', '2026-06-14 04:57:45'),
    ('Moderator', 'This person will monitor the record/verify/comment activities and report any violations to the List Editor.', 'assets/badges/badge-20260614120005-3098aaf8.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:00:05', '2026-06-14 05:00:05'),
    ('Supporter', 'Supporters of the server', 'assets/badges/badge-20260614120118-e7d3ebe3.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:01:18', '2026-06-14 05:01:18'),
    ('Developer', 'Server programmer', 'assets/badges/badge-20260614120332-7697311b.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:03:32', '2026-06-14 05:03:32'),
    ('Owner', 'Owner of server', 'assets/badges/badge-20260614120421-8306852a.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:04:21', '2026-06-14 05:04:21'),
    ('Special', 'For a special person to the owner', 'assets/badges/badge-20260614120504-1a1b2f62.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:05:04', '2026-06-14 05:05:04'),
    ('Editor', 'List administrator', 'assets/badges/badge-20260614121159-ed12789d.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:06:32', '2026-06-14 05:11:59'),
    ('Helper', 'The person who checks the submitted records.', 'assets/badges/badge-20260614121425-3409c439.png', '#465A7A', '#FFFFFF', 1, NULL, '2026-06-14 05:14:25', '2026-06-14 05:14:25');

CREATE TABLE IF NOT EXISTS user_badges (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
