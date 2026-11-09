-- api documentation (swagger ui of /api/openapi.json, superadmin only) in the menu administration, opened in a new tab

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `url`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'API documentation', 'url', 'api', 'api-docs', '["list"]', '/api/docs/', 190, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.name = 'Administration' AND m.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` WHERE `route_prefix_name` = 'api-docs' AND `deleted` = 'no')
ORDER BY m.id
LIMIT 1;

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('api-docs') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('api-docs') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('api-docs') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('api-docs') AND `created_by` = 'unee-migrate';
