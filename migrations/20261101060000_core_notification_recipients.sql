-- notifications: one message (category, target groups or users) and one row per recipient (read_at), test notifications purged

DELETE FROM `xcore_notification`;

ALTER TABLE `xcore_notification` DROP INDEX IF EXISTS `user_read`;
ALTER TABLE `xcore_notification` DROP COLUMN IF EXISTS `xcore_user_id`;
ALTER TABLE `xcore_notification` DROP COLUMN IF EXISTS `read_at`;
ALTER TABLE `xcore_notification` ADD COLUMN IF NOT EXISTS `category` varchar(100) NOT NULL DEFAULT '' AFTER `id`;
ALTER TABLE `xcore_notification` ADD COLUMN IF NOT EXISTS `target_type` varchar(10) NOT NULL DEFAULT 'user' AFTER `link`;
ALTER TABLE `xcore_notification` ADD COLUMN IF NOT EXISTS `target_ids` text DEFAULT NULL COMMENT 'json array of group or user ids' AFTER `target_type`;

CREATE TABLE IF NOT EXISTS `xcore_notification_recipient` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_notification_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
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
  KEY `xcore_notification_id` (`xcore_notification_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down

DROP TABLE IF EXISTS `xcore_notification_recipient`;

DELETE FROM `xcore_notification`;
ALTER TABLE `xcore_notification` DROP COLUMN IF EXISTS `target_ids`;
ALTER TABLE `xcore_notification` DROP COLUMN IF EXISTS `target_type`;
ALTER TABLE `xcore_notification` DROP COLUMN IF EXISTS `category`;
ALTER TABLE `xcore_notification` ADD COLUMN IF NOT EXISTS `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `id`;
ALTER TABLE `xcore_notification` ADD COLUMN IF NOT EXISTS `read_at` datetime DEFAULT NULL AFTER `link`;
ALTER TABLE `xcore_notification` ADD INDEX IF NOT EXISTS `user_read` (`xcore_user_id`, `read_at`);
