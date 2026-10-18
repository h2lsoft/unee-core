-- communication (newsletters entry only, mailing lists, subscribers, blacklist), crm (tickets entry only, messages, templates), saved filters (administration)

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Newsletters', 'normal', 'campaign', 'newsletter', '["list","add","edit","delete"]', 10, 'yes', 'yes', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'newsletter' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Mailing lists', 'normal', 'contact_mail', 'mailinglist', '["list","add","edit","delete"]', 20, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'mailinglist' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Subscribers', 'normal', 'group', 'mailinglist-subscriber', '["list","add","edit","delete"]', 30, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'mailinglist-subscriber' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Blacklist', 'normal', 'block', 'blacklist', '["list","add","edit","delete"]', 40, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Communication' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'blacklist' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Tickets', 'normal', 'support_agent', 'ticket', '["list","add","edit","delete"]', 10, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'CRM' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'ticket' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Ticket messages', 'normal', 'forum', 'ticket-message', '["list","add","edit","delete"]', 15, 'no', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'CRM' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'ticket-message' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Ticket templates', 'normal', 'quickreply', 'ticket-template', '["list","add","edit","delete"]', 20, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'CRM' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'ticket-template' AND x.deleted = 'no');

INSERT INTO `xcore_plugin` (`xcore_menu_id`, `name`, `type`, `icon`, `route_prefix_name`, `actions`, `position`, `visible`, `versioning`, `active`, `created_at`, `created_by`)
SELECT m.id, 'Saved filters', 'normal', 'filter_alt', 'user-filter', '["list","add","edit","delete"]', 130, 'yes', 'no', 'yes', NOW(), 'unee-migrate'
FROM `xcore_menu` m
WHERE m.id = (SELECT MIN(t.id) FROM `xcore_menu` t WHERE t.name = 'Administration' AND t.deleted = 'no')
AND NOT EXISTS (SELECT 1 FROM `xcore_plugin` x WHERE x.route_prefix_name = 'user-filter' AND x.deleted = 'no');
