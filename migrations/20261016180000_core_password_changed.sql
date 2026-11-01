-- password changed confirmation, sent after a reset: [[firstname]], [[date]], [[ip]]

INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.password.changed', 'password changed confirmation, vars: firstname, date, ip',
'', 'Your password has been changed',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">The password of your account was changed on [[date]] from the ip address [[ip]]. Every open session has been signed out.</p>
<p style="margin:0;color:#5b6178;">If you did not change it, contact your administrator at once.</p>',
'', 'Votre mot de passe a été modifié',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">Le mot de passe de votre compte a été modifié le [[date]] depuis l''adresse ip [[ip]]. Toutes les sessions ouvertes ont été déconnectées.</p>
<p style="margin:0;color:#5b6178;">Si vous n''êtes pas à l''origine de ce changement, contactez immédiatement votre administrateur.</p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.password.changed' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_mail_template` WHERE `name` = 'core.password.changed' AND `created_by` = 'unee-migrate';
