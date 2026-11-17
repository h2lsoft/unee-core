-- cron task Article archiving: the articles whose archive date passed are archived

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Website', 'Article archiving', 'Archives the articles whose archive date passed', 'vendor/h2lsoft/unee-core/core/cron/article_archiving.php', 'limit=500', '*', '*', '*', '*/15', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/article_archiving.php' AND x.deleted = 'no');

CREATE INDEX IF NOT EXISTS `archived_date` ON `xcore_article` (`archived_date`);

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/article_archiving.php' AND `created_by` = 'unee-migrate';
DROP INDEX IF EXISTS `archived_date` ON `xcore_article`;
