-- dashboard of the user by rows: a widget is in a row (0, 1, 2...) and has a position in it; the widths of a row are 4 columns at most

ALTER TABLE `xcore_user_widget` ADD COLUMN IF NOT EXISTS `row` int(10) unsigned NOT NULL DEFAULT 0 AFTER `xcore_widget_id`;

-- @down

ALTER TABLE `xcore_user_widget` DROP COLUMN IF EXISTS `row`;
