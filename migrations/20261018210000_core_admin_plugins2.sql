-- administration modules: users, rights, layouts, mail templates, logs
-- layouts: description, mail templates: variables declared by the code (shown as help), defaults for the columns not in the forms

ALTER TABLE `xcore_layout` ADD COLUMN IF NOT EXISTS `description` varchar(255) NOT NULL DEFAULT '' AFTER `name`;
ALTER TABLE `xcore_mail_template` ADD COLUMN IF NOT EXISTS `variables` text NOT NULL DEFAULT '' AFTER `description`;
ALTER TABLE `xcore_mail_template` MODIFY `sender_en` varchar(255) NOT NULL DEFAULT '', MODIFY `sender_fr` varchar(255) NOT NULL DEFAULT '';
ALTER TABLE `xcore_user` MODIFY `avatar` text NOT NULL DEFAULT '', MODIFY `company` varchar(50) NOT NULL DEFAULT '', MODIFY `bio` longtext NOT NULL DEFAULT '', MODIFY `note` text NOT NULL DEFAULT '';

UPDATE `xcore_mail_template` SET `variables` = '[[firstname]] [[link]] [[hours]]' WHERE `name` = 'core.password.forgot' AND `variables` = '';
UPDATE `xcore_mail_template` SET `variables` = '[[firstname]] [[date]] [[ip]]' WHERE `name` = 'core.password.changed' AND `variables` = '';

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Users', 'normal', 'person', 'user', '["list","add","edit","delete","connect_as"]', 60, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'user' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Rights', 'normal', 'admin_panel_settings', 'right', '["list","edit"]', 70, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'right' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Layouts', 'normal', 'web', 'layout', '["list","add","edit","delete"]', 90, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'layout' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Mail templates', 'normal', 'mail', 'mail-template', '["list","add","edit","delete"]', 110, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'mail-template' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Logs', 'normal', 'receipt_long', 'log', '["list","purge"]', 120, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'log' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('user', 'right', 'layout', 'mail-template', 'log') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('user', 'right', 'layout', 'mail-template', 'log') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('user', 'right', 'layout', 'mail-template', 'log') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('user', 'right', 'layout', 'mail-template', 'log') AND `created_by` = 'unee-migrate';
ALTER TABLE `xcore_user` MODIFY `avatar` text NOT NULL, MODIFY `company` varchar(50) NOT NULL, MODIFY `bio` longtext NOT NULL, MODIFY `note` text NOT NULL;
ALTER TABLE `xcore_mail_template` DROP COLUMN IF EXISTS `variables`, MODIFY `sender_en` varchar(255) NOT NULL, MODIFY `sender_fr` varchar(255) NOT NULL;
ALTER TABLE `xcore_layout` DROP COLUMN IF EXISTS `description`;
