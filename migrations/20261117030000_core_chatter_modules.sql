-- chatter on by default for the business and content modules (vote of Nov 17, 2026): tickets, projects, campaigns, newsletters, articles, pages, users
-- the superadmin can still switch it in the module Plugins

UPDATE `xcore_plugin` SET `chatter` = 'yes', `updated_at` = NOW(), `updated_by` = 'unee-migrate'
WHERE `route_prefix_name` IN ('ticket', 'project', 'campaign', 'newsletter', 'article', 'page', 'user') AND `chatter` = 'no' AND `deleted` = 'no';

-- @down

UPDATE `xcore_plugin` SET `chatter` = 'no'
WHERE `route_prefix_name` IN ('ticket', 'project', 'campaign', 'newsletter', 'article', 'page', 'user') AND `updated_by` = 'unee-migrate' AND `deleted` = 'no';
