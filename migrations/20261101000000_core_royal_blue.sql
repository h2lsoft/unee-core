-- primary color royal blue instead of violet: notes purple become primary, mail buttons and default project color

UPDATE `xcore_user_note` SET `color` = 'primary' WHERE `color` = 'purple';

UPDATE `xcore_mail_template` SET `body_en` = REPLACE(`body_en`, '#6d4aff', '#4169e1'), `body_fr` = REPLACE(`body_fr`, '#6d4aff', '#4169e1') WHERE `body_en` LIKE '%#6d4aff%' OR `body_fr` LIKE '%#6d4aff%';

ALTER TABLE `xcore_project` ALTER COLUMN `color` SET DEFAULT '#4169e1';

-- @down

UPDATE `xcore_user_note` SET `color` = 'purple' WHERE `color` = 'primary';

UPDATE `xcore_mail_template` SET `body_en` = REPLACE(`body_en`, '#4169e1', '#6d4aff'), `body_fr` = REPLACE(`body_fr`, '#4169e1', '#6d4aff') WHERE `body_en` LIKE '%#4169e1%' OR `body_fr` LIKE '%#4169e1%';

ALTER TABLE `xcore_project` ALTER COLUMN `color` SET DEFAULT '#6d4aff';
