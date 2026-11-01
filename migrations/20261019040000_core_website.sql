-- website modules (menu Website): pages, articles, blocks, patterns, page templates and zones, galleries, sliders (tables of the base schema)

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Pages', 'normal', 'description', 'page', '["list","add","edit","delete"]', 10, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'page' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Articles', 'normal', 'newspaper', 'article', '["list","add","edit","delete"]', 20, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'article' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Blocks', 'normal', 'widgets', 'block', '["list","add","edit","delete"]', 30, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'block' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Patterns', 'normal', 'code_blocks', 'pattern', '["list","add","edit","delete"]', 40, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'pattern' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Page templates', 'normal', 'dashboard', 'page-template', '["list","add","edit","delete"]', 50, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'page-template' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Page zones', 'normal', 'view_quilt', 'page-zone', '["list","add","edit","delete"]', 60, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'page-zone' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Galleries', 'normal', 'photo_library', 'gallery', '["list","add","edit","delete"]', 70, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'gallery' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Gallery images', 'normal', 'image', 'gallery-card', '["list","add","edit","delete"]', 75, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'gallery-card' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Sliders', 'normal', 'view_carousel', 'slider', '["list","add","edit","delete"]', 80, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'slider' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Slides', 'normal', 'slideshow', 'slider-card', '["list","add","edit","delete"]', 85, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Website' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'slider-card' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('page', 'article', 'block', 'pattern', 'page-template', 'page-zone', 'gallery', 'gallery-card', 'slider', 'slider-card') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('page', 'article', 'block', 'pattern', 'page-template', 'page-zone', 'gallery', 'gallery-card', 'slider', 'slider-card') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('page', 'article', 'block', 'pattern', 'page-template', 'page-zone', 'gallery', 'gallery-card', 'slider', 'slider-card') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('page', 'article', 'block', 'pattern', 'page-template', 'page-zone', 'gallery', 'gallery-card', 'slider', 'slider-card') AND `created_by` = 'unee-migrate';
