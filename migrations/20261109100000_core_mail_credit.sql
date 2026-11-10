-- email credits: every email sent is counted (xcore_mail_sent + the newsletters of xcore_newsletter_data), globals mail.credits.* replace newsletter.credits.*
-- widget Newsletter credits renamed Email credits, globals mail.* shown by the button Configuration of newsletters and mail templates

CREATE TABLE IF NOT EXISTS `xcore_mail_sent` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `application` varchar(20) NOT NULL DEFAULT '' COMMENT 'backend, frontend, api, cron or cli',
  `module` varchar(255) NOT NULL DEFAULT '',
  `action` varchar(255) NOT NULL DEFAULT '',
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `category` varchar(20) NOT NULL DEFAULT 'transactional' COMMENT 'transactional or marketing',
  `template` varchar(255) NOT NULL DEFAULT '' COMMENT 'name of the mail template, empty = mail without template',
  `subject` varchar(255) NOT NULL DEFAULT '',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'recipient when he is a user',
  `status` varchar(20) NOT NULL DEFAULT 'sent' COMMENT 'sent (1 credit), failed (smtp error), refused (no credits left)',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `module` (`module`),
  KEY `record_id` (`record_id`),
  KEY `template` (`template`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- globals: values of the newsletter credits kept
INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'mail', 'mail.credits.monthly', 'int', 'emails sent a month (every email: newsletters, sign-in codes, notifications...), 0 = unlimited',
	COALESCE((SELECT g.value FROM `xcore_globals` g WHERE g.name = 'newsletter.credits.monthly' AND g.deleted = 'no' LIMIT 1), '100000'), NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'mail.credits.monthly' AND x.deleted = 'no');

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'mail', 'mail.credits.reset_day', 'int', 'day of the month the email credits are reset (1-28)',
	COALESCE((SELECT g.value FROM `xcore_globals` g WHERE g.name = 'newsletter.credits.reset_day' AND g.deleted = 'no' LIMIT 1), '1'), NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'mail.credits.reset_day' AND x.deleted = 'no');

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'mail', 'mail.credits.reserve', 'int', 'percent of the monthly credits kept for the transactional emails (sign-in codes, passwords...), never used by the newsletters', '2', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'mail.credits.reserve' AND x.deleted = 'no');

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'mail', 'mail.alert.below', 'int', 'the super admins are notified once a period when the email credits left go below, 0 = never', '1000', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'mail.alert.below' AND x.deleted = 'no');

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'mail', 'mail.alert.key', 'text', 'set by the code: period of the last alerts of the email credits', '', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'mail.alert.key' AND x.deleted = 'no');

UPDATE `xcore_globals` SET `deleted` = 'yes', `deleted_at` = NOW(), `deleted_by` = 'unee-migrate'
WHERE `name` IN ('newsletter.credits.monthly', 'newsletter.credits.reset_day') AND `deleted` = 'no';

-- widget and configuration categories (left alone when changed by hand)
UPDATE `xcore_widget` SET `name` = 'Email credits'
WHERE `method` = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'NewsletterController::widgetCredits') AND `name` = 'Newsletter credits' AND `deleted` = 'no';

UPDATE `xcore_plugin` SET `config_categories` = '["newsletter","mail","mailer"]' WHERE `route_prefix_name` = 'newsletter' AND `config_categories` = '["newsletter","mailer"]';
UPDATE `xcore_plugin` SET `config_categories` = '["mail","mailer"]' WHERE `route_prefix_name` = 'mail-template' AND `config_categories` = '["mailer"]';

-- @down

UPDATE `xcore_plugin` SET `config_categories` = '["mailer"]' WHERE `route_prefix_name` = 'mail-template' AND `config_categories` = '["mail","mailer"]';
UPDATE `xcore_plugin` SET `config_categories` = '["newsletter","mailer"]' WHERE `route_prefix_name` = 'newsletter' AND `config_categories` = '["newsletter","mail","mailer"]';

UPDATE `xcore_widget` SET `name` = 'Newsletter credits'
WHERE `method` = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'NewsletterController::widgetCredits') AND `name` = 'Email credits' AND `deleted` = 'no';

-- newsletter credits back with the current values
UPDATE `xcore_globals` n
INNER JOIN `xcore_globals` m ON m.name = REPLACE(n.name, 'newsletter.', 'mail.') AND m.deleted = 'no'
SET n.value = m.value
WHERE n.name IN ('newsletter.credits.monthly', 'newsletter.credits.reset_day') AND n.deleted = 'yes' AND n.deleted_by = 'unee-migrate';

DELETE FROM `xcore_globals` WHERE `name` IN ('mail.credits.monthly', 'mail.credits.reset_day', 'mail.credits.reserve', 'mail.alert.below', 'mail.alert.key');

UPDATE `xcore_globals` SET `deleted` = 'no', `deleted_at` = NULL, `deleted_by` = NULL
WHERE `name` IN ('newsletter.credits.monthly', 'newsletter.credits.reset_day') AND `deleted` = 'yes' AND `deleted_by` = 'unee-migrate';

DROP TABLE IF EXISTS `xcore_mail_sent`;
