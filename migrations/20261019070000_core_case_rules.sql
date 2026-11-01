-- existing data follows the form rules: emails and logins in lower case, first and last names with an uppercase initial

UPDATE `xcore_user` SET `email` = LOWER(`email`) WHERE BINARY `email` != LOWER(`email`);
UPDATE `xcore_user` SET `login` = LOWER(`login`) WHERE BINARY `login` != LOWER(`login`);
UPDATE `xcore_user` SET `firstname` = CONCAT(UPPER(LEFT(`firstname`, 1)), SUBSTRING(`firstname`, 2)) WHERE BINARY LEFT(`firstname`, 1) != UPPER(LEFT(`firstname`, 1));
UPDATE `xcore_user` SET `lastname` = CONCAT(UPPER(LEFT(`lastname`, 1)), SUBSTRING(`lastname`, 2)) WHERE BINARY LEFT(`lastname`, 1) != UPPER(LEFT(`lastname`, 1));

UPDATE `xcore_mailinglist_subscriber` SET `email` = LOWER(`email`) WHERE BINARY `email` != LOWER(`email`);
UPDATE `xcore_mailinglist_subscriber` SET `firstname` = CONCAT(UPPER(LEFT(`firstname`, 1)), SUBSTRING(`firstname`, 2)) WHERE BINARY LEFT(`firstname`, 1) != UPPER(LEFT(`firstname`, 1));
UPDATE `xcore_mailinglist_subscriber` SET `lastname` = CONCAT(UPPER(LEFT(`lastname`, 1)), SUBSTRING(`lastname`, 2)) WHERE BINARY LEFT(`lastname`, 1) != UPPER(LEFT(`lastname`, 1));

UPDATE `xcore_blacklist` SET `email` = LOWER(`email`) WHERE BINARY `email` != LOWER(`email`);
UPDATE `xcore_ticket` SET `email` = LOWER(`email`) WHERE BINARY `email` != LOWER(`email`);
UPDATE `xcore_ticket_message` SET `sender_email` = LOWER(`sender_email`) WHERE BINARY `sender_email` != LOWER(`sender_email`);
UPDATE `xcore_newsletter` SET `expeditor_email` = LOWER(`expeditor_email`), `reply_to` = LOWER(`reply_to`), `return_path` = LOWER(`return_path`);

-- @down

-- irreversible: the case before the change is not kept
-- nothing to undo
