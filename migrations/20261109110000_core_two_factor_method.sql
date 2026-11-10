-- second factor by user: two_factor_method '' (method of the application, globals auth.<application>.two_factor.method), authenticator, sms, email or none
-- mail template core.two_factor.code: sign-in code by email ([[firstname]], [[code]], [[minutes]], [[ip]], [[browser]], [[date]]), never the code in the subject

ALTER TABLE `xcore_user` ADD COLUMN IF NOT EXISTS `two_factor_method` varchar(20) NOT NULL DEFAULT '' AFTER `two_factor_last_step`;

INSERT INTO `xcore_mail_template` (`category`, `xcore_layout_id`, `name`, `description`, `variables`, `sender_en`, `subject_en`, `body_en`, `sender_fr`, `subject_fr`, `body_fr`, `created_at`, `created_by`)
SELECT 'core', (SELECT l.id FROM `xcore_layout` l WHERE l.name = 'core.mail' AND l.deleted = 'no' LIMIT 1), 'core.two_factor.code', 'sign-in code (second factor by email)',
'[[firstname]] [[code]] [[minutes]] [[ip]] [[browser]] [[date]]',
'', 'Your sign-in code',
'<p style="margin:0 0 16px;">Hello [[firstname]],</p>
<p style="margin:0 0 16px;">Here is your code to sign in to the back office:</p>
<p style="margin:0 0 16px;"><span style="display:inline-block;padding:14px 24px;border-radius:10px;background:#ecf0fc;color:#2c4fc4;font-family:Consolas,Menlo,monospace;font-size:32px;letter-spacing:8px;">[[code]]</span></p>
<p style="margin:0 0 24px;">It is valid for [[minutes]] minutes. Never share it, our team will never ask you for it.</p>
<p style="margin:0 0 8px;color:#5b6178;font-size:13px;">Request from [[ip]] ([[browser]]) on [[date]].</p>
<p style="margin:0;color:#5b6178;font-size:13px;">If this was not you, change your password and contact your administrator.</p>',
'', 'Votre code de connexion',
'<p style="margin:0 0 16px;">Bonjour [[firstname]],</p>
<p style="margin:0 0 16px;">Voici votre code pour vous connecter au back-office :</p>
<p style="margin:0 0 16px;"><span style="display:inline-block;padding:14px 24px;border-radius:10px;background:#ecf0fc;color:#2c4fc4;font-family:Consolas,Menlo,monospace;font-size:32px;letter-spacing:8px;">[[code]]</span></p>
<p style="margin:0 0 24px;">Il est valable [[minutes]] minutes. Ne le communiquez jamais, notre équipe ne vous le demandera jamais.</p>
<p style="margin:0 0 8px;color:#5b6178;font-size:13px;">Demande depuis [[ip]] ([[browser]]) le [[date]].</p>
<p style="margin:0;color:#5b6178;font-size:13px;">Si ce n''était pas vous, changez votre mot de passe et contactez votre administrateur.</p>',
NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_mail_template` x WHERE x.name = 'core.two_factor.code' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_mail_template` WHERE `name` = 'core.two_factor.code' AND `created_by` = 'unee-migrate';

ALTER TABLE `xcore_user` DROP COLUMN IF EXISTS `two_factor_method`;
