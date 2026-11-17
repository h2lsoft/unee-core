-- widget option access all: shown to every user, even without the widget right on its plugin
-- widget Weather (MET Norway forecast of the globals widget.weather.*), access all, 1 column

ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `access_all` enum('yes','no') NOT NULL DEFAULT 'no' AFTER `xcore_plugin_id`;

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `access_all`, `name`, `type`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT 0, 'yes', 'Weather', 'method', CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather'), 'quarter', 'yes', 1800, 5, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.`method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather') AND x.`deleted` = 'no');

-- @down

DELETE FROM `xcore_user_widget` WHERE `xcore_widget_id` IN (SELECT `id` FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather'));
DELETE FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather');
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `access_all`;
