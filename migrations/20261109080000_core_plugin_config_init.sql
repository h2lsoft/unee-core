-- categories of configuration of the core modules whose globals are not named after their route (button Configuration of the module)
-- newsletter: its smtp falls back on mailer.smtp.*, mail-template: mails of the application (mailer.smtp.*), user: phone.mask of the phone fields, page: frontend.langs

UPDATE `xcore_plugin` SET `config_categories` = '["newsletter","mailer"]' WHERE `route_prefix_name` = 'newsletter' AND (`config_categories` IS NULL OR `config_categories` IN ('', '[]'));
UPDATE `xcore_plugin` SET `config_categories` = '["mailer"]' WHERE `route_prefix_name` = 'mail-template' AND (`config_categories` IS NULL OR `config_categories` IN ('', '[]'));
UPDATE `xcore_plugin` SET `config_categories` = '["phone"]' WHERE `route_prefix_name` = 'user' AND (`config_categories` IS NULL OR `config_categories` IN ('', '[]'));
UPDATE `xcore_plugin` SET `config_categories` = '["frontend"]' WHERE `route_prefix_name` = 'page' AND (`config_categories` IS NULL OR `config_categories` IN ('', '[]'));

-- @down

UPDATE `xcore_plugin` SET `config_categories` = NULL WHERE `route_prefix_name` = 'newsletter' AND `config_categories` = '["newsletter","mailer"]';
UPDATE `xcore_plugin` SET `config_categories` = NULL WHERE `route_prefix_name` = 'mail-template' AND `config_categories` = '["mailer"]';
UPDATE `xcore_plugin` SET `config_categories` = NULL WHERE `route_prefix_name` = 'user' AND `config_categories` = '["phone"]';
UPDATE `xcore_plugin` SET `config_categories` = NULL WHERE `route_prefix_name` = 'page' AND `config_categories` = '["frontend"]';
