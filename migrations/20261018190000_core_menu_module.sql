-- menu categories: color (#rrggbb) of the category icon, module Menu in Administration

ALTER TABLE `xcore_menu` ADD COLUMN IF NOT EXISTS `color` varchar(20) NOT NULL DEFAULT '' AFTER `icon`;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Menu', 'normal', 'menu', 'menu', '["list","add","edit","delete"]', 10, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'menu' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('menu') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('menu') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('menu') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('menu') AND `created_by` = 'unee-migrate';
ALTER TABLE `xcore_menu` DROP COLUMN IF EXISTS `color`;
