-- unee core schema: every xcore_* table (complete environment)
-- generated from .elements/mcd.v2.sql, re-runnable (CREATE TABLE IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS `xcore_article` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `language` char(5) NOT NULL,
  `type` varchar(255) NOT NULL,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `header_image` varchar(255) NOT NULL,
  `resume` varchar(120) NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT '',
  `url` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(255) NOT NULL,
  `archived` enum('yes','no') NOT NULL DEFAULT 'no',
  `archived_date` datetime DEFAULT NULL,
  `publication_date` datetime DEFAULT NULL,
  `note` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `date` (`date`),
  KEY `status` (`status`),
  KEY `publication_date` (`publication_date`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `language` (`language`),
  KEY `category` (`type`),
  KEY `archived` (`archived`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_blacklist` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `email` varchar(255) NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  UNIQUE KEY `email` (`email`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_block` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `type` enum('content','file') NOT NULL DEFAULT 'content',
  `name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL DEFAULT '',
  `content` text NOT NULL,
  `publication_date_start` datetime DEFAULT NULL,
  `publication_date_end` datetime DEFAULT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `visible` (`active`),
  KEY `category` (`category`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_crontask` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `script_path` text NOT NULL,
  `script_parameters` text NOT NULL,
  `repetition_months` varchar(255) NOT NULL DEFAULT '',
  `repetition_days` varchar(255) NOT NULL DEFAULT '',
  `repetition_hours` varchar(255) NOT NULL DEFAULT '',
  `repetition_minutes` varchar(255) NOT NULL DEFAULT '',
  `email_report` enum('yes','no') NOT NULL DEFAULT 'no',
  `email_report_subject` varchar(255) NOT NULL DEFAULT '',
  `email_report_recipients` text NOT NULL,
  `email_report_failure` enum('yes','no') NOT NULL DEFAULT 'no',
  `status` varchar(255) NOT NULL,
  `locked` enum('yes','no') NOT NULL DEFAULT 'no',
  `locked_date` datetime DEFAULT NULL,
  `last_error_message` text NOT NULL,
  `last_execution_date_start` datetime DEFAULT NULL,
  `last_execution_date_end` datetime DEFAULT NULL,
  `priority` int(10) unsigned NOT NULL DEFAULT 0,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `priority` (`priority`),
  KEY `active` (`active`),
  KEY `status` (`status`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_dump` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `query` text NOT NULL,
  `php_hook` longtext NOT NULL,
  `export_type` enum('csv') NOT NULL DEFAULT 'csv',
  `export_filename` varchar(255) NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_entity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `category` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `entity` varchar(255) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `action` varchar(255) NOT NULL,
  `entity2` varchar(255) NOT NULL,
  `entity2_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `parameters` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `deleted` (`deleted`),
  KEY `category` (`category`),
  KEY `object_id` (`entity_id`),
  KEY `entity2_id` (`entity2_id`),
  KEY `action` (`action`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_event` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  UNIQUE KEY `name` (`name`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='list all event';

CREATE TABLE IF NOT EXISTS `xcore_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `category_slug` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `source` varchar(255) NOT NULL,
  `visible` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `visible` (`visible`),
  KEY `category` (`category`),
  KEY `category_slug` (`category_slug`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_gallery` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `active` (`active`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_gallery_card` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_gallery_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `image` varchar(255) NOT NULL DEFAULT '0',
  `visible` enum('yes','no') NOT NULL DEFAULT 'yes',
  `position` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `visible` (`visible`),
  KEY `position` (`position`),
  KEY `xcore_slide_id` (`xcore_gallery_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_globals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('text','int','float','boolean','json-array','json-object','list','url') NOT NULL DEFAULT 'text',
  `description` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  UNIQUE KEY `name` (`name`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_group` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `priority` int(10) unsigned NOT NULL,
  `access_backend` enum('yes','no') NOT NULL DEFAULT 'no',
  `access_frontend` enum('yes','no') NOT NULL DEFAULT 'no',
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_group_right` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_group_id` bigint(20) unsigned NOT NULL,
  `xcore_plugin_id` bigint(20) unsigned NOT NULL,
  `action` varchar(255) DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `action` (`action`),
  KEY `xcore_group_id` (`xcore_group_id`),
  KEY `xcore_module_id` (`xcore_plugin_id`),
  UNIQUE KEY `xcore_group_id_xcore_plugin_id_action` (`xcore_group_id`, `xcore_plugin_id`, `action`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_layout` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_list` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `icon` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `style` varchar(255) NOT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `position` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `name` (`name`),
  KEY `active` (`active`),
  KEY `deleted` (`deleted`),
  KEY `position` (`position`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `author` varchar(255) DEFAULT NULL,
  `application` varchar(255) NOT NULL DEFAULT 'backend',
  `date` datetime NOT NULL,
  `level` enum('info','warning','error','fatal','debug','success','critical') NOT NULL DEFAULT 'info',
  `plugin` varchar(255) NOT NULL,
  `action` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `values` longtext NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `ip` varchar(255) NOT NULL DEFAULT '',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `level` (`level`),
  KEY `deleted` (`deleted`),
  KEY `application` (`application`),
  KEY `action` (`action`),
  KEY `module` (`plugin`),
  KEY `author` (`author`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_mail_template` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `xcore_layout_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `sender_en` varchar(255) NOT NULL,
  `subject_en` varchar(255) NOT NULL DEFAULT '',
  `body_en` longtext NOT NULL,
  `sender_fr` varchar(255) NOT NULL,
  `subject_fr` varchar(255) NOT NULL DEFAULT '',
  `body_fr` longtext NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `deleted` (`deleted`),
  UNIQUE KEY `name` (`name`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_mailinglist` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_mailinglist_subscriber` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_mailinglist_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `language` char(5) NOT NULL DEFAULT 'en',
  `date` datetime NOT NULL,
  `email` varchar(255) NOT NULL,
  `firstname` varchar(255) NOT NULL,
  `lastname` varchar(255) NOT NULL,
  `unsubscribe_newsletter_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `unsubscribe_date` datetime DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `email` (`email`),
  KEY `unsubscribe_newsletter_id` (`unsubscribe_newsletter_id`),
  KEY `xcore_mailinglist_id` (`xcore_mailinglist_id`),
  UNIQUE KEY `xcore_mailinglist_id_email` (`xcore_mailinglist_id`, `email`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_menu` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `icon` varchar(255) NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `position` (`position`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_migration` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL DEFAULT '0',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  UNIQUE KEY `version` (`version`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_newsletter` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `category` varchar(255) NOT NULL DEFAULT '',
  `campaign` varchar(255) NOT NULL DEFAULT '',
  `expeditor_email` varchar(255) NOT NULL,
  `expeditor_email_label` varchar(255) NOT NULL,
  `reply_to` varchar(255) NOT NULL,
  `return_path` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `subject2` varchar(255) NOT NULL DEFAULT '',
  `subject3` varchar(255) NOT NULL DEFAULT '',
  `template_url` varchar(255) NOT NULL,
  `template_url2` varchar(255) NOT NULL,
  `template_url3` varchar(255) NOT NULL,
  `link_add_parameters` text NOT NULL,
  `mailinglist_ids` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `programming_date` datetime DEFAULT NULL,
  `scheduler_date_start` datetime DEFAULT NULL,
  `scheduler_date_end` datetime DEFAULT NULL,
  `scheduler_report_email` enum('yes','no') NOT NULL DEFAULT 'no',
  `scheduler_report_email_recipients` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `status` (`status`),
  KEY `deleted` (`deleted`),
  KEY `category` (`category`),
  KEY `programming_date` (`programming_date`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_newsletter_data` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_newsletter_id` bigint(20) unsigned NOT NULL,
  `xcore_mailinglist_id` bigint(20) unsigned NOT NULL,
  `xcore_mailinglist_subscriber_id` bigint(20) unsigned NOT NULL,
  `date` datetime NOT NULL,
  `email` varchar(255) NOT NULL DEFAULT '',
  `action` enum('sent','sent-error','unsubscribe','view','click','blacklisted','conversion','refused','bounce','queue','spam','quota-exceeded','mail not exists') NOT NULL,
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(255) NOT NULL DEFAULT '',
  `conversion_type` varchar(255) NOT NULL DEFAULT '',
  `conversion_params` longtext NOT NULL,
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `url` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_mailinglist_id` (`xcore_mailinglist_id`),
  KEY `xcore_mailinglist_subscriber_id` (`xcore_mailinglist_subscriber_id`),
  KEY `date` (`date`),
  KEY `action` (`action`),
  KEY `xcore_newsletter_id` (`xcore_newsletter_id`),
  KEY `deleted` (`deleted`),
  KEY `conversion_type` (`conversion_type`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_page` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `language` varchar(5) NOT NULL,
  `xcore_page_zone_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_page_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `type` varchar(255) NOT NULL DEFAULT 'page',
  `name` varchar(255) NOT NULL,
  `template` varchar(255) NOT NULL,
  `featured_image` varchar(255) NOT NULL,
  `headline` varchar(255) NOT NULL,
  `resume` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `menu_visible` enum('yes','no') NOT NULL DEFAULT 'yes',
  `meta_title` varchar(255) NOT NULL,
  `meta_description` varchar(255) NOT NULL,
  `meta_keywords` varchar(255) NOT NULL,
  `meta_robot` varchar(255) NOT NULL,
  `meta_og_type` varchar(255) NOT NULL,
  `meta_og_image` varchar(255) NOT NULL,
  `sitemap_priority` varchar(4) NOT NULL DEFAULT '',
  `sitemap_change_freq` enum('','hourly','daily','weekly','monthly','yearly','always','never') NOT NULL DEFAULT '',
  `sitemap_pagination_pattern` varchar(255) NOT NULL DEFAULT '',
  `sitemap_follow_url_pattern` varchar(255) NOT NULL DEFAULT '',
  `sitemap_follow_url_priority` varchar(255) NOT NULL DEFAULT '',
  `url` varchar(255) NOT NULL,
  `is_homepage` enum('yes','no') NOT NULL DEFAULT 'no',
  `list_subpage` enum('yes','no') NOT NULL DEFAULT 'no',
  `status` varchar(255) NOT NULL,
  `publication_date` datetime DEFAULT NULL,
  `locked` enum('yes','no') NOT NULL DEFAULT 'no',
  `locked_at` datetime DEFAULT NULL,
  `locked_xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_page_id` (`xcore_page_id`),
  KEY `status` (`status`),
  KEY `menu_visible` (`menu_visible`),
  KEY `lang` (`language`),
  KEY `deleted` (`deleted`),
  KEY `is_homepage` (`is_homepage`),
  KEY `xcore_zone_id` (`xcore_page_zone_id`),
  KEY `type` (`type`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`),
  KEY `position` (`position`),
  KEY `publication_date` (`publication_date`),
  KEY `locked` (`locked`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_page_template` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `collection` varchar(255) NOT NULL,
  `image_preview` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `active` (`active`),
  KEY `collection` (`collection`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_page_visitor` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `user_ip` varchar(50) NOT NULL,
  `user_agent` text NOT NULL,
  `user_device` varchar(255) NOT NULL DEFAULT '',
  `user_browser` varchar(255) NOT NULL DEFAULT '',
  `user_browser_version` varchar(255) NOT NULL DEFAULT '',
  `user_os` varchar(255) NOT NULL DEFAULT '',
  `user_os_version` varchar(255) NOT NULL DEFAULT '',
  `user_os_label` varchar(255) NOT NULL DEFAULT '',
  `user_lang` varchar(255) NOT NULL DEFAULT '',
  `referer` text NOT NULL,
  `url` text NOT NULL,
  `url_domain` text NOT NULL,
  `url_query_string` text DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_page_zone` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `website` varchar(255) NOT NULL,
  `position` int(10) unsigned NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `position` (`position`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_pattern` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `pattern` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `deleted` (`deleted`),
  KEY `active` (`active`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_plugin` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_menu_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `type` enum('normal','url','core') NOT NULL DEFAULT 'normal',
  `icon` varchar(255) NOT NULL,
  `route_prefix_name` varchar(255) NOT NULL,
  `actions` text NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `xcore_plugin_id` bigint(20) unsigned DEFAULT 0 COMMENT 'parent plugin',
  `position` int(10) unsigned NOT NULL,
  `visible` enum('yes','no') NOT NULL DEFAULT 'yes',
  `versioning` enum('yes','no') NOT NULL DEFAULT 'yes',
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `position` (`position`),
  KEY `xcore_category_id` (`xcore_menu_id`),
  KEY `active` (`active`),
  KEY `visible` (`visible`),
  UNIQUE KEY `route_prefix_name` (`route_prefix_name`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_slider` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `class` varchar(255) NOT NULL,
  `js` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_speed` int(10) unsigned NOT NULL DEFAULT 400,
  `option_allow_touch` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_slider_per_view` int(10) unsigned NOT NULL DEFAULT 1,
  `option_centered_slides` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_navigation` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_pagination` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_effect` enum('fade','coverflow','flip','card','creative','cube','none') NOT NULL DEFAULT 'fade',
  `option_loop` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_direction` enum('horizontal','vertical') NOT NULL DEFAULT 'horizontal',
  `option_gap` int(11) NOT NULL DEFAULT 0,
  `option_grab_cursor` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_keyboard` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_mousewheel` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_zoom` enum('yes','no') NOT NULL DEFAULT 'yes',
  `option_autoplay` enum('yes','no') NOT NULL DEFAULT 'no',
  `option_autoplay_delay_ms` int(11) NOT NULL DEFAULT 3000,
  `option_breakpoints` text NOT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `active` (`active`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_slider_card` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_slider_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) NOT NULL,
  `image_mobile` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `title_color` varchar(255) NOT NULL,
  `text` text NOT NULL,
  `text_color` text NOT NULL,
  `button_text` varchar(255) NOT NULL,
  `button_text_color` varchar(255) NOT NULL,
  `button_bg_color` varchar(255) NOT NULL,
  `button_href` varchar(255) NOT NULL,
  `button_class` varchar(255) NOT NULL,
  `date_start` datetime DEFAULT NULL,
  `date_end` datetime DEFAULT NULL,
  `visible` enum('yes','no') NOT NULL DEFAULT 'yes',
  `position` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `visible` (`visible`),
  KEY `xcore_slide_id` (`xcore_slider_id`),
  KEY `position` (`position`),
  KEY `date_start` (`date_start`),
  KEY `date_end` (`date_end`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_tag` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature` varchar(255) NOT NULL,
  `tag` varchar(255) NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime NOT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `record_id` (`record_id`),
  KEY `deleted` (`deleted`),
  KEY `signature` (`signature`),
  KEY `tag` (`tag`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_ticket` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `email` varchar(255) NOT NULL DEFAULT '',
  `subject` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(255) NOT NULL DEFAULT 'open',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `date` (`date`),
  KEY `status` (`status`),
  KEY `order_id` (`order_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_ticket_message` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_ticket_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `imap_uid` bigint(20) unsigned NOT NULL DEFAULT 0,
  `date` datetime NOT NULL,
  `direction` enum('in','out') NOT NULL DEFAULT 'out',
  `sender_email` varchar(255) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `date` (`date`),
  KEY `xcore_ticket_id` (`xcore_ticket_id`),
  KEY `imap_uid` (`imap_uid`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_ticket_template` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_url_redirect` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rule` text NOT NULL,
  `url` text NOT NULL,
  `code` int(10) unsigned NOT NULL DEFAULT 0,
  `position` bigint(20) unsigned NOT NULL DEFAULT 0,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `position` (`position`),
  KEY `active` (`active`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_group_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `avatar` text NOT NULL,
  `language` char(5) NOT NULL DEFAULT 'en',
  `lastname` varchar(50) NOT NULL,
  `firstname` varchar(50) NOT NULL,
  `company` varchar(50) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `address3` varchar(255) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `service` varchar(255) DEFAULT NULL,
  `job` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `login` varchar(30) NOT NULL,
  `password` varbinary(255) NOT NULL,
  `timezone` varchar(255) DEFAULT '',
  `format_date` varchar(255) DEFAULT NULL,
  `format_datetime` varchar(255) DEFAULT NULL,
  `last_connection_date` datetime DEFAULT NULL,
  `last_connection_application` varchar(255) DEFAULT NULL,
  `last_connection_ip` varchar(255) DEFAULT NULL,
  `password_token` varchar(255) DEFAULT NULL,
  `password_token_date` datetime DEFAULT NULL,
  `password_token_expiration_date` datetime DEFAULT NULL,
  `bio` longtext NOT NULL,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `note` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `active` (`active`),
  KEY `xcore_group_id` (`xcore_group_id`),
  UNIQUE KEY `email` (`email`, `deleted_key`),
  UNIQUE KEY `login` (`login`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_user_bookmark` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_plugin_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `position` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `deleted_key` tinyint(1) GENERATED ALWAYS AS (if(`deleted` = 'no',1,NULL)) VIRTUAL COMMENT 'unique keys on non deleted records',
  `created_at` datetime NOT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_user_id` (`xcore_user_id`),
  UNIQUE KEY `xcore_user_id_xcore_plugin_id` (`xcore_user_id`, `xcore_plugin_id`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_user_note` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `text` text NOT NULL,
  `color` varchar(20) NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `deleted` (`deleted`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_user_search` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `xcore_plugin_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `url` longtext NOT NULL,
  `default` enum('yes','no') NOT NULL DEFAULT 'no',
  `default_xcore_group_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `share` enum('yes','no') NOT NULL DEFAULT 'no',
  `share_xcore_group_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime NOT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deleted` (`deleted`),
  KEY `xcore_user_id` (`xcore_user_id`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_versioning` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned DEFAULT NULL,
  `date` datetime NOT NULL,
  `application` varchar(255) NOT NULL,
  `table` varchar(255) NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `data_json` text NOT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `application` (`application`),
  KEY `record_id` (`record_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `xcore_widget` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_plugin_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `method` varchar(255) NOT NULL,
  `autorefresh` enum('yes','no') NOT NULL DEFAULT 'no',
  `autorefresh_seconds` int(11) NOT NULL DEFAULT 5,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `active` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `active` (`active`),
  KEY `position` (`position`),
  KEY `xcore_plugin_id` (`xcore_plugin_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down

-- xcore_migration is kept: it records the rollback itself
DROP TABLE IF EXISTS `xcore_article`;
DROP TABLE IF EXISTS `xcore_blacklist`;
DROP TABLE IF EXISTS `xcore_block`;
DROP TABLE IF EXISTS `xcore_crontask`;
DROP TABLE IF EXISTS `xcore_dump`;
DROP TABLE IF EXISTS `xcore_entity_log`;
DROP TABLE IF EXISTS `xcore_event`;
DROP TABLE IF EXISTS `xcore_files`;
DROP TABLE IF EXISTS `xcore_gallery`;
DROP TABLE IF EXISTS `xcore_gallery_card`;
DROP TABLE IF EXISTS `xcore_globals`;
DROP TABLE IF EXISTS `xcore_group`;
DROP TABLE IF EXISTS `xcore_group_right`;
DROP TABLE IF EXISTS `xcore_layout`;
DROP TABLE IF EXISTS `xcore_list`;
DROP TABLE IF EXISTS `xcore_log`;
DROP TABLE IF EXISTS `xcore_mail_template`;
DROP TABLE IF EXISTS `xcore_mailinglist`;
DROP TABLE IF EXISTS `xcore_mailinglist_subscriber`;
DROP TABLE IF EXISTS `xcore_menu`;
DROP TABLE IF EXISTS `xcore_newsletter`;
DROP TABLE IF EXISTS `xcore_newsletter_data`;
DROP TABLE IF EXISTS `xcore_page`;
DROP TABLE IF EXISTS `xcore_page_template`;
DROP TABLE IF EXISTS `xcore_page_visitor`;
DROP TABLE IF EXISTS `xcore_page_zone`;
DROP TABLE IF EXISTS `xcore_pattern`;
DROP TABLE IF EXISTS `xcore_plugin`;
DROP TABLE IF EXISTS `xcore_slider`;
DROP TABLE IF EXISTS `xcore_slider_card`;
DROP TABLE IF EXISTS `xcore_tag`;
DROP TABLE IF EXISTS `xcore_ticket`;
DROP TABLE IF EXISTS `xcore_ticket_message`;
DROP TABLE IF EXISTS `xcore_ticket_template`;
DROP TABLE IF EXISTS `xcore_url_redirect`;
DROP TABLE IF EXISTS `xcore_user`;
DROP TABLE IF EXISTS `xcore_user_bookmark`;
DROP TABLE IF EXISTS `xcore_user_note`;
DROP TABLE IF EXISTS `xcore_user_search`;
DROP TABLE IF EXISTS `xcore_versioning`;
DROP TABLE IF EXISTS `xcore_widget`;
