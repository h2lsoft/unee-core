-- chatter: last time each user read the thread of a record (double check "read" on the messages, unread count of the button Thread)
CREATE TABLE IF NOT EXISTS `xcore_chatter_read` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plugin` varchar(100) NOT NULL DEFAULT '',
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
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
  UNIQUE KEY `plugin` (`plugin`, `record_id`, `xcore_user_id`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down

DROP TABLE IF EXISTS `xcore_chatter_read`;
