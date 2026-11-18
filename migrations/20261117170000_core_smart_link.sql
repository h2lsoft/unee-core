-- smart links (module Communication > Smart links, \Core\SmartLink and \Core\Stats, see the project doc smart-links.md)
-- xcore_smart_link: a code counted by /go/<code>/ (redirect or action) or by \Core\SmartLink::hit() in php
-- xcore_smart_link_hit: one row per hit, insert only (never the ip nor the user agent), ref = once by smart link (required with an amount)
-- xcore_stats_salt: salt of the day of the visitor hash, shared by the statistics, the old days are deleted at each rotation

CREATE TABLE IF NOT EXISTS `xcore_smart_link` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT 'letters, digits, - and _, locked after the first hit',
  `type` varchar(20) NOT NULL DEFAULT 'redirect' COMMENT 'redirect, action',
  `url` text DEFAULT NULL COMMENT 'target of a redirect',
  `description` varchar(255) NOT NULL DEFAULT '',
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`, `deleted_key`),
  KEY `active` (`active`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='smart links';

CREATE TABLE IF NOT EXISTS `xcore_smart_link_hit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_smart_link_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `date` datetime NOT NULL,
  `source` varchar(32) NOT NULL DEFAULT '' COMMENT '?s= of the link',
  `referrer` varchar(190) NOT NULL DEFAULT '' COMMENT 'domain of the referer, page of the site for an action',
  `country` char(2) NOT NULL DEFAULT '' COMMENT 'iso code, empty = unknown',
  `device` varchar(10) NOT NULL DEFAULT '' COMMENT 'mobile, desktop, tablet',
  `browser` varchar(20) NOT NULL DEFAULT '',
  `os` varchar(20) NOT NULL DEFAULT '',
  `visitor` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'hash of the day, never the ip',
  `amount` decimal(12,2) DEFAULT NULL COMMENT 'set by the server only',
  `currency` char(3) NOT NULL DEFAULT '',
  `ref` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'reference of the caller, once by smart link',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  PRIMARY KEY (`id`),
  UNIQUE KEY `smart_link_ref` (`xcore_smart_link_id`, `ref`),
  KEY `smart_link_date` (`xcore_smart_link_id`, `date`),
  KEY `date` (`date`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='hits of the smart links, insert only';

CREATE TABLE IF NOT EXISTS `xcore_stats_salt` (
  `day` date NOT NULL,
  `salt` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`day`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='salt of the day of the visitor hash';

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `config_categories`, `position`, `visible`, `versioning`, `chatter`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Smart links', 'normal', 'link', 'smart-link', '["list","add","edit","delete"]', '["stats","smart-link"]', 17, 'yes', 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = COALESCE((SELECT MIN(n.xcore_menu_id) FROM `xcore_plugin` n WHERE n.route_prefix_name = 'newsletter' AND n.deleted = 'no'), (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no'))
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'smart-link' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `chatter`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Smart link hits', 'normal', 'ads_click', 'smart-link-hit', '["list","export"]', 18, 'no', 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = COALESCE((SELECT MIN(n.xcore_menu_id) FROM `xcore_plugin` n WHERE n.route_prefix_name = 'newsletter' AND n.deleted = 'no'), (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no'))
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'smart-link-hit' AND x.deleted = 'no');

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Communication', 'Smart links purge', 'Deletes the hits of the smart links older than the globals smart-link.retention.months', 'vendor/h2lsoft/unee-core/core/cron/smart_link_purge.php', '', '*', '*', '3', '23', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/smart_link_purge.php' AND x.deleted = 'no');

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Statistics', 'Statistics GeoIP', 'Downloads the country database of the statistics (DB-IP lite, CC BY 4.0), once a month', 'vendor/h2lsoft/unee-core/core/cron/stats_geoip.php', '', '*', '2', '4', '17', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/stats_geoip.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` IN ('vendor/h2lsoft/unee-core/core/cron/smart_link_purge.php', 'vendor/h2lsoft/unee-core/core/cron/stats_geoip.php') AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('smart-link', 'smart-link-hit') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('smart-link', 'smart-link-hit') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('smart-link', 'smart-link-hit') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('smart-link', 'smart-link-hit') AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_globals` WHERE `name` IN ('smart-link.exclude.ips', 'smart-link.retention.months', 'stats.exclude.robots', 'stats.exclude.ips');
DROP TABLE IF EXISTS `xcore_stats_salt`;
DROP TABLE IF EXISTS `xcore_smart_link_hit`;
DROP TABLE IF EXISTS `xcore_smart_link`;
