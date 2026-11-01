-- core groups and system user, fixed ids: code relies on them, names can be changed
-- group 1 superadmin: full access, rights not checked
-- group 2 system: technical account (cron, console), no login
-- client groups (admin, ...) are created by the superadmin from the backend

INSERT INTO `xcore_group` (`id`, `name`, `description`, `priority`, `access_backend`, `access_frontend`, `active`, `created_at`, `created_by`)
SELECT 1, 'Super admin', 'full access, rights not checked', 1, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
WHERE NOT EXISTS (SELECT 1 FROM `xcore_group` WHERE `id` = 1);

INSERT INTO `xcore_group` (`id`, `name`, `description`, `priority`, `access_backend`, `access_frontend`, `active`, `created_at`, `created_by`)
SELECT 2, 'System', 'technical account for cron and console', 2, 'no', 'no', 'yes', NOW(), 'unee-migrate'
WHERE NOT EXISTS (SELECT 1 FROM `xcore_group` WHERE `id` = 2);

-- system user: inactive and empty password hash, password_verify() always fails so no login is possible
INSERT INTO `xcore_user` (`id`, `xcore_group_id`, `avatar`, `language`, `lastname`, `firstname`, `company`, `email`, `login`, `password`, `bio`, `active`, `note`, `created_at`, `created_by`)
SELECT 1, 2, '', 'en', '', 'System', '', 'system@localhost', 'system', '', '', 'no', '', NOW(), 'unee-migrate'
WHERE NOT EXISTS (SELECT 1 FROM `xcore_user` WHERE `id` = 1);

-- @down

DELETE FROM `xcore_user` WHERE `id` = 1 AND `created_by` = 'unee-migrate';
DELETE FROM `xcore_group` WHERE `id` IN (1, 2) AND `created_by` = 'unee-migrate';
