-- globals, plugins, lists and url redirects go to the menu configuration (only when they are still in administration)

UPDATE `xcore_plugin` p
INNER JOIN `xcore_menu` a ON a.id = p.xcore_menu_id AND a.name = 'Administration'
INNER JOIN `xcore_menu` c ON c.name = 'Configuration' AND c.deleted = 'no'
SET p.xcore_menu_id = c.id, p.position = 10, p.updated_at = NOW(), p.updated_by = 'unee-migrate'
WHERE p.route_prefix_name = 'globals' AND p.deleted = 'no';

UPDATE `xcore_plugin` p
INNER JOIN `xcore_menu` a ON a.id = p.xcore_menu_id AND a.name = 'Administration'
INNER JOIN `xcore_menu` c ON c.name = 'Configuration' AND c.deleted = 'no'
SET p.xcore_menu_id = c.id, p.position = 20, p.updated_at = NOW(), p.updated_by = 'unee-migrate'
WHERE p.route_prefix_name = 'plugin' AND p.deleted = 'no';

UPDATE `xcore_plugin` p
INNER JOIN `xcore_menu` a ON a.id = p.xcore_menu_id AND a.name = 'Administration'
INNER JOIN `xcore_menu` c ON c.name = 'Configuration' AND c.deleted = 'no'
SET p.xcore_menu_id = c.id, p.position = 30, p.updated_at = NOW(), p.updated_by = 'unee-migrate'
WHERE p.route_prefix_name = 'list' AND p.deleted = 'no';

UPDATE `xcore_plugin` p
INNER JOIN `xcore_menu` a ON a.id = p.xcore_menu_id AND a.name = 'Administration'
INNER JOIN `xcore_menu` c ON c.name = 'Configuration' AND c.deleted = 'no'
SET p.xcore_menu_id = c.id, p.position = 40, p.updated_at = NOW(), p.updated_by = 'unee-migrate'
WHERE p.route_prefix_name = 'url-redirect' AND p.deleted = 'no';
