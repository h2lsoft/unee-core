-- globals: sentence at the bottom of the public pages (page of a share link), empty = nothing shown

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'backend', 'backend.powered_by', 'text', 'sentence at the bottom of the public pages (share links), empty = nothing shown', 'Powered by Unee', NOW(), 'unee-migrate'
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'backend.powered_by' AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_globals` WHERE `name` = 'backend.powered_by' AND `created_by` = 'unee-migrate';
