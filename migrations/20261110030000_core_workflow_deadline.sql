-- deadlines of the workflow states (cron task Workflow deadlines): each entry in a state with a deadline keeps its due date
-- on its history row (sla_due_at), handled once (sla_done_at), closed without action when the record leaves the state before
-- actions: notify (the group sla_xcore_group_id), remind (the creator of the record), transition (system transition sla_transition from the state)

ALTER TABLE `xcore_workflow_state` MODIFY `sla_action` varchar(20) NOT NULL DEFAULT 'notify' COMMENT 'notify (a group), remind (the creator of the record), transition (sla_transition)';
ALTER TABLE `xcore_workflow_state` ADD COLUMN IF NOT EXISTS `sla_transition` varchar(50) NOT NULL DEFAULT '' COMMENT 'system transition from this state fired at the deadline' AFTER `sla_xcore_group_id`;

ALTER TABLE `xcore_workflow_history` ADD COLUMN IF NOT EXISTS `sla_due_at` datetime DEFAULT NULL COMMENT 'deadline of the state entered, null = none' AFTER `comment`;
ALTER TABLE `xcore_workflow_history` ADD COLUMN IF NOT EXISTS `sla_done_at` datetime DEFAULT NULL COMMENT 'deadline handled, or closed by a change of state' AFTER `sla_due_at`;
CREATE INDEX IF NOT EXISTS `sla` ON `xcore_workflow_history` (`sla_done_at`, `sla_due_at`);

INSERT INTO `xcore_crontask` (`category`, `name`, `description`, `script_path`, `script_parameters`, `repetition_months`, `repetition_days`, `repetition_hours`, `repetition_minutes`, `email_report_recipients`, `status`, `last_error_message`, `priority`, `active`, `created_at`, `created_by`)
SELECT 'System', 'Workflow deadlines', 'Handles the deadlines of the workflow states: notifies a group, reminds the creator or fires a system transition', 'vendor/h2lsoft/unee-core/core/cron/workflow_deadline.php', 'limit=500', '*', '*', '*', '*/15', '', 'idle', '', 10, 'yes', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_crontask` x WHERE x.script_path = 'vendor/h2lsoft/unee-core/core/cron/workflow_deadline.php' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_crontask` WHERE `script_path` = 'vendor/h2lsoft/unee-core/core/cron/workflow_deadline.php' AND `created_by` = 'unee-migrate';
DROP INDEX IF EXISTS `sla` ON `xcore_workflow_history`;
ALTER TABLE `xcore_workflow_history` DROP COLUMN IF EXISTS `sla_done_at`, DROP COLUMN IF EXISTS `sla_due_at`;
ALTER TABLE `xcore_workflow_state` DROP COLUMN IF EXISTS `sla_transition`;
