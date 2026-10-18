-- layout names are keys like the mail template names (ex: core.mail): initial put back in lowercase after 20261018280000_core_ucfirst

UPDATE `xcore_layout` SET `name` = CONCAT(LOWER(LEFT(`name`, 1)), SUBSTRING(`name`, 2)) WHERE `name` LIKE '%.%';
