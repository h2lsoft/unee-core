-- dedicated right widget: a widget is shown with the widget right on its plugin, no longer with the list right
-- the plugins holding widgets offer the action widget, the groups with the list right on them keep their widgets

UPDATE `xcore_plugin` p
SET p.`actions` = JSON_ARRAY_APPEND(p.`actions`, '$', 'widget'), p.`updated_at` = NOW(), p.`updated_by` = 'unee-migrate'
WHERE p.`deleted` = 'no' AND p.`type` != 'url' AND JSON_VALID(p.`actions`) AND NOT JSON_CONTAINS(p.`actions`, '"widget"')
AND p.`id` IN (SELECT w.`xcore_plugin_id` FROM `xcore_widget` w WHERE w.`deleted` = 'no' AND w.`xcore_plugin_id` > 0);

INSERT INTO `xcore_group_right` (`xcore_group_id`, `xcore_plugin_id`, `action`, `created_at`, `created_by`)
SELECT DISTINCT r.`xcore_group_id`, r.`xcore_plugin_id`, 'widget', NOW(), 'unee-migrate'
FROM `xcore_group_right` r
WHERE r.`deleted` = 'no' AND r.`action` = 'list'
AND r.`xcore_plugin_id` IN (SELECT w.`xcore_plugin_id` FROM `xcore_widget` w WHERE w.`deleted` = 'no' AND w.`xcore_plugin_id` > 0)
AND NOT EXISTS (SELECT 1 FROM `xcore_group_right` x WHERE x.`deleted` = 'no' AND x.`xcore_group_id` = r.`xcore_group_id` AND x.`xcore_plugin_id` = r.`xcore_plugin_id` AND x.`action` = 'widget');

-- @down

DELETE FROM `xcore_group_right` WHERE `action` = 'widget' AND `created_by` = 'unee-migrate';

UPDATE `xcore_plugin`
SET `actions` = JSON_REMOVE(`actions`, JSON_UNQUOTE(JSON_SEARCH(`actions`, 'one', 'widget'))), `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `deleted` = 'no' AND JSON_VALID(`actions`) AND JSON_SEARCH(`actions`, 'one', 'widget') IS NOT NULL;
