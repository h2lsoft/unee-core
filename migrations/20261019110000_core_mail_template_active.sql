-- mail templates: active (an inactive template is not sent by \Core\Mailer::sendTemplate())

ALTER TABLE `xcore_mail_template` ADD COLUMN IF NOT EXISTS `active` enum('yes','no') NOT NULL DEFAULT 'yes' AFTER `xcore_layout_id`;

-- @down

ALTER TABLE `xcore_mail_template` DROP COLUMN IF EXISTS `active`;
