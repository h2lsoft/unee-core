-- saved filters shared with several groups: xcore_group_id replaced by xcore_group_ids (json array of ids as strings, [] = personal)

ALTER TABLE `xcore_user_filter` ADD COLUMN IF NOT EXISTS `xcore_group_ids` text NOT NULL DEFAULT '[]' COMMENT 'shared with these groups, json ["id"], [] = personal' AFTER `xcore_user_id`;

UPDATE `xcore_user_filter` SET `xcore_group_ids` = CONCAT('["', `xcore_group_id`, '"]') WHERE `xcore_group_id` IS NOT NULL;

ALTER TABLE `xcore_user_filter` DROP INDEX IF EXISTS `xcore_group_id`, DROP COLUMN IF EXISTS `xcore_group_id`;

-- @down

-- a filter shared with several groups keeps the first one
ALTER TABLE `xcore_user_filter` ADD COLUMN IF NOT EXISTS `xcore_group_id` bigint(20) unsigned DEFAULT NULL COMMENT 'shared with this group, null = personal' AFTER `xcore_user_id`;
UPDATE `xcore_user_filter` SET `xcore_group_id` = JSON_UNQUOTE(JSON_EXTRACT(`xcore_group_ids`, '$[0]')) WHERE JSON_VALID(`xcore_group_ids`) AND JSON_LENGTH(`xcore_group_ids`) > 0;
ALTER TABLE `xcore_user_filter` ADD INDEX IF NOT EXISTS `xcore_group_id` (`xcore_group_id`), DROP COLUMN IF EXISTS `xcore_group_ids`;
