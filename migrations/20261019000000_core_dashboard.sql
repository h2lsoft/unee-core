-- dashboard (backslashes written CHAR(92): the sql_mode NO_BACKSLASH_ESCAPES differs between servers): widget size, plugin widgets (configuration), widgets my tasks, cron, jobber, last active users

ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `size` enum('full','half') NOT NULL DEFAULT 'full' AFTER `method`;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Widgets', 'normal', 'dashboard_customize', 'widget', '["list","add","edit","delete"]', 50, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Configuration' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'widget' AND x.deleted = 'no');

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `name`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT p.id, w.name, w.method, w.size, w.autorefresh, w.seconds, w.position, 'yes', NOW(), 'unee-migrate'
FROM (
	SELECT 'user-task' AS route, 'My tasks' AS name, CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'User_TaskController::widgetMyTasks') AS method, 'full' AS size, 'no' AS autorefresh, 60 AS seconds, 10 AS position
	UNION ALL SELECT 'cron', 'Cron', CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'CronController::widgetCron'), 'half', 'yes', 60, 30
	UNION ALL SELECT 'jobber', 'Jobber', CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'JobberController::widgetJobber'), 'half', 'yes', 30, 40
	UNION ALL SELECT 'user', 'Last active users', CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'UserController::widgetLastActive'), 'full', 'yes', 60, 100
) w
JOIN `xcore_plugin` p ON p.route_prefix_name = w.route AND p.deleted = 'no'
WHERE NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.method = w.method AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` IN (
	CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'User_TaskController::widgetMyTasks'),
	CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'CronController::widgetCron'),
	CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'JobberController::widgetJobber'),
	CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'UserController::widgetLastActive')
);
DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('widget') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('widget') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('widget') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('widget') AND `created_by` = 'unee-migrate';
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `size`;
