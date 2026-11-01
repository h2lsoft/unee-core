-- in-app notifications (bell of the header): per user, read or not, purge task; jobs can notify the user who queued them

CREATE TABLE IF NOT EXISTS `xcore_notification` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_read` (`xcore_user_id`, `read_at`),
  KEY `deleted` (`deleted`),
  KEY `created_at` (`created_at`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `xcore_job_queue` ADD COLUMN IF NOT EXISTS `notify_user_id` bigint(20) unsigned NOT NULL DEFAULT 0;

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'System', 'Notifications purge', 'Deletes the notifications read for more than n days', 'vendor/h2lsoft/unee-core/core/cron/notification_purge.php', 'days=90', '*', '*', '3', '15', '', 'idle', '', 50, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/notification_purge.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/notification_purge.php' AND `created_by` = 'unee-migrate';

ALTER TABLE `xcore_job_queue` DROP COLUMN IF EXISTS `notify_user_id`;

DROP TABLE IF EXISTS `xcore_notification`;
