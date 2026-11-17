-- chatter under the records of a module (option chatter of xcore_plugin, off by default): messages to the followers and internal notes,
-- attachments, followers notified in-app (email when asked), activities stored in xcore_user_task (plugin + record_id), reminders by the cron

ALTER TABLE `xcore_plugin` ADD COLUMN IF NOT EXISTS `chatter` enum('yes','no') NOT NULL DEFAULT 'no' AFTER `versioning`;

CREATE TABLE IF NOT EXISTS `xcore_chatter_message` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plugin` varchar(100) NOT NULL DEFAULT '' COMMENT 'route_prefix_name of the module',
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `type` varchar(255) NOT NULL DEFAULT 'message' COMMENT 'message, note, activity',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `body` mediumtext NOT NULL DEFAULT '' COMMENT 'html, profile basic',
  `email` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'also sent by email to the followers',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `plugin` (`plugin`, `record_id`),
  KEY `xcore_user_id` (`xcore_user_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_chatter_attachment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_chatter_message_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL DEFAULT '',
  `path` varchar(255) NOT NULL DEFAULT '' COMMENT 'relative to private_dir',
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_chatter_message_id` (`xcore_chatter_message_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_chatter_follower` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plugin` varchar(100) NOT NULL DEFAULT '',
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  UNIQUE KEY `plugin` (`plugin`, `record_id`, `xcore_user_id`, `deleted_key`),
  KEY `xcore_user_id` (`xcore_user_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- activities of the chatter: tasks linked to a record, listed in My tasks
ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `plugin` varchar(100) NOT NULL DEFAULT '' COMMENT 'activity of the chatter: module of the record' AFTER `progress`;
ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `record_id` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `plugin`;
ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `activity` varchar(255) NOT NULL DEFAULT '' COMMENT 'call, email, meeting, todo, document' AFTER `record_id`;
ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `remind_email` enum('yes','no') NOT NULL DEFAULT 'no' AFTER `activity`;
ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `reminded_at` datetime DEFAULT NULL AFTER `remind_email`;
CREATE INDEX IF NOT EXISTS `plugin` ON `xcore_user_task` (`plugin`, `record_id`);

-- emails of the chatter
INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `variables`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.chatter.message', 'message of the chatter sent by email to the followers',
'[[firstname]] [[author]] [[record]] [[message]] [[url]]',
'', '[[record]]: new message of [[author]]',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">[[author]] wrote on [[record]]:</p>
<div style="margin:0 0 24px;padding:12px 16px;border-radius:8px;background:#f4f5f8;">[[message]]</div>
<p style="margin:0;"><a href="[[url]]">Open the record</a></p>',
'', '[[record]] : nouveau message de [[author]]',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">[[author]] a écrit sur [[record]] :</p>
<div style="margin:0 0 24px;padding:12px 16px;border-radius:8px;background:#f4f5f8;">[[message]]</div>
<p style="margin:0;"><a href="[[url]]">Ouvrir la fiche</a></p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.chatter.message' AND x.deleted = 'no');

INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `variables`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.chatter.activity', 'reminder of an activity of the chatter at its due date',
'[[firstname]] [[activity]] [[title]] [[record]] [[due_date]] [[url]]',
'', 'Activity due: [[title]]',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">Your activity [[activity]] <strong>[[title]]</strong> on [[record]] is due on [[due_date]].</p>
<p style="margin:0;"><a href="[[url]]">Open the record</a></p>',
'', 'Activité à faire : [[title]]',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">Votre activité [[activity]] <strong>[[title]]</strong> sur [[record]] est prévue le [[due_date]].</p>
<p style="margin:0;"><a href="[[url]]">Ouvrir la fiche</a></p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.chatter.activity' AND x.deleted = 'no');

-- cron task Chatter reminders: activities due, every 15 minutes
INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Communication', 'Chatter reminders', 'Reminds the activities of the chatter at their due date (in-app, email when asked)', 'vendor/h2lsoft/unee-core/core/cron/chatter_reminder.php', '', '*', '*', '*', '*/15', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/chatter_reminder.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/chatter_reminder.php' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_mail_template` WHERE `name` IN ('core.chatter.message', 'core.chatter.activity') AND `created_by` = 'unee-migrate';
DROP INDEX IF EXISTS `plugin` ON `xcore_user_task`;
ALTER TABLE `xcore_user_task` DROP COLUMN IF EXISTS `reminded_at`, DROP COLUMN IF EXISTS `remind_email`, DROP COLUMN IF EXISTS `activity`, DROP COLUMN IF EXISTS `record_id`, DROP COLUMN IF EXISTS `plugin`;
DROP TABLE IF EXISTS `xcore_chatter_follower`;
DROP TABLE IF EXISTS `xcore_chatter_attachment`;
DROP TABLE IF EXISTS `xcore_chatter_message`;
ALTER TABLE `xcore_plugin` DROP COLUMN IF EXISTS `chatter`;
