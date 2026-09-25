-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Máy chủ: 127.0.0.1
-- Thời gian đã tạo: Th9 22, 2026 lúc 05:37 AM
-- Phiên bản máy phục vụ: 10.4.32-MariaDB
-- Phiên bản PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `demonlist`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `app_settings`
--

CREATE TABLE `app_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `badges`
--

CREATE TABLE `badges` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(36) NOT NULL,
  `description` varchar(140) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `color` char(7) NOT NULL DEFAULT '#465A7A',
  `text_color` char(7) NOT NULL DEFAULT '#FFFFFF',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `completions`
--

CREATE TABLE `completions` (
  `id` int(10) UNSIGNED NOT NULL,
  `demon_id` int(10) UNSIGNED NOT NULL,
  `player` varchar(120) NOT NULL,
  `video_url` varchar(255) NOT NULL,
  `progress` tinyint(3) UNSIGNED NOT NULL DEFAULT 100,
  `enjoyment` tinyint(3) UNSIGNED DEFAULT NULL,
  `placement` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `demons`
--

CREATE TABLE `demons` (
  `id` int(10) UNSIGNED NOT NULL,
  `position` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `difficulty` varchar(50) NOT NULL DEFAULT 'Extreme Demon',
  `requirement` tinyint(3) UNSIGNED NOT NULL DEFAULT 100,
  `creator` varchar(160) DEFAULT NULL,
  `creator_more` varchar(512) DEFAULT NULL,
  `publisher` varchar(120) NOT NULL,
  `publisher_user_id` int(10) UNSIGNED DEFAULT NULL,
  `verifier` varchar(120) DEFAULT NULL,
  `verifier_user_id` int(10) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `video_url` varchar(255) NOT NULL,
  `thumbnail_url` varchar(255) DEFAULT NULL,
  `level_id` varchar(32) DEFAULT NULL,
  `level_length` varchar(40) DEFAULT NULL,
  `song` varchar(120) DEFAULT NULL,
  `object_count` int(10) UNSIGNED DEFAULT NULL,
  `legacy` tinyint(1) NOT NULL DEFAULT 0,
  `comments_disabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `name_cs` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (`name`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `demon_level_info_values`
--

CREATE TABLE `demon_level_info_values` (
  `demon_id` int(10) UNSIGNED NOT NULL,
  `row_key` varchar(64) NOT NULL,
  `row_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `demon_position_history`
--

CREATE TABLE `demon_position_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `demon_id` int(10) UNSIGNED NOT NULL,
  `old_position` int(10) UNSIGNED DEFAULT NULL,
  `new_position` int(10) UNSIGNED NOT NULL,
  `changed_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `demon_tags`
--

CREATE TABLE `demon_tags` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(60) NOT NULL,
  `color` char(7) NOT NULL DEFAULT '#465A7A',
  `gradient` tinyint(1) NOT NULL DEFAULT 0,
  `gradient_color` char(7) DEFAULT NULL,
  `created_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `demon_tag_links`
--

CREATE TABLE `demon_tag_links` (
  `demon_id` int(10) UNSIGNED NOT NULL,
  `tag_id` int(10) UNSIGNED NOT NULL,
  `assigned_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `ip_bans`
--

CREATE TABLE `ip_bans` (
  `id` int(10) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `level_comments`
--

CREATE TABLE `level_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `demon_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `parent_comment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `body` text NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pinned_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `pinned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `level_comment_reactions`
--

CREATE TABLE `level_comment_reactions` (
  `comment_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `reaction` tinyint(4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `level_comment_reports`
--

CREATE TABLE `level_comment_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `comment_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `submissions`
--

CREATE TABLE `submissions` (
  `id` int(10) UNSIGNED NOT NULL,
  `type` enum('demon','completion') NOT NULL,
  `demon_name` varchar(120) NOT NULL,
  `difficulty` varchar(50) DEFAULT NULL,
  `publisher` varchar(120) DEFAULT NULL,
  `player` varchar(120) DEFAULT NULL,
  `submitted_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  `raw_footage_url` varchar(255) DEFAULT NULL,
  `platform` varchar(50) DEFAULT NULL,
  `refresh_rate` int(10) UNSIGNED DEFAULT NULL,
  `progress` tinyint(3) UNSIGNED DEFAULT NULL,
  `enjoyment` tinyint(3) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(40) NOT NULL,
  `display_name` varchar(60) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `country_code` char(2) DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `youtube_channel` varchar(255) DEFAULT NULL,
  `discord_user_id` varchar(32) DEFAULT NULL,
  `discord_username` varchar(120) DEFAULT NULL,
  `discord_link_pending_user_id` varchar(32) DEFAULT NULL,
  `discord_link_code_hash` varchar(255) DEFAULT NULL,
  `discord_link_code_expires_at` timestamp NULL DEFAULT NULL,
  `discord_link_requested_at` timestamp NULL DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `failed_login_attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `login_locked_until` timestamp NULL DEFAULT NULL,
  `role` enum('player','list_helper','list_editor','owner') NOT NULL DEFAULT 'player',
  `is_banned` tinyint(1) NOT NULL DEFAULT 0,
  `comments_disabled` tinyint(1) NOT NULL DEFAULT 0,
  `points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `user_badges`
--

CREATE TABLE `user_badges` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `badge_id` int(10) UNSIGNED NOT NULL,
  `assigned_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Chỉ mục cho bảng `badges`
--
ALTER TABLE `badges`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_badges_name` (`name`),
  ADD KEY `idx_badges_active` (`is_active`),
  ADD KEY `idx_badges_created_by` (`created_by_user_id`);

--
-- Chỉ mục cho bảng `completions`
--
ALTER TABLE `completions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_completions_demon_player` (`demon_id`,`player`),
  ADD KEY `idx_completions_demon` (`demon_id`),
  ADD KEY `idx_completions_player` (`player`);

--
-- Chỉ mục cho bảng `demons`
--
ALTER TABLE `demons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_demons_position` (`position`),
  ADD UNIQUE KEY `uq_demons_name_cs` (`name_cs`),
  ADD KEY `idx_demons_legacy` (`legacy`),
  ADD KEY `idx_demons_comments_disabled` (`comments_disabled`),
  ADD KEY `idx_demons_publisher_user_id` (`publisher_user_id`),
  ADD KEY `idx_demons_verifier_user_id` (`verifier_user_id`);

--
-- Chỉ mục cho bảng `demon_level_info_values`
--
ALTER TABLE `demon_level_info_values`
  ADD PRIMARY KEY (`demon_id`,`row_key`);

--
-- Chỉ mục cho bảng `demon_position_history`
--
ALTER TABLE `demon_position_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_position_history_demon` (`demon_id`),
  ADD KEY `idx_position_history_created` (`created_at`),
  ADD KEY `idx_position_history_user` (`changed_by_user_id`);

--
-- Chỉ mục cho bảng `demon_tags`
--
ALTER TABLE `demon_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_demon_tags_name` (`name`),
  ADD KEY `idx_demon_tags_created_by` (`created_by_user_id`);

--
-- Chỉ mục cho bảng `demon_tag_links`
--
ALTER TABLE `demon_tag_links`
  ADD PRIMARY KEY (`demon_id`,`tag_id`),
  ADD KEY `idx_demon_tag_links_tag` (`tag_id`),
  ADD KEY `idx_demon_tag_links_assigned_by` (`assigned_by_user_id`);

--
-- Chỉ mục cho bảng `ip_bans`
--
ALTER TABLE `ip_bans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ip_bans_ip_address` (`ip_address`),
  ADD KEY `idx_ip_bans_created_by` (`created_by_user_id`);

--
-- Chỉ mục cho bảng `level_comments`
--
ALTER TABLE `level_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_level_comments_demon_created` (`demon_id`,`created_at`),
  ADD KEY `idx_level_comments_user` (`user_id`),
  ADD KEY `idx_level_comments_parent` (`parent_comment_id`,`created_at`),
  ADD KEY `idx_level_comments_pinned` (`demon_id`,`is_pinned`,`pinned_at`),
  ADD KEY `fk_level_comments_pinned_by` (`pinned_by_user_id`);

--
-- Chỉ mục cho bảng `level_comment_reactions`
--
ALTER TABLE `level_comment_reactions`
  ADD PRIMARY KEY (`comment_id`,`user_id`),
  ADD KEY `idx_level_comment_reactions_user` (`user_id`),
  ADD KEY `idx_level_comment_reactions_reaction` (`reaction`);

--
-- Chỉ mục cho bảng `level_comment_reports`
--
ALTER TABLE `level_comment_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_level_comment_reports_user` (`comment_id`,`user_id`),
  ADD KEY `idx_level_comment_reports_comment_created` (`comment_id`,`created_at`),
  ADD KEY `idx_level_comment_reports_user` (`user_id`);

--
-- Chỉ mục cho bảng `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_submissions_status` (`status`),
  ADD KEY `idx_submissions_type` (`type`),
  ADD KEY `idx_submissions_demon_name` (`demon_name`),
  ADD KEY `idx_submissions_user` (`submitted_by_user_id`);

--
-- Chỉ mục cho bảng `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD UNIQUE KEY `uq_users_discord_user_id` (`discord_user_id`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_country` (`country_code`),
  ADD KEY `idx_users_is_banned` (`is_banned`),
  ADD KEY `idx_users_comments_disabled` (`comments_disabled`),
  ADD KEY `idx_users_discord_pending` (`discord_link_pending_user_id`),
  ADD KEY `idx_users_points` (`points`),
  ADD KEY `idx_users_bonus_points` (`bonus_points`),
  ADD KEY `idx_users_last_ip` (`last_ip`);

--
-- Chỉ mục cho bảng `user_badges`
--
ALTER TABLE `user_badges`
  ADD PRIMARY KEY (`user_id`,`badge_id`),
  ADD KEY `idx_user_badges_badge` (`badge_id`),
  ADD KEY `idx_user_badges_assigned_by` (`assigned_by_user_id`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `badges`
--
ALTER TABLE `badges`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `completions`
--
ALTER TABLE `completions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `demons`
--
ALTER TABLE `demons`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `demon_position_history`
--
ALTER TABLE `demon_position_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `demon_tags`
--
ALTER TABLE `demon_tags`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `ip_bans`
--
ALTER TABLE `ip_bans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `level_comments`
--
ALTER TABLE `level_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `level_comment_reports`
--
ALTER TABLE `level_comment_reports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Các ràng buộc cho các bảng đã đổ
--

--
-- Các ràng buộc cho bảng `badges`
--
ALTER TABLE `badges`
  ADD CONSTRAINT `fk_badges_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `completions`
--
ALTER TABLE `completions`
  ADD CONSTRAINT `fk_completions_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `demons`
--
ALTER TABLE `demons`
  ADD CONSTRAINT `fk_demons_publisher_user` FOREIGN KEY (`publisher_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_demons_verifier_user` FOREIGN KEY (`verifier_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `demon_level_info_values`
--
ALTER TABLE `demon_level_info_values`
  ADD CONSTRAINT `fk_level_info_values_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `demon_position_history`
--
ALTER TABLE `demon_position_history`
  ADD CONSTRAINT `fk_position_history_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_position_history_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `demon_tags`
--
ALTER TABLE `demon_tags`
  ADD CONSTRAINT `fk_demon_tags_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `demon_tag_links`
--
ALTER TABLE `demon_tag_links`
  ADD CONSTRAINT `fk_demon_tag_links_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_demon_tag_links_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_demon_tag_links_tag` FOREIGN KEY (`tag_id`) REFERENCES `demon_tags` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `ip_bans`
--
ALTER TABLE `ip_bans`
  ADD CONSTRAINT `fk_ip_bans_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `level_comments`
--
ALTER TABLE `level_comments`
  ADD CONSTRAINT `fk_level_comments_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_level_comments_parent` FOREIGN KEY (`parent_comment_id`) REFERENCES `level_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_level_comments_pinned_by` FOREIGN KEY (`pinned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_level_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `level_comment_reactions`
--
ALTER TABLE `level_comment_reactions`
  ADD CONSTRAINT `fk_level_comment_reactions_comment` FOREIGN KEY (`comment_id`) REFERENCES `level_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_level_comment_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `level_comment_reports`
--
ALTER TABLE `level_comment_reports`
  ADD CONSTRAINT `fk_level_comment_reports_comment` FOREIGN KEY (`comment_id`) REFERENCES `level_comments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_level_comment_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Các ràng buộc cho bảng `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `fk_submissions_user` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Các ràng buộc cho bảng `user_badges`
--
ALTER TABLE `user_badges`
  ADD CONSTRAINT `fk_user_badges_assigned_by` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_user_badges_badge` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_badges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
