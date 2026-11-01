-- versioning: snapshots of large records (html pages) do not fit in a text column

ALTER TABLE `xcore_versioning` MODIFY `data_json` mediumtext NOT NULL;
ALTER TABLE `xcore_versioning` ADD INDEX IF NOT EXISTS `table_record` (`table`(100), `record_id`); -- prefix: 1000 bytes max on MyISAM in utf8mb4

-- @down

-- fails (strict sql mode) when a snapshot is longer than a text column: nothing is truncated
ALTER TABLE `xcore_versioning` DROP INDEX IF EXISTS `table_record`;
ALTER TABLE `xcore_versioning` MODIFY `data_json` text NOT NULL;
