-- globals: stylesheets loaded by the backend after its own css to override it

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'backend', 'backend.stylesheets', 'list', 'stylesheets loaded after the backend css to override it (login page included), one url per line: /path/file.css or https://..., empty = none', '', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'backend.stylesheets' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_globals` WHERE `name` IN ('backend.stylesheets') AND `created_by` = 'unee-migrate';
