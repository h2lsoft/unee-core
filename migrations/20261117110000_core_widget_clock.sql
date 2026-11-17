-- widget Clock (date and time of the browser), drawn by the react dashboard (type clock, no data call), 1 column, every user

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `access_all`, `name`, `type`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT 0, 'yes', 'Clock', 'method', CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock'), 'quarter', 'no', 60, 1, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.`method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock') AND x.`deleted` = 'no');

-- @down

DELETE FROM `xcore_user_widget` WHERE `xcore_widget_id` IN (SELECT `id` FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock'));
DELETE FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock');
