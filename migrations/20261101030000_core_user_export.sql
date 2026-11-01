-- csv export of the users grid: right export on the users plugin (UserController calls allowExport())

UPDATE `xcore_plugin` SET `actions` = JSON_ARRAY_APPEND(`actions`, '$', 'export')
WHERE `route_prefix_name` = 'user' AND `deleted` = 'no' AND NOT JSON_CONTAINS(`actions`, '"export"');

-- @down

UPDATE `xcore_plugin` SET `actions` = JSON_REMOVE(`actions`, JSON_UNQUOTE(JSON_SEARCH(`actions`, 'one', 'export')))
WHERE `route_prefix_name` = 'user' AND `deleted` = 'no' AND JSON_CONTAINS(`actions`, '"export"');
