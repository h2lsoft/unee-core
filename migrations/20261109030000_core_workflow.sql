-- workflows (\Core\Workflow): one per module, states and transitions, history of each record, approvals of the transitions that need several
-- the state of a record stays in a column of the table of its module (grid, filters, chips and export as usual)

CREATE TABLE IF NOT EXISTS `xcore_workflow` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_plugin_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `state_column` varchar(64) NOT NULL DEFAULT 'state' COMMENT 'column of the table of the module holding the state',
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_plugin_id` (`xcore_plugin_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_workflow_state` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_workflow_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `label` varchar(100) NOT NULL DEFAULT '',
  `color` varchar(20) NOT NULL DEFAULT 'grey',
  `initial` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'state of a new record when no transition starts from the creation',
  `final` enum('yes','no') NOT NULL DEFAULT 'no',
  `edit_xcore_group_ids` text DEFAULT NULL COMMENT 'json array: groups that edit the record in this state, empty = locked',
  `sla_hours` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0 = no deadline',
  `sla_action` varchar(10) NOT NULL DEFAULT 'notify' COMMENT 'notify or remind',
  `sla_xcore_group_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `x` int(11) NOT NULL DEFAULT 0,
  `y` int(11) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_workflow_id` (`xcore_workflow_id`),
  KEY `position` (`position`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_workflow_transition` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_workflow_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `label` varchar(100) NOT NULL DEFAULT '',
  `from_state` varchar(50) NOT NULL DEFAULT '' COMMENT 'empty = creation of the record',
  `to_state` varchar(50) NOT NULL DEFAULT '',
  `xcore_group_ids` text DEFAULT NULL COMMENT 'json array: groups that fire it, empty = users with the edit right (the add right for the creation)',
  `system` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'fired by the code only (job, cron)',
  `required_fields` text DEFAULT NULL COMMENT 'json array: columns filled before the transition',
  `comment_required` enum('yes','no') NOT NULL DEFAULT 'no',
  `approvals` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'different users needed',
  `effects` text DEFAULT NULL COMMENT 'json array of the effects',
  `bend` int(11) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_workflow_id` (`xcore_workflow_id`),
  KEY `position` (`position`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_workflow_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(100) NOT NULL DEFAULT '',
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `transition` varchar(50) NOT NULL DEFAULT '' COMMENT 'empty = creation without transition',
  `from_state` varchar(50) NOT NULL DEFAULT '',
  `to_state` varchar(50) NOT NULL DEFAULT '',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '0 = system',
  `comment` text DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `record` (`module`, `record_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_workflow_approval` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(100) NOT NULL DEFAULT '',
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `transition` varchar(50) NOT NULL DEFAULT '',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `comment` text DEFAULT NULL,
  `xcore_workflow_history_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT '0 = pending, else the change of state that closed it',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pending` (`module`, `record_id`, `xcore_workflow_history_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down

DROP TABLE IF EXISTS `xcore_workflow_approval`;
DROP TABLE IF EXISTS `xcore_workflow_history`;
DROP TABLE IF EXISTS `xcore_workflow_transition`;
DROP TABLE IF EXISTS `xcore_workflow_state`;
DROP TABLE IF EXISTS `xcore_workflow`;
