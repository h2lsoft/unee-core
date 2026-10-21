-- newsletters: content (template url or html), preheader, test emails, html frozen at the start of the sending, error message
-- statuses: draft, scheduled, sending, paused, sent, cancelled, error
-- dashboard widget Newsletter credits, cron task Newsletter bounces (imap mailbox of the return path)

ALTER TABLE `xcore_newsletter` ADD COLUMN IF NOT EXISTS `content_type` varchar(10) NOT NULL DEFAULT 'url' COMMENT 'url, html' AFTER `subject3`;
ALTER TABLE `xcore_newsletter` ADD COLUMN IF NOT EXISTS `content_html` longtext NOT NULL DEFAULT '' AFTER `template_url3`;
ALTER TABLE `xcore_newsletter` ADD COLUMN IF NOT EXISTS `preheader` varchar(255) NOT NULL DEFAULT '' AFTER `subject3`;
ALTER TABLE `xcore_newsletter` ADD COLUMN IF NOT EXISTS `test_emails` text NOT NULL DEFAULT '' AFTER `scheduler_report_email_recipients`;
ALTER TABLE `xcore_newsletter` ADD COLUMN IF NOT EXISTS `snapshot_json` longtext DEFAULT NULL COMMENT 'variants [{subject, html}] frozen at the start of the sending' AFTER `test_emails`;
ALTER TABLE `xcore_newsletter` ADD COLUMN IF NOT EXISTS `error_message` varchar(255) NOT NULL DEFAULT '' AFTER `status`;
ALTER TABLE `xcore_newsletter` MODIFY `template_url` varchar(255) NOT NULL DEFAULT '', MODIFY `template_url2` varchar(255) NOT NULL DEFAULT '', MODIFY `template_url3` varchar(255) NOT NULL DEFAULT '', MODIFY `expeditor_email_label` varchar(255) NOT NULL DEFAULT '', MODIFY `reply_to` varchar(255) NOT NULL DEFAULT '', MODIFY `return_path` varchar(255) NOT NULL DEFAULT '', MODIFY `link_add_parameters` text NOT NULL DEFAULT '', MODIFY `mailinglist_ids` varchar(255) NOT NULL DEFAULT '', MODIFY `status` varchar(255) NOT NULL DEFAULT 'draft', MODIFY `scheduler_report_email_recipients` text NOT NULL DEFAULT '';
UPDATE `xcore_newsletter` SET `status` = 'draft' WHERE `status` = '';
ALTER TABLE `xcore_newsletter_data` ADD KEY IF NOT EXISTS `newsletter_email` (`xcore_newsletter_id`, `email`(190));

INSERT INTO `xcore_widget` (`xcore_plugin_id`, `name`, `method`, `size`, `autorefresh`, `autorefresh_seconds`, `position`, `active`, `created_at`, `created_by`)
SELECT p.id, 'Newsletter credits', CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'NewsletterController::widgetCredits'), 'half', 'yes', 300, 50, 'yes', NOW(), 'unee-migrate'
FROM `xcore_plugin` p
WHERE p.route_prefix_name = 'newsletter' AND p.deleted = 'no'
AND NOT EXISTS (SELECT 1 FROM `xcore_widget` x WHERE x.method = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'NewsletterController::widgetCredits') AND x.deleted = 'no')
LIMIT 1;

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'Communication', 'Newsletter bounces', 'Reads the bounces of the imap mailbox (globals newsletter.bounce.*), blacklists the hard bounces', 'vendor/h2lsoft/unee-core/core/cron/newsletter_bounce.php', 'max_messages=500', '*', '*', '*', '5,35', '', 'idle', '', 20, 'no', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/newsletter_bounce.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/newsletter_bounce.php' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_widget` WHERE `created_by` = 'unee-migrate' AND `method` = CONCAT('Plugin', CHAR(92), 'Communication', CHAR(92), 'NewsletterController::widgetCredits');
ALTER TABLE `xcore_newsletter_data` DROP KEY IF EXISTS `newsletter_email`;
ALTER TABLE `xcore_newsletter` DROP COLUMN IF EXISTS `content_type`, DROP COLUMN IF EXISTS `content_html`, DROP COLUMN IF EXISTS `preheader`, DROP COLUMN IF EXISTS `test_emails`, DROP COLUMN IF EXISTS `snapshot_json`, DROP COLUMN IF EXISTS `error_message`;
