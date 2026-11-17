-- settings of the core widgets: Weather = a city (found by the geocoder), Clock = time zone, format, seconds, date (multiple), Last active users = application (multiple)
-- not changed when an admin changed the widget

UPDATE `xcore_widget` SET `config_fields` = '[{"name": "city", "label": "City", "type": "text", "default": "Paris"}]', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND `created_by` = 'unee-migrate' AND (`updated_by` IS NULL OR `updated_by` = 'unee-migrate')
AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather');

UPDATE `xcore_widget` SET `multiple` = 'yes', `configurable` = 'yes',
`config_fields` = '[{"name": "timezone", "label": "Time zone", "type": "select", "default": "browser", "options": {"browser": "Time of the computer", "Europe/Paris": "Paris", "Europe/London": "London", "Europe/Berlin": "Berlin", "Europe/Moscow": "Moscow", "America/New_York": "New York", "America/Chicago": "Chicago", "America/Los_Angeles": "Los Angeles", "America/Montreal": "Montreal", "America/Sao_Paulo": "Sao Paulo", "America/Martinique": "Martinique / Guadeloupe", "America/Cayenne": "Guyane", "Indian/Reunion": "La Reunion", "Indian/Mayotte": "Mayotte", "Pacific/Noumea": "Noumea", "Pacific/Tahiti": "Tahiti", "Africa/Casablanca": "Casablanca", "Africa/Dakar": "Dakar", "Asia/Dubai": "Dubai", "Asia/Jerusalem": "Jerusalem", "Asia/Shanghai": "Shanghai", "Asia/Tokyo": "Tokyo", "Australia/Sydney": "Sydney", "UTC": "UTC"}}, {"name": "format", "label": "Format", "type": "select", "default": "24", "options": {"24": "24 hours", "12": "12 hours (AM / PM)"}}, {"name": "seconds", "label": "Seconds", "type": "boolean", "default": "yes"}, {"name": "date", "label": "Date", "type": "boolean", "default": "yes"}]',
`updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND `created_by` = 'unee-migrate' AND (`updated_by` IS NULL OR `updated_by` = 'unee-migrate')
AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock');

UPDATE `xcore_widget` SET `multiple` = 'yes', `configurable` = 'yes',
`config_fields` = '[{"name": "application", "label": "Application", "type": "select", "default": "both", "options": {"both": "Backend and frontend", "backend": "Backend", "frontend": "Frontend"}}]',
`updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND `created_by` = 'unee-migrate' AND (`updated_by` IS NULL OR `updated_by` = 'unee-migrate')
AND `method` = CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'UserController::widgetLastActive');

-- @down

UPDATE `xcore_widget` SET `multiple` = 'no', `configurable` = 'no', `config_fields` = NULL, `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `updated_by` = 'unee-migrate' AND `method` IN (CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetClock'), CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'UserController::widgetLastActive'));

UPDATE `xcore_widget` SET `config_fields` = '[{"name": "city", "label": "City", "type": "text", "default": "Paris"}, {"name": "latitude", "label": "Latitude", "type": "number", "default": "48.8566"}, {"name": "longitude", "label": "Longitude", "type": "number", "default": "2.3522"}]', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `updated_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Core_Backend', CHAR(92), 'DashboardController::widgetWeather');
