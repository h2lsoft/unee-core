-- lists: each value is shown as a mui chip, class = color and variant (ex: success outlined), style = css added
-- the badge color of the previous migration becomes the class, a class that is not a mui chip class (ex: bootstrap) is replaced

UPDATE `xcore_list` SET `class` = CASE `color`
	WHEN 'orange' THEN 'warning filled'
	WHEN 'red' THEN 'error filled'
	WHEN 'green' THEN 'success filled'
	WHEN 'blue' THEN 'info filled'
	WHEN 'purple' THEN 'secondary filled'
	WHEN 'yellow' THEN 'warning outlined'
	WHEN 'cyan' THEN 'info outlined'
	ELSE 'default filled'
END
WHERE `class` NOT REGEXP '^(default|primary|secondary|error|warning|info|success) (filled|outlined)$';

ALTER TABLE `xcore_list` DROP COLUMN IF EXISTS `color`;
ALTER TABLE `xcore_list` MODIFY `class` varchar(255) NOT NULL DEFAULT 'default filled';

-- @down

-- the color comes back from the class, the classes replaced by the up part (ex: bootstrap) are not restored
ALTER TABLE `xcore_list` ADD COLUMN IF NOT EXISTS `color` varchar(20) NOT NULL DEFAULT 'grey' AFTER `style`;
UPDATE `xcore_list` SET `color` = CASE `class`
	WHEN 'warning filled' THEN 'orange'
	WHEN 'error filled' THEN 'red'
	WHEN 'success filled' THEN 'green'
	WHEN 'info filled' THEN 'blue'
	WHEN 'secondary filled' THEN 'purple'
	WHEN 'warning outlined' THEN 'yellow'
	WHEN 'info outlined' THEN 'cyan'
	ELSE 'grey'
END;
ALTER TABLE `xcore_list` MODIFY `class` varchar(255) NOT NULL DEFAULT '';
