-- administration modules: plugins, groups, lists, url redirects
-- lists: badge color of each value, url redirects: description of the rule

ALTER TABLE `xcore_list` ADD COLUMN IF NOT EXISTS `color` varchar(20) NOT NULL DEFAULT 'grey' AFTER `style`;
ALTER TABLE `xcore_list` MODIFY `icon` varchar(255) NOT NULL DEFAULT '', MODIFY `class` varchar(255) NOT NULL DEFAULT '', MODIFY `style` varchar(255) NOT NULL DEFAULT '';
ALTER TABLE `xcore_url_redirect` ADD COLUMN IF NOT EXISTS `description` varchar(255) NOT NULL DEFAULT '' AFTER `url`;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Plugins', 'normal', 'extension', 'plugin', '["list","add","edit","delete"]', 40, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'plugin' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Groups', 'normal', 'groups', 'group', '["list","add","edit","delete"]', 50, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'group' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Lists', 'normal', 'list_alt', 'list', '["list","add","edit","delete"]', 80, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'list' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Url redirects', 'normal', 'alt_route', 'url-redirect', '["list","add","edit","delete"]', 100, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'url-redirect' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('plugin', 'group', 'list', 'url-redirect') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('plugin', 'group', 'list', 'url-redirect') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('plugin', 'group', 'list', 'url-redirect') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('plugin', 'group', 'list', 'url-redirect') AND `created_by` = 'unee-migrate';
ALTER TABLE `xcore_url_redirect` DROP COLUMN IF EXISTS `description`;
ALTER TABLE `xcore_list` DROP COLUMN IF EXISTS `color`, MODIFY `icon` varchar(255) NOT NULL, MODIFY `class` varchar(255) NOT NULL, MODIFY `style` varchar(255) NOT NULL;
