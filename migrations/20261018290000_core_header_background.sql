-- background color of the header of the backend (css color, ex: #1f2335), empty = white

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'backend', 'backend.header.background', 'text', 'background color of the header (css color: #hex, rgb(), hsl() or a name), empty = white', '', NOW(), 'unee-migrate'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = 'backend.header.background' AND x.deleted = 'no');
