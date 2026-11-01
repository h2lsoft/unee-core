-- mailer settings (globals, editable by instance) and the password recovery mail
-- dev: mailpit on localhost:25 catches every mail; production: set the instance smtp in these globals

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'mailer', g.name, g.type, g.description, g.value, NOW(), 'unee-migrate'
FROM (
	SELECT 'mailer.smtp.host' AS name, 'text' AS type, 'smtp host, empty = php mail()' AS description, 'localhost' AS value
	UNION ALL SELECT 'mailer.smtp.port', 'int', 'smtp port', '25'
	UNION ALL SELECT 'mailer.smtp.security', 'text', 'smtp encryption: empty, tls or ssl', ''
	UNION ALL SELECT 'mailer.smtp.username', 'text', 'smtp user, empty = no authentication', ''
	UNION ALL SELECT 'mailer.smtp.password', 'text', 'smtp password', ''
) g
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = g.name AND x.deleted = 'no');

-- default layout of the core mails, [CONTENT] receives the template body
INSERT INTO `xcore_layout` (`category`, `name`, `content`, `created_at`, `created_by`)
SELECT 'mail', 'core.mail', '<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f4f5f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f8;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;">
<tr><td style="padding:32px 36px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1f2335;">
[CONTENT]
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_layout` x WHERE x.name = 'core.mail' AND x.deleted = 'no');

-- password recovery: [[firstname]], [[link]], [[hours]]
INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.password.forgot', 'password recovery link, vars: firstname, link, hours',
'', 'Reset your password',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">We received a request to reset the password of your account. Click the button below to choose a new password. This link is valid for [[hours]] hours and can be used once.</p>
<p style="margin:24px 0;"><a href="[[link]]" style="display:inline-block;padding:10px 20px;border-radius:8px;background:#6d4aff;color:#ffffff;font-weight:bold;text-decoration:none;">Reset my password</a></p>
<p style="margin:0;color:#5b6178;">If you did not ask for it, ignore this email: your password stays unchanged.</p>',
'', 'Réinitialisez votre mot de passe',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">Nous avons reçu une demande de réinitialisation du mot de passe de votre compte. Cliquez sur le bouton ci-dessous pour en choisir un nouveau. Ce lien est valable [[hours]] heures et ne peut servir qu''une seule fois.</p>
<p style="margin:24px 0;"><a href="[[link]]" style="display:inline-block;padding:10px 20px;border-radius:8px;background:#6d4aff;color:#ffffff;font-weight:bold;text-decoration:none;">Réinitialiser mon mot de passe</a></p>
<p style="margin:0;color:#5b6178;">Si vous n''êtes pas à l''origine de cette demande, ignorez cet email : votre mot de passe reste inchangé.</p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.password.forgot' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_mail_template` WHERE `name` = 'core.password.forgot' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_layout` WHERE `name` = 'core.mail' AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_globals` WHERE `name` IN ('mailer.smtp.host', 'mailer.smtp.port', 'mailer.smtp.security', 'mailer.smtp.username', 'mailer.smtp.password') AND `created_by` = 'unee-migrate';
