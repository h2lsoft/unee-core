-- Drive (menu Tools, module drive): folders with their settings (rights by group, file types, max size, public), files stored in private_dir/drive under random names,
-- favorites and pinned folders of the users, storage counter of the instance (quota)

CREATE TABLE IF NOT EXISTS `xcore_drive_folder` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_drive_folder_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'parent folder, 0 = root',
  `name` varchar(255) NOT NULL DEFAULT '',
  `path` varchar(240) NOT NULL DEFAULT '' COMMENT 'ids of the ancestors and of the folder: /3/7/',
  `settings` text DEFAULT NULL COMMENT 'json: read, write, manage (group ids), extensions, max_size, links, public, versions',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_drive_folder_id` (`xcore_drive_folder_id`),
  KEY `path` (`path`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_drive_file` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_drive_folder_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '0 = root',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'name shown, as uploaded',
  `extension` varchar(20) NOT NULL DEFAULT '',
  `mime` varchar(100) NOT NULL DEFAULT '',
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL DEFAULT '',
  `disk` varchar(255) NOT NULL DEFAULT '' COMMENT 'path relative to private_dir, random name',
  `status` varchar(20) NOT NULL DEFAULT 'ready' COMMENT 'ready, scanning (antivirus)',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_drive_folder_id` (`xcore_drive_folder_id`),
  KEY `sha256` (`sha256`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_drive_favorite` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_drive_file_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `xcore_user_id_xcore_drive_file_id` (`xcore_user_id`, `xcore_drive_file_id`, `deleted_key`),
  KEY `xcore_drive_file_id` (`xcore_drive_file_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_drive_pin` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_drive_folder_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `xcore_user_id_xcore_drive_folder_id` (`xcore_user_id`, `xcore_drive_folder_id`, `deleted_key`),
  KEY `position` (`position`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- storage used by the instance (storage-quota): files counter moved by \Core\Storage on each write, files and database measured again by Storage::recount()
CREATE TABLE IF NOT EXISTS `xcore_storage` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(50) NOT NULL DEFAULT '' COMMENT 'files, database',
  `bytes` bigint(20) NOT NULL DEFAULT 0,
  `measured_at` datetime DEFAULT NULL COMMENT 'last full measure',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scope` (`scope`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `xcore_storage` (`scope`, `bytes`, `measured_at`, `updated_at`)
SELECT 'files', 0, NULL, NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_storage` WHERE `scope` = 'files');

INSERT INTO `xcore_storage` (`scope`, `bytes`, `measured_at`, `updated_at`)
SELECT 'database', 0, NULL, NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_storage` WHERE `scope` = 'database');

-- module Drive (menu Tools): list = see and download, write = add, rename, move, copy, trash, delete = empty the trash, manage = settings of the folders, public folders
INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `custom`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `global_search`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Drive', 'normal', 'yes', 'folder_open', 'drive', '["list","write","delete","manage"]', 50, 'yes', 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Tools' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'drive' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` = 'drive' AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` = 'drive' AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` = 'drive' AND `created_by` = 'unee-migrate';
DROP TABLE IF EXISTS `xcore_storage`;
DROP TABLE IF EXISTS `xcore_drive_pin`;
DROP TABLE IF EXISTS `xcore_drive_favorite`;
DROP TABLE IF EXISTS `xcore_drive_file`;
DROP TABLE IF EXISTS `xcore_drive_folder`;
