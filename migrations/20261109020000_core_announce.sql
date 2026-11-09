-- announcements (module Communication > Announce): html message shown once in a lightbox to the logged users of the backend, the frontend or both
-- one read row per user (I understood), kept apart from the logs so a purge keeps the history (hidden plugin announce-read, rights list and export)

CREATE TABLE IF NOT EXISTS `xcore_announce` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `content` mediumtext DEFAULT NULL,
  `target` enum('backend','frontend','both') NOT NULL DEFAULT 'backend',
  `xcore_group_ids` text DEFAULT NULL COMMENT 'json array of group ids, empty = every logged user',
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL COMMENT 'empty = no end',
  `link_label` varchar(100) NOT NULL DEFAULT '',
  `link_url` varchar(255) NOT NULL DEFAULT '',
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `live` (`active`, `start_at`, `end_at`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_announce_read` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_announce_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `application` varchar(10) NOT NULL DEFAULT 'backend',
  `read_at` datetime DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announce_user` (`xcore_announce_id`, `xcore_user_id`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Announce', 'normal', 'release_alert', 'announce', '["list","add","edit","delete"]', 5, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'announce' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Announce reads', 'normal', 'visibility', 'announce-read', '["list","export"]', 6, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'announce-read' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('announce', 'announce-read') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('announce', 'announce-read') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('announce', 'announce-read') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('announce', 'announce-read') AND `created_by` = 'unee-migrate';
DROP TABLE IF EXISTS `xcore_announce_read`;
DROP TABLE IF EXISTS `xcore_announce`;
