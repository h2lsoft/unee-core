-- projects (menu tools): projects, steps of the board, tasks, attachments of the tasks; widget projects of the dashboard

CREATE TABLE IF NOT EXISTS `xcore_project` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'responsible',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `budget` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date_start` date DEFAULT NULL,
  `date_end` date DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#6d4aff',
  `note` text DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `status` (`status`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='projects';

CREATE TABLE IF NOT EXISTS `xcore_project_step` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_project_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'grey',
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `is_final` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_project_id` (`xcore_project_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='steps (columns) of a project board';

CREATE TABLE IF NOT EXISTS `xcore_project_task` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_project_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_project_step_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'assigned to',
  `depends_on_task_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `category` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `progress` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `date_start` date DEFAULT NULL,
  `date_end` date DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_project_id` (`xcore_project_id`),
  KEY `xcore_project_step_id` (`xcore_project_step_id`),
  KEY `depends_on_task_id` (`depends_on_task_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='tasks of the projects';

CREATE TABLE IF NOT EXISTS `xcore_project_task_attachment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_project_task_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL COMMENT 'original file name',
  `path` varchar(500) NOT NULL COMMENT 'relative to config private_dir',
  `mime` varchar(100) NOT NULL DEFAULT '',
  `size` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_project_task_id` (`xcore_project_task_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='files of the project tasks';

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Projects', 'normal', 'account_tree', 'project', '["list","add","edit","delete"]', 60, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Tools' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'project' AND x.deleted = 'no');

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `name`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT p.id, 'Projects', CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'ProjectController::widgetProjects'), 'full', 'no', 60, 20, 'yes', NOW(), 'unee-migrate'
FROM `xcore_plugin` p
WHERE p.route_prefix_name = 'project' AND p.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.method LIKE '%ProjectController::widgetProjects' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('project') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('project') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('project') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('project') AND `created_by` = 'unee-migrate';
DROP TABLE IF EXISTS `xcore_project_task_attachment`;
DROP TABLE IF EXISTS `xcore_project_task`;
DROP TABLE IF EXISTS `xcore_project_step`;
DROP TABLE IF EXISTS `xcore_project`;
