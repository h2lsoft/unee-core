-- global search of the backend (GET /api/backend/search/): every module with a search box is searched, unless its field Global search (module Plugins) is off

ALTER TABLE `xcore_plugin` ADD COLUMN IF NOT EXISTS `global_search` enum('yes','no') NOT NULL DEFAULT 'yes' AFTER `config_categories`;

-- @down

ALTER TABLE `xcore_plugin` DROP COLUMN IF EXISTS `global_search`;
