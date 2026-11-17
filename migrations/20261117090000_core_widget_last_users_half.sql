-- widget Last active users: 2 columns (half) instead of full, unless changed by an admin

UPDATE `xcore_widget` SET `size` = 'half', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND `size` = 'full' AND `created_by` = 'unee-migrate' AND (`updated_by` IS NULL OR `updated_by` = 'unee-migrate')
AND `method` = CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'UserController::widgetLastActive');

-- @down

UPDATE `xcore_widget` SET `size` = 'full', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND `size` = 'half' AND `updated_by` = 'unee-migrate'
AND `method` = CONCAT('Plugin', CHAR(92), 'Core', CHAR(92), 'UserController::widgetLastActive');
