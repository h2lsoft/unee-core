-- globals: name shown next to the unee icon in the backend logo (ignored when backend.logo is set)

INSERT INTO `xcore_globals` (`category`, `name`, `type`, `description`, `value`, `created_at`, `created_by`)
SELECT 'backend', g.name, g.type, g.description, '', NOW(), 'unee-migrate'
FROM (
	SELECT 'backend.logo.text' AS name, 'text' AS type, 'name next to the icon of the logo, empty = Unee' AS description
	UNION ALL SELECT 'backend.logo.color', 'text', 'color of the name of the logo (css color: #hex, rgb(), hsl() or a name), empty = black (white in dark mode)'
	UNION ALL SELECT 'backend.logo.size', 'int', 'font size of the name of the logo in px (8 to 80), empty = proportional to the icon'
) g
WHERE NOT EXISTS (SELECT 1 FROM `xcore_globals` x WHERE x.name = g.name AND x.deleted = 'no');

-- @down

DELETE FROM `xcore_globals` WHERE `name` IN ('backend.logo.text', 'backend.logo.color', 'backend.logo.size') AND `created_by` = 'unee-migrate';
