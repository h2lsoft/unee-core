-- dashboard of each user: the widgets he placed (xcore_widget allowed to him), in his order; size and type come from xcore_widget
-- no row = empty dashboard (message), a widget once per user

CREATE TABLE IF NOT EXISTS `xcore_user_widget` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_widget_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_user_id` (`xcore_user_id`, `position`),
  KEY `xcore_widget_id` (`xcore_widget_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down

DROP TABLE IF EXISTS `xcore_user_widget`;
