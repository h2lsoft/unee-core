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
