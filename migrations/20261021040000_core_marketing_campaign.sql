-- marketing: campaigns (xcore_campaign, name = alphanumeric code) and their expenses and revenues (xcore_campaign_entry, amounts incl. tax)
-- an entry may point to the record it comes from (record_type + record_id, ex: donation 125), once by type

CREATE TABLE IF NOT EXISTS `xcore_campaign` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `description` text DEFAULT NULL,
  `date_start` date DEFAULT NULL,
  `date_end` date DEFAULT NULL,
  `budget` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'EUR',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `name` (`name`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='marketing campaigns';

CREATE TABLE IF NOT EXISTS `xcore_campaign_entry` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_campaign_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `type` varchar(20) NOT NULL DEFAULT 'expense' COMMENT 'expense, revenue',
  `date` date DEFAULT NULL,
  `label` varchar(255) NOT NULL DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'incl. tax',
  `record_type` varchar(64) NOT NULL DEFAULT '' COMMENT 'origin, ex: donation',
  `record_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'id of the record of origin',
  `note` text DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_campaign_id` (`xcore_campaign_id`, `type`),
  KEY `record` (`record_type`, `record_id`),
  KEY `date` (`date`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='expenses and revenues of the campaigns';

INSERT INTO `xcore_menu` (`name`, `icon`, `color`, `position`, `created_at`, `created_by`)
SELECT 'Marketing', 'ads_click', '#ff1493', 55, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'Marketing' AND `deleted` = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Campaigns', 'normal', 'campaign', 'campaign', '["list","add","edit","delete"]', 10, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Marketing' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'campaign' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Campaign entries', 'normal', 'receipt_long', 'campaign-entry', '["list","add","edit","delete"]', 20, 'no', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Marketing' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'campaign-entry' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('campaign', 'campaign-entry') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('campaign', 'campaign-entry') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('campaign', 'campaign-entry') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('campaign', 'campaign-entry') AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_menu` WHERE `name` = 'Marketing' AND `created_by` = 'unee-migrate' AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` p WHERE p.xcore_menu_id = `xcore_menu`.id AND p.deleted = 'no');
DELETE FROM `xcore_tag` WHERE `signature` = 'xcore_campaign';
DROP TABLE IF EXISTS `xcore_campaign_entry`;
DROP TABLE IF EXISTS `xcore_campaign`;
