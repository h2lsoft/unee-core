-- backend menu: category Administration and its first module, Globals (rights: list, add, edit, delete)

INSERT INTO `xcore_menu` (`name`, `icon`, `position`, `created_at`, `created_by`)
SELECT 'Administration', 'settings', 100, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'Administration' AND `deleted` = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Globals', 'normal', 'tune', 'globals', '["list","add","edit","delete"]', 30, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'globals' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;
