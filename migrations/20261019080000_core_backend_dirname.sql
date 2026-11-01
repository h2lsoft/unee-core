-- the mail layout points to the logo of the backend by /@backend/ (\Core\Mailer replaces it by config backend.dirname)

UPDATE `xcore_layout` SET `content` = REPLACE(`content`, 'src="/backend/logo-mail.png"', 'src="/@backend/logo-mail.png"') WHERE `content` LIKE '%src="/backend/logo-mail.png"%';

-- @down

UPDATE `xcore_layout` SET `content` = REPLACE(`content`, 'src="/@backend/logo-mail.png"', 'src="/backend/logo-mail.png"') WHERE `content` LIKE '%src="/@backend/logo-mail.png"%';
