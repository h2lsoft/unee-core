-- menu tools: scheduled tasks (cron), job queue (jobber), entity log, system events; mail template of the cron report; cron task running the jobber every minute

CREATE TABLE IF NOT EXISTS `xcore_job_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `type` varchar(100) NOT NULL COMMENT 'handler key of config jobs, ex: send_email',
  `payload` longtext DEFAULT NULL COMMENT 'json',
  `comment` varchar(255) NOT NULL DEFAULT '',
  `priority` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'higher first',
  `status` enum('pending','processing','completed','failed','dead') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 3,
  `scheduled_at` datetime DEFAULT NULL COMMENT 'null = now',
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) NOT NULL DEFAULT '' COMMENT 'worker',
  `completed_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `result` longtext DEFAULT NULL COMMENT 'json',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `status` (`status`),
  KEY `type` (`type`),
  KEY `fetch` (`status`, `deleted`, `scheduled_at`, `priority`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='job queue';

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, p.name, 'normal', p.icon, p.route, p.actions, p.position, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
JOIN (
	SELECT 'Cron' AS name, 'schedule' AS icon, 'cron' AS route, '["list","add","edit","delete"]' AS actions, 20 AS position
	UNION ALL SELECT 'Jobber', 'work_history', 'jobber', '["list","edit","delete"]', 30
	UNION ALL SELECT 'Entity log', 'history', 'entity-log', '["list","purge"]', 40
	UNION ALL SELECT 'System events', 'bolt', 'event', '["list","edit","delete"]', 50
) p
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Tools' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = p.route AND x.deleted = 'no');

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'System', 'Jobber', 'Runs the pending jobs of the queue', 'vendor/h2lsoft/unee-core/core/cron/jobber.php', 'max_jobs=100&max_seconds=50', '*', '*', '*', '*', '', 'idle', '', 0, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/jobber.php' AND x.deleted = 'no');

INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'Core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.cron.report', 'Report of a scheduled task, vars: subject, name, status, start, duration, error, output',
'', '[[subject]]',
'<p style="margin:0 0 16px;">Task <b>[[name]]</b>: [[status]]</p>
<p style="margin:0 0 16px;">Started [[start]], duration [[duration]] s.</p>
<p style="margin:0 0 16px;color:#b3261e;">[[error]]</p>
<pre style="margin:0;padding:12px;background:#f5f6f8;border-radius:6px;font-size:12px;white-space:pre-wrap;">[[output]]</pre>',
'', '[[subject]]',
'<p style="margin:0 0 16px;">Tâche <b>[[name]]</b> : [[status]]</p>
<p style="margin:0 0 16px;">Démarrée le [[start]], durée [[duration]] s.</p>
<p style="margin:0 0 16px;color:#b3261e;">[[error]]</p>
<pre style="margin:0;padding:12px;background:#f5f6f8;border-radius:6px;font-size:12px;white-space:pre-wrap;">[[output]]</pre>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.cron.report' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_mail_template` WHERE `name` = 'core.cron.report' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/jobber.php' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_group_right` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('cron', 'jobber', 'entity-log', 'event') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_user_bookmark` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('cron', 'jobber', 'entity-log', 'event') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_widget` WHERE `xcore_plugin_id` IN (SELECT `id` FROM `xcore_plugin` WHERE `route_prefix_name` IN ('cron', 'jobber', 'entity-log', 'event') AND `created_by` = 'unee-migrate');
DELETE FROM `xcore_plugin` WHERE `route_prefix_name` IN ('cron', 'jobber', 'entity-log', 'event') AND `created_by` = 'unee-migrate';
DROP TABLE IF EXISTS `xcore_job_queue`;
