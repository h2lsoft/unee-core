-- menu categories: configuration, administration, crm, website, communication, tools
-- administration (core_menu_globals) takes its place in the order unless it was moved, and a color when it has none

UPDATE `xcore_menu` SET `position` = 20, `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `name` = 'Administration' AND `deleted` = 'no' AND `position` = 100;

UPDATE `xcore_menu` SET `color` = '#ee82ee', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `name` = 'Administration' AND `deleted` = 'no' AND `color` = '';

INSERT INTO `xcore_menu` (`name`, `icon`, `color`, `position`, `created_at`, `created_by`)
SELECT 'Configuration', 'settings_suggest', '#000000', 10, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'Configuration' AND `deleted` = 'no');

INSERT INTO `xcore_menu` (`name`, `icon`, `color`, `position`, `created_at`, `created_by`)
SELECT 'CRM', 'groups', '#00c832', 30, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'CRM' AND `deleted` = 'no');

INSERT INTO `xcore_menu` (`name`, `icon`, `color`, `position`, `created_at`, `created_by`)
SELECT 'Website', 'language', '#4169e1', 40, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'Website' AND `deleted` = 'no');

INSERT INTO `xcore_menu` (`name`, `icon`, `color`, `position`, `created_at`, `created_by`)
SELECT 'Communication', 'rss_feed', '#ff0000', 50, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'Communication' AND `deleted` = 'no');

INSERT INTO `xcore_menu` (`name`, `icon`, `color`, `position`, `created_at`, `created_by`)
SELECT 'Tools', 'handyman', '#ffa500', 60, NOW(), 'unee-migrate'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `xcore_menu` WHERE `name` = 'Tools' AND `deleted` = 'no');

-- @down

DELETE FROM `xcore_menu` WHERE `name` IN ('Configuration', 'CRM', 'Website', 'Communication', 'Tools') AND `created_by` = 'unee-migrate';
UPDATE `xcore_menu` SET `color` = '' WHERE `name` = 'Administration' AND `deleted` = 'no' AND `color` = '#ee82ee' AND `updated_by` = 'unee-migrate';
UPDATE `xcore_menu` SET `position` = 100 WHERE `name` = 'Administration' AND `deleted` = 'no' AND `position` = 20 AND `updated_by` = 'unee-migrate';
