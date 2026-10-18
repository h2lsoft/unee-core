-- system info, cron, jobber, entity log and system events move from Tools to Administration (after the existing plugins)

UPDATE `xcore_plugin` p
JOIN `xcore_menu` m ON m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Administration' AND t.deleted = 'no')
SET p.xcore_menu_id = m.id,
    p.position = CASE p.route_prefix_name WHEN 'system-info' THEN 140 WHEN 'cron' THEN 150 WHEN 'jobber' THEN 160 WHEN 'entity-log' THEN 170 WHEN 'event' THEN 180 END,
    p.updated_at = NOW(), p.updated_by = 'unee-migrate'
WHERE p.deleted = 'no' AND p.route_prefix_name IN ('system-info', 'cron', 'jobber', 'entity-log', 'event');
