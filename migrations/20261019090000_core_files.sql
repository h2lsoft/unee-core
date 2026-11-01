-- files to download (menu Website): restriction by groups, download counter, files stored in private_dir/files

ALTER TABLE `xcore_files`
  ADD `xcore_group_ids` text NULL AFTER `source`,
  ADD `downloads` int(10) unsigned NOT NULL DEFAULT 0 AFTER `xcore_group_ids`;

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Files', 'normal', 'folder_open', 'files', '["list","add","edit","delete"]', 90, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'files' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('files') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('files') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('files') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('files') AND `created_by` = 'unee-migrate';
ALTER TABLE `xcore_files` DROP COLUMN IF EXISTS `downloads`, DROP COLUMN IF EXISTS `xcore_group_ids`;
