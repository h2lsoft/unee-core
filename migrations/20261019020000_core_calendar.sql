-- calendar (header icon): events of the users, plugin hidden from the menus

CREATE TABLE IF NOT EXISTS `xcore_agenda` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `all_day` enum('yes','no') NOT NULL DEFAULT 'no',
  `hour_start` varchar(5) NOT NULL DEFAULT '',
  `hour_end` varchar(5) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `comment` text DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'purple',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `date` (`date`),
  KEY `xcore_user_id` (`xcore_user_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='calendar events';

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Calendar', 'normal', 'calendar_month', 'calendar', '["list","add","edit","delete"]', 95, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Tools' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'calendar' AND x.deleted = 'no');
