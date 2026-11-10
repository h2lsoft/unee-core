-- newsletters: mailing lists stored as a json array of ids (["1","3"], like every multi select) instead of [1][3]

ALTER TABLE `xcore_newsletter` MODIFY `mailinglist_ids` text DEFAULT NULL COMMENT 'json array of xcore_mailinglist ids';

UPDATE `xcore_newsletter` SET `mailinglist_ids` = '[]' WHERE `mailinglist_ids` IS NULL OR `mailinglist_ids` = '';

UPDATE `xcore_newsletter` SET `mailinglist_ids` = CONCAT('["', REPLACE(SUBSTRING(`mailinglist_ids`, 2, CHAR_LENGTH(`mailinglist_ids`) - 2), '][', '","'), '"]')
WHERE `mailinglist_ids` REGEXP '^([[][0-9]+[]])+$';

-- @down

UPDATE `xcore_newsletter` SET `mailinglist_ids` = REPLACE(REPLACE(REPLACE(`mailinglist_ids`, '","', ']['), '["', '['), '"]', ']')
WHERE `mailinglist_ids` LIKE '["%"]';

UPDATE `xcore_newsletter` SET `mailinglist_ids` = '' WHERE `mailinglist_ids` IS NULL OR `mailinglist_ids` = '[]';

ALTER TABLE `xcore_newsletter` MODIFY `mailinglist_ids` varchar(255) NOT NULL DEFAULT '';
