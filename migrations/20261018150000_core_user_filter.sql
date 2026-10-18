-- saved filters of the grids: personal (xcore_group_id null) or shared with a group

CREATE TABLE IF NOT EXISTS `xcore_user_filter` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL,
  `xcore_group_id` bigint(20) unsigned DEFAULT NULL COMMENT 'shared with this group, null = personal',
  `plugin` varchar(255) NOT NULL COMMENT 'route_prefix_name of the module',
  `name` varchar(255) NOT NULL,
  `conditions` text NOT NULL COMMENT 'json {"match": "all|any", "conditions": [{"f", "o", "v"}]}',
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
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `xcore_group_id` (`xcore_group_id`),
  KEY `plugin` (`plugin`),
  UNIQUE KEY `xcore_user_id_plugin_name` (`xcore_user_id`, `plugin`, `name`, `deleted_key`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
