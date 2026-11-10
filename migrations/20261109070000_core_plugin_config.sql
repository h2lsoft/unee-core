-- categories of xcore_globals of a module (module Plugins, field Configuration categories): the button Configuration of the module opens Globals filtered on them, empty = the route of the module

ALTER TABLE `xcore_plugin` ADD COLUMN IF NOT EXISTS `config_categories` text DEFAULT NULL COMMENT 'json array of categories of xcore_globals, empty = route_prefix_name' AFTER `actions`;

-- @down

ALTER TABLE `xcore_plugin` DROP COLUMN IF EXISTS `config_categories`;
