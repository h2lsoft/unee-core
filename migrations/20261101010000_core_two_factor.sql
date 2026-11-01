-- two-factor authentication (totp): encrypted secret per user, trusted devices, right two_factor_reset on the users plugin

ALTER TABLE `xcore_user` ADD COLUMN IF NOT EXISTS `two_factor_secret` varchar(255) DEFAULT NULL;
ALTER TABLE `xcore_user` ADD COLUMN IF NOT EXISTS `two_factor_enabled_at` datetime DEFAULT NULL;
ALTER TABLE `xcore_user` ADD COLUMN IF NOT EXISTS `two_factor_last_step` bigint(20) unsigned NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `xcore_user_trusted_device` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `application` varchar(20) NOT NULL DEFAULT 'backend',
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `token_hash` (`token_hash`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `xcore_plugin` SET `actions` = JSON_ARRAY_APPEND(`actions`, '$', 'two_factor_reset')
WHERE `route_prefix_name` = 'user' AND `deleted` = 'no' AND NOT JSON_CONTAINS(`actions`, '"two_factor_reset"');

-- @down

UPDATE `xcore_plugin` SET `actions` = JSON_REMOVE(`actions`, JSON_UNQUOTE(JSON_SEARCH(`actions`, 'one', 'two_factor_reset')))
WHERE `route_prefix_name` = 'user' AND `deleted` = 'no' AND JSON_CONTAINS(`actions`, '"two_factor_reset"');

DROP TABLE IF EXISTS `xcore_user_trusted_device`;

ALTER TABLE `xcore_user` DROP COLUMN IF EXISTS `two_factor_last_step`;
ALTER TABLE `xcore_user` DROP COLUMN IF EXISTS `two_factor_enabled_at`;
ALTER TABLE `xcore_user` DROP COLUMN IF EXISTS `two_factor_secret`;
