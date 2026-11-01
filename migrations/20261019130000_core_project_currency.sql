-- projects: currency of the budget (iso 4217 code, default global backend.currency.default)

ALTER TABLE `xcore_project` ADD COLUMN IF NOT EXISTS `currency` char(3) NOT NULL DEFAULT 'EUR' AFTER `budget`;

-- @down

ALTER TABLE `xcore_project` DROP COLUMN IF EXISTS `currency`;
