-- menu categories: color (#rrggbb) of the category icon, module Menu in Administration

ALTER TABLE `xcore_menu` ADD COLUMN IF NOT EXISTS `color` varchar(20) NOT NULL DEFAULT '' AFTER `icon`;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Menu', 'normal', 'menu', 'menu', '["list","add","edit","delete"]', 10, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'menu' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;
