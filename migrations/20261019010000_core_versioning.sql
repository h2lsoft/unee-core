-- versioning: snapshots of large records (html pages) do not fit in a text column

ALTER TABLE `xcore_versioning` MODIFY `data_json` mediumtext NOT NULL;
ALTER TABLE `xcore_versioning` ADD INDEX IF NOT EXISTS `table_record` (`table`(100), `record_id`); -- prefix: 1000 bytes max on MyISAM in utf8mb4
