-- Emro Science upgrade (14 Nov 2026) — same changes as `php artisan migrate`, for phpMyAdmin.
-- Safe to run more than once. It only CREATEs missing tables and ADDs missing columns;
-- no existing table, column or row is dropped, renamed or changed (except maqola.pdf becoming optional).
-- phpMyAdmin: select the site database -> SQL tab -> paste all of this -> Go.

-- 1. New tables ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `papers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `authors` varchar(255) NOT NULL,
  `affiliation` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `abstract` text DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `locale` varchar(5) NOT NULL DEFAULT 'uz',
  `source` varchar(20) NOT NULL DEFAULT 'web',
  `telegram_chat_id` bigint(20) DEFAULT NULL,
  `telegram_username` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `file_id` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `storage_message_id` bigint(20) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'submitted',
  `issue_id` bigint(20) unsigned DEFAULT NULL,
  `article_id` bigint(20) unsigned DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `papers_code_unique` (`code`),
  KEY `papers_telegram_chat_id_index` (`telegram_chat_id`),
  KEY `papers_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `type` varchar(20) NOT NULL,
  `paper_id` bigint(20) unsigned DEFAULT NULL,
  `article_id` bigint(20) unsigned DEFAULT NULL,
  `recipient` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `locale` varchar(5) NOT NULL DEFAULT 'uz',
  `issued_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `certificates_code_unique` (`code`),
  KEY `certificates_paper_id_index` (`paper_id`),
  KEY `certificates_article_id_index` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_chats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `locale` varchar(5) DEFAULT NULL,
  `state` varchar(40) DEFAULT NULL,
  `data` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `telegram_chats_chat_id_unique` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- English/Russian editorial-board pages (they used to show the Uzbek text).
CREATE TABLE IF NOT EXISTS `teameng` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `team` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `teamru`  (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `team` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Start them with the current team text (only if still empty).
INSERT INTO `teameng` (`team`, `created_at`, `updated_at`)
  SELECT t.`team`, NOW(), NOW() FROM `team` t WHERE NOT EXISTS (SELECT 1 FROM `teameng`) ORDER BY t.`id` DESC LIMIT 1;
INSERT INTO `teamru` (`team`, `created_at`, `updated_at`)
  SELECT t.`team`, NOW(), NOW() FROM `team` t WHERE NOT EXISTS (SELECT 1 FROM `teamru`) ORDER BY t.`id` DESC LIMIT 1;

-- Page translation tables, created only if this database never had them.
CREATE TABLE IF NOT EXISTS `abouteng`       (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `about` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `aboutru`        (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `about` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `aimeng`         (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `aim` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `aimru`          (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `aim` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `instructioneng` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `instruction` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `instructionru`  (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `instruction` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `plagarismeng`   (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `plagarism` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `plagarismru`    (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `plagarism` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `submissioneng`  (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `submission` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `submissionru`   (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `submission` text NOT NULL, `created_at` timestamp NULL DEFAULT NULL, `updated_at` timestamp NULL DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. New columns (added only when missing) ---------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'article' AND COLUMN_NAME = 'pdf_file_id') = 0,
  'ALTER TABLE `article` ADD `pdf_file_id` varchar(255) NULL DEFAULT NULL AFTER `pdf`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maqola' AND COLUMN_NAME = 'pdf_file_id') = 0,
  'ALTER TABLE `maqola` ADD `pdf_file_id` varchar(255) NULL DEFAULT NULL AFTER `pdf`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'type') = 0,
  'ALTER TABLE `users` ADD `type` varchar(100) NOT NULL DEFAULT ''admin''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- maqola.pdf becomes optional (issue PDFs can live in Telegram). Keeps the column's current type and length.
SET @sql := (SELECT IF(IS_NULLABLE = 'NO', CONCAT('ALTER TABLE `maqola` MODIFY `pdf` ', COLUMN_TYPE, ' NULL'), 'SELECT 1')
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maqola' AND COLUMN_NAME = 'pdf');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Record the migrations so `php artisan migrate` later knows they are done -------

CREATE TABLE IF NOT EXISTS `migrations` (`id` int(10) unsigned NOT NULL AUTO_INCREMENT, `migration` varchar(255) NOT NULL, `batch` int(11) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @batch := (SELECT IFNULL(MAX(`batch`), 0) + 1 FROM `migrations`);
INSERT INTO `migrations` (`migration`, `batch`) SELECT m.name, @batch FROM (
  SELECT '2026_11_14_000001_create_papers_table' AS name UNION ALL
  SELECT '2026_11_14_000002_create_certificates_table' UNION ALL
  SELECT '2026_11_14_000003_create_telegram_chats_table' UNION ALL
  SELECT '2026_11_14_000004_add_telegram_file_ids' UNION ALL
  SELECT '2026_11_14_000005_create_team_translation_tables' UNION ALL
  SELECT '2026_11_14_000006_add_type_to_users_table' UNION ALL
  SELECT '2026_11_14_000007_create_missing_page_translation_tables'
) m WHERE NOT EXISTS (SELECT 1 FROM `migrations` x WHERE x.`migration` = m.name);
