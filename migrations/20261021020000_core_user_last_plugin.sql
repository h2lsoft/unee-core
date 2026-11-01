-- last active users widget: plugin the user is in (route_prefix_name of xcore_plugin, dashboard included)

ALTER TABLE `xcore_user` ADD COLUMN IF NOT EXISTS `last_connection_plugin` varchar(255) DEFAULT NULL AFTER `last_connection_ip`;

-- @down

ALTER TABLE `xcore_user` DROP COLUMN IF EXISTS `last_connection_plugin`;
