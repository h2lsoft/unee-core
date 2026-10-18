-- personal tasks of the users (module user-task, opened from the user menu: core plugin, not visible in the menu)

CREATE TABLE IF NOT EXISTS `xcore_user_task` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL,
  `category` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `description` text NOT NULL DEFAULT '',
  `priority` varchar(50) NOT NULL DEFAULT 'medium' COMMENT 'low, medium, high',
  `due_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending' COMMENT 'pending, in progress, done',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `status` (`status`),
  KEY `priority` (`priority`),
  KEY `due_date` (`due_date`),
  KEY `category` (`category`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'My tasks', 'core', 'task_alt', 'user-task', '["list","add","edit","delete"]', 90, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Tools' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'user-task' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;
