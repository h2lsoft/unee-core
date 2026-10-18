-- icon of the menu configuration (unless it was changed from the backend)

UPDATE `xcore_menu` SET `icon` = 'tune', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `name` = 'Configuration' AND `deleted` = 'no' AND `icon` = 'settings_suggest';
