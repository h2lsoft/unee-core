-- dashboard of 4 columns: a widget takes 1 (quarter), 2 (half), 3 (three_quarters) or 4 (full) columns

ALTER TABLE `xcore_widget` MODIFY `size` enum('quarter','half','three_quarters','full') NOT NULL DEFAULT 'full';

-- @down

UPDATE `xcore_widget` SET `size` = 'half' WHERE `size` = 'quarter';
UPDATE `xcore_widget` SET `size` = 'full' WHERE `size` = 'three_quarters';
ALTER TABLE `xcore_widget` MODIFY `size` enum('full','half') NOT NULL DEFAULT 'full';
