-- drive: optional note of a share link (ex: client number), only for its creator: in the lists, the notification and the email of a download

ALTER TABLE `xcore_drive_link` ADD `note` varchar(100) NOT NULL DEFAULT '' AFTER `notify`;

-- mail template not changed by hand: note added under the date of the download
UPDATE `xcore_mail_template` SET
	`variables` = '[[firstname]] [[file]] [[note]] [[date]] [[downloads]] [[url]]',
	`body_en` = REPLACE(`body_en`, '<p style="margin:0 0 24px;">', '<p style="margin:0 0 16px;"><strong>[[note]]</strong></p>\n<p style="margin:0 0 24px;">'),
	`body_fr` = REPLACE(`body_fr`, '<p style="margin:0 0 24px;">', '<p style="margin:0 0 16px;"><strong>[[note]]</strong></p>\n<p style="margin:0 0 24px;">')
WHERE `name` = 'core.drive.link_download' AND `deleted` = 'no' AND `updated_at` IS NULL AND `body_en` NOT LIKE '%[[note]]%';

-- @down

UPDATE `xcore_mail_template` SET
	`variables` = '[[firstname]] [[file]] [[date]] [[downloads]] [[url]]',
	`body_en` = REPLACE(`body_en`, '<p style="margin:0 0 16px;"><strong>[[note]]</strong></p>\n', ''),
	`body_fr` = REPLACE(`body_fr`, '<p style="margin:0 0 16px;"><strong>[[note]]</strong></p>\n', '')
WHERE `name` = 'core.drive.link_download' AND `deleted` = 'no';

ALTER TABLE `xcore_drive_link` DROP `note`;
