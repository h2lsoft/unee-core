-- globals of the backend login page, empty = default of the backend (unee logo, lock image, no notice)

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'backend', g.name, g.type, g.description, '', NOW(), 'unee-migrate'
FROM (
	SELECT 'backend.logo' AS name, 'url' AS type, 'logo url (backend and login page), empty = unee logo' AS description
	UNION ALL SELECT 'backend.login.image', 'url', 'image of the login page, empty = lock'
	UNION ALL SELECT 'backend.login.title', 'text', 'title of the login page, empty = Welcome to Unee'
	UNION ALL SELECT 'backend.login.subtitle', 'text', 'subtitle of the login page'
	UNION ALL SELECT 'backend.login.notice', 'text', 'information shown under the login form (plain text), empty = hidden'
	UNION ALL SELECT 'backend.login.notice_severity', 'text', 'notice color: info, warning, error or success'
) g
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = g.name AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_globals` WHERE `name` IN ('backend.logo', 'backend.login.image', 'backend.login.title', 'backend.login.subtitle', 'backend.login.notice', 'backend.login.notice_severity') AND `created_by` = 'unee-migrate';
