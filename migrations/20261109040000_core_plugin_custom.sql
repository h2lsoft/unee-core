-- studio: a custom plugin is coded by a developer (own page, meta module) and never opened in the studio
-- the studio type of a plugin: url, custom (custom = yes), crud (the others); type core stays the flag every backend user gets every action

ALTER TABLE `xcore_plugin` ADD COLUMN IF NOT EXISTS `custom` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'coded by a developer, never opened in the studio' AFTER `type`;

UPDATE `xcore_plugin` SET `custom` = 'yes'
WHERE `route_prefix_name` IN ('right', 'system-info', 'calendar', 'project', 'plugin') AND `deleted` = 'no';

-- @down

ALTER TABLE `xcore_plugin` DROP COLUMN IF EXISTS `custom`;
