-- maintenance message of the login page: backend.login.notice renamed backend.maintenance.message (value kept), backend.login.notice_severity removed (always a warning)

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'backend', 'backend.maintenance.message', 'text', 'maintenance information shown under the login form (plain text), empty = hidden, ex: Scheduled maintenance on Saturday from 8pm to 10pm', COALESCE((SELECT o.value FROM `xcore_globals` o WHERE o.name = 'backend.login.notice' AND o.deleted = 'no' LIMIT 1), ''), NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'backend.maintenance.message' AND x.deleted = 'no');

UPDATE `xcore_globals` SET `deleted` = 'yes', `deleted_at` = NOW(), `deleted_by` = 'unee-migrate' WHERE `name` IN ('backend.login.notice', 'backend.login.notice_severity') AND `deleted` = 'no';

-- @down

UPDATE `xcore_globals` SET `deleted` = 'no', `deleted_at` = NULL, `deleted_by` = NULL WHERE `name` IN ('backend.login.notice', 'backend.login.notice_severity') AND `deleted` = 'yes' AND `deleted_by` = 'unee-migrate';
UPDATE `xcore_globals` n JOIN `xcore_globals` m ON m.`name` = 'backend.maintenance.message' AND m.`deleted` = 'no' SET n.`value` = m.`value` WHERE n.`name` = 'backend.login.notice' AND n.`deleted` = 'no';
DELETE FROM `xcore_globals` WHERE `name` IN ('backend.maintenance.message') AND `created_by` = 'unee-migrate';
