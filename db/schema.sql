CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(40) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    email VARCHAR(120) NULL,
    country_code CHAR(2) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('player', 'admin') NOT NULL DEFAULT 'player',
    points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_country (country_code),
    KEY idx_users_points (points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    difficulty VARCHAR(50) NOT NULL DEFAULT 'Extreme Demon',
    requirement TINYINT UNSIGNED NOT NULL DEFAULT 100,
    creator VARCHAR(160) NULL,
    publisher VARCHAR(120) NOT NULL,
    verifier VARCHAR(120) NULL,
    video_url VARCHAR(255) NOT NULL,
    thumbnail_url VARCHAR(255) NULL,
    level_id VARCHAR(32) NULL,
    level_length VARCHAR(40) NULL,
    song VARCHAR(120) NULL,
    object_count INT UNSIGNED NULL,
    legacy TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demons_position (position),
    UNIQUE KEY uq_demons_name (name),
    KEY idx_demons_legacy (legacy)
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


