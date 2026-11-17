-- widgets: own icon (empty = icon of the plugin), allowed several times on a dashboard (multiple), configurable by each user (configurable + config_fields json)
-- config_fields: [{"name", "label", "type" (text, number, select, boolean), "default", "options" (select)}]
-- dashboard: settings of each placed widget (config json), a placed widget is identified by its row id

ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `icon` varchar(64) NOT NULL DEFAULT '' AFTER `name`;
ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `multiple` enum('yes','no') NOT NULL DEFAULT 'no' AFTER `access_all`;
ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `configurable` enum('yes','no') NOT NULL DEFAULT 'no' AFTER `multiple`;
ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `config_fields` text NULL AFTER `configurable`;
ALTER TABLE `xcore_user_widget` ADD COLUMN IF NOT EXISTS `config` text NULL AFTER `position`;

UPDATE `xcore_widget` SET `icon` = 'schedule', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `icon` = '' AND `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock');

UPDATE `xcore_widget` SET `icon` = 'sticky_note_2', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `icon` = '' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetNotes');

UPDATE `xcore_widget` SET `icon` = 'partly_cloudy_day', `multiple` = 'yes', `configurable` = 'yes',
`config_fields` = '[{"name": "city", "label": "City", "type": "text", "default": "Paris"}, {"name": "latitude", "label": "Latitude", "type": "number", "default": "48.8566"}, {"name": "longitude", "label": "Longitude", "type": "number", "default": "2.3522"}]',
`updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `created_by` = 'unee-migrate' AND `config_fields` IS NULL AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather');

-- @down

ALTER TABLE `xcore_user_widget` DROP COLUMN IF EXISTS `config`;
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `config_fields`;
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `configurable`;
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `multiple`;
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `icon`;
