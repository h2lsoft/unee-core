-- Drive: share links of the files (page /s/<token>/), expiration, password, max downloads, notification of the creator at each download

CREATE TABLE IF NOT EXISTS `xcore_drive_link` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `xcore_drive_file_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `token` char(32) NOT NULL DEFAULT '' COMMENT '128 bits, hex',
  `expires_at` datetime DEFAULT NULL COMMENT 'null = never',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT 'password_hash, empty = none',
  `max_downloads` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `downloads` int(10) unsigned NOT NULL DEFAULT 0,
  `notify` enum('yes','no') NOT NULL DEFAULT 'no' COMMENT 'creator notified at each download (in-app and email)',
  `xcore_user_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'creator',
  `failures` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'wrong passwords in a row',
  `locked_until` datetime DEFAULT NULL COMMENT 'too many wrong passwords',
  `last_download_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `created_at` datetime DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `xcore_drive_file_id` (`xcore_drive_file_id`),
  KEY `xcore_user_id` (`xcore_user_id`),
  KEY `deleted` (`deleted`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- email to the creator of a link at each download
INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `variables`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.drive.link_download', 'download of a file shared by a link of the Drive (to the creator of the link)',
'[[firstname]] [[file]] [[date]] [[downloads]] [[url]]',
'', '[[file]] downloaded',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">The file <strong>[[file]]</strong> you shared by a link was downloaded on [[date]].</p>
<p style="margin:0 0 24px;">Downloads of this link: [[downloads]].</p>
<p style="margin:0;"><a href="[[url]]">Open the Drive</a></p>',
'', '[[file]] téléchargé',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">Le fichier <strong>[[file]]</strong> que vous avez partagé par un lien a été téléchargé le [[date]].</p>
<p style="margin:0 0 24px;">Téléchargements de ce lien : [[downloads]].</p>
<p style="margin:0;"><a href="[[url]]">Ouvrir le Drive</a></p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.drive.link_download' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_mail_template` WHERE `name` = 'core.drive.link_download' AND `created_by` = 'unee-migrate';
DROP TABLE IF EXISTS `xcore_drive_link`;
