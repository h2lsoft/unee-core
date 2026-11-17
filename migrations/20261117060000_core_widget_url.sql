-- url widgets: a page shown in an iframe of the dashboard (https only), height in px

ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `type` enum('method','url') NOT NULL DEFAULT 'method' AFTER `name`;
ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `url` varchar(2048) NOT NULL DEFAULT '' AFTER `method`;
ALTER TABLE `xcore_widget` ADD COLUMN IF NOT EXISTS `url_height` smallint(5) unsigned NOT NULL DEFAULT 300 AFTER `url`;
ALTER TABLE `xcore_widget` MODIFY `method` varchar(255) NOT NULL DEFAULT '';

-- @down

DELETE FROM `xcore_widget` WHERE `type` = 'url';
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `url_height`;
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `url`;
ALTER TABLE `xcore_widget` DROP COLUMN IF EXISTS `type`;
