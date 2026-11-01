-- my tasks: responsible (sees and changes the task like its creator) and progress in percent

ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `responsible_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'xcore_user, sees and changes the task' AFTER `xcore_user_id`;
ALTER TABLE `xcore_user_task` ADD COLUMN IF NOT EXISTS `progress` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'percent' AFTER `status`;
ALTER TABLE `xcore_user_task` ADD INDEX IF NOT EXISTS `responsible_id` (`responsible_id`);
UPDATE `xcore_user_task` SET `responsible_id` = `xcore_user_id` WHERE `responsible_id` = 0;
UPDATE `xcore_user_task` SET `progress` = 100 WHERE `status` = 'done';

-- @down

ALTER TABLE `xcore_user_task` DROP INDEX IF EXISTS `responsible_id`, DROP COLUMN IF EXISTS `responsible_id`, DROP COLUMN IF EXISTS `progress`;
