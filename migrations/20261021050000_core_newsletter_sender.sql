-- cron task Newsletter sender: every 15 minutes, at most 500 emails and 780 s per run (under the 15 minutes)

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Communication', 'Newsletter sender', 'Sends the scheduled newsletters to their mailing lists', 'vendor/h2lsoft/unee-core/core/cron/newsletter_sender.php', 'limit=500&max_seconds=780', '*', '*', '*', '*/15', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/newsletter_sender.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/newsletter_sender.php' AND `created_by` = 'unee-migrate';
