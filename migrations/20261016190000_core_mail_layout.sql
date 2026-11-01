-- core mail layout: logo on top, footer under the card (company information is written here, in the layout)
-- only a layout never edited from the application is updated

UPDATE `xcore_layout`
SET `content` = '<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f4f5f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f8;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;">
<tr><td style="padding:28px 36px 0;">
<img src="/backend/logo-mail.png" alt="Unee" height="38" style="height:38px;width:auto;display:block;border:0;">
</td></tr>
<tr><td style="padding:20px 36px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1f2335;">
[CONTENT]
</td></tr>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
<tr><td align="center" style="padding:24px 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#6b7088;">
<!-- company information, ex: Company name - address - zip code city<br>phone - <a href="mailto:contact@company.com" style="color:#6b7088;">contact@company.com</a><br> -->
This email was sent automatically, please do not reply.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `name` = 'core.mail' AND `deleted` = 'no' AND `content` NOT LIKE '%logo-mail.png%' AND (`updated_by` IS NULL OR `updated_by` = 'unee-migrate');

-- @down

UPDATE `xcore_layout`
SET `content` = '<!doctype html>
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
</html>', `updated_at` = NULL, `updated_by` = NULL
WHERE `name` = 'core.mail' AND `deleted` = 'no' AND `content` LIKE '%logo-mail.png%' AND `updated_by` = 'unee-migrate';
