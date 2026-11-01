-- connection journal: user agent of every log, mail to the user on a logon from a new ip ([[firstname]], [[date]], [[ip]], [[browser]])

ALTER TABLE `xcore_log` ADD COLUMN IF NOT EXISTS `user_agent` varchar(255) DEFAULT NULL AFTER `ip`;

INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.logon.new_ip', 'sign in from a new ip address, vars: firstname, date, ip, browser',
'', 'New sign in to your account',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">Your account was signed in on [[date]] from a new ip address: [[ip]] ([[browser]]).</p>
<p style="margin:0;color:#5b6178;">If it was not you, change your password and contact your administrator at once.</p>',
'', 'Nouvelle connexion à votre compte',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">Une connexion à votre compte a eu lieu le [[date]] depuis une nouvelle adresse ip : [[ip]] ([[browser]]).</p>
<p style="margin:0;color:#5b6178;">Si ce n''était pas vous, changez votre mot de passe et contactez immédiatement votre administrateur.</p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.logon.new_ip' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_mail_template` WHERE `name` = 'core.logon.new_ip' AND `created_by` = 'unee-migrate';

ALTER TABLE `xcore_log` DROP COLUMN IF EXISTS `user_agent`;
