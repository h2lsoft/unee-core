-- versioning: snapshots of large records (html pages) do not fit in a text column

ALTER TABLE `xcore_versioning` MODIFY `data_json` mediumtext NOT NULL;
ALTER TABLE `xcore_versioning` ADD INDEX IF NOT EXISTS `table_record` (`table`, `record_id`);
