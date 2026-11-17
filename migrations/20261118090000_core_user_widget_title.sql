-- dashboard: title of a placed widget, set by its user (empty = name of the widget, translated)

ALTER TABLE `xcore_user_widget` ADD COLUMN IF NOT EXISTS `title` varchar(60) NOT NULL DEFAULT '' AFTER `config`;

-- @down

ALTER TABLE `xcore_user_widget` DROP COLUMN IF EXISTS `title`;
