-- dashboard: personal notes as a widget without plugin (shown to every user), first position (backslashes written CHAR(92))

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `name`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT 0, 'My notes', w.method, 'full', 'no', 60, 0, 'yes', NOW(), 'unee-migrate'
FROM (SELECT CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetNotes') AS method) w
WHERE NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.method = w.method AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_widget` WHERE `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetNotes') AND `created_by` = 'unee-migrate';
