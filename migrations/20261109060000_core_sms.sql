-- sms (module Communication > SMS, \Core\Sms): one-off sendings and campaigns to mailing lists, sent by the cron task SMS sender
-- one row per recipient (xcore_sms_data: credits used, provider id, delivery report), purchases of prepaid credits (xcore_sms_credit)
-- mobile of the subscribers, blacklisted mobiles (email optional: a row holds an email or a mobile), dashboard widget SMS credits next to the newsletter one
-- plugins in the menu of the newsletters (else the menu Communication)

CREATE TABLE IF NOT EXISTS `xcore_sms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(10) NOT NULL DEFAULT 'one-off' COMMENT 'one-off, campaign',
  `sender` varchar(16) NOT NULL DEFAULT '' COMMENT '3 to 11 letters or digits, or a number',
  `message` text DEFAULT NULL,
  `numbers` text DEFAULT NULL COMMENT 'json array of mobiles (one-off)',
  `xcore_user_ids` text DEFAULT NULL COMMENT 'json array (one-off)',
  `xcore_group_ids` text DEFAULT NULL COMMENT 'json array (one-off)',
  `mailinglist_ids` text DEFAULT NULL COMMENT 'json array (campaign)',
  `marketing` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'stop mention added',
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'draft, scheduled, sending, sent, cancelled, error',
  `programming_date` datetime DEFAULT NULL COMMENT 'empty = next run of the cron task',
  `sent_at` datetime DEFAULT NULL,
  `parts` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'sms of the message (160 gsm, 70 unicode, then 153 / 67)',
  `recipients` int(10) unsigned NOT NULL DEFAULT 0,
  `credits_used` int(10) unsigned NOT NULL DEFAULT 0,
  `delivered` int(10) unsigned NOT NULL DEFAULT 0,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `due` (`status`, `programming_date`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_sms_data` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_sms_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT 'e.164',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_mailinglist_subscriber_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `firstname` varchar(255) NOT NULL DEFAULT '',
  `lastname` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'queued' COMMENT 'queued, sending (claimed by a run), sent, delivered, failed (credits 0 when refused at the sending)',
  `provider_id` varchar(100) NOT NULL DEFAULT '',
  `credits` int(10) unsigned NOT NULL DEFAULT 0,
  `error` varchar(255) NOT NULL DEFAULT '',
  `sent_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sms_status` (`xcore_sms_id`, `status`),
  KEY `provider_id` (`provider_id`),
  KEY `sent_at` (`sent_at`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_sms_credit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` date DEFAULT NULL,
  `credits` int(10) unsigned NOT NULL DEFAULT 0,
  `amount` decimal(10,2) DEFAULT NULL COMMENT 'excl. tax',
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `reference` varchar(255) NOT NULL DEFAULT '',
  `comment` varchar(255) NOT NULL DEFAULT '',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `xcore_mailinglist_subscriber` ADD COLUMN IF NOT EXISTS `mobile` varchar(30) NOT NULL DEFAULT '' AFTER `email`;
ALTER TABLE `xcore_blacklist` MODIFY `email` varchar(255) DEFAULT NULL;
ALTER TABLE `xcore_blacklist` ADD COLUMN IF NOT EXISTS `mobile` varchar(20) DEFAULT NULL COMMENT 'e.164' AFTER `email`;
ALTER TABLE `xcore_blacklist` ADD UNIQUE KEY IF NOT EXISTS `mobile` (`mobile`, `deleted_key`);

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'SMS', 'normal', 'sms', 'sms', '["list","add","edit","delete","settings","widget"]', 15, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = COALESCE((SELECT MIN(n.xcore_menu_id) FROM `xcore_plugin` n WHERE n.route_prefix_name = 'newsletter' AND n.deleted = 'no'), (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no'))
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'sms' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'SMS credits', 'normal', 'payments', 'sms-credit', '["list","add","delete"]', 16, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = COALESCE((SELECT MIN(n.xcore_menu_id) FROM `xcore_plugin` n WHERE n.route_prefix_name = 'newsletter' AND n.deleted = 'no'), (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no'))
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'sms-credit' AND x.deleted = 'no');

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `name`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT p.id, 'SMS credits', CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'SmsController::widgetCredits'), 'half', 'yes', 300,
	COALESCE((SELECT MAX(w.position) + 1 FROM `xcore_widget` w WHERE w.method = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'NewsletterController::widgetCredits') AND w.deleted = 'no'), 51), 'yes', NOW(), 'unee-migrate'
FROM `xcore_plugin` p
WHERE p.route_prefix_name = 'sms' AND p.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.method = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'SmsController::widgetCredits') AND x.deleted = 'no')
LIMIT 1;

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Communication', 'SMS sender', 'Sends the due SMS (one-off and campaigns) with the provider of the globals sms.*', 'vendor/h2lsoft/unee-core/core/cron/sms_sender.php', 'limit=500&max_seconds=240', '*', '*', '*', '*/5', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/sms_sender.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/sms_sender.php' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'SmsController::widgetCredits');
DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('sms', 'sms-credit') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('sms', 'sms-credit') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('sms', 'sms-credit') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('sms', 'sms-credit') AND `created_by` = 'unee-migrate';
ALTER TABLE `xcore_blacklist` DROP INDEX IF EXISTS `mobile`;
DELETE FROM `xcore_blacklist` WHERE `email` IS NULL;
ALTER TABLE `xcore_blacklist` DROP COLUMN IF EXISTS `mobile`;
ALTER TABLE `xcore_blacklist` MODIFY `email` varchar(255) NOT NULL;
ALTER TABLE `xcore_mailinglist_subscriber` DROP COLUMN IF EXISTS `mobile`;
DROP TABLE IF EXISTS `xcore_sms_credit`;
DROP TABLE IF EXISTS `xcore_sms_data`;
DROP TABLE IF EXISTS `xcore_sms`;
