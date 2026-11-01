-- url redirects: the rule is a regex without delimiters, the # delimiters of the old php regex are removed

UPDATE `xcore_url_redirect` SET `rule` = SUBSTRING(`rule`, 2, CHAR_LENGTH(`rule`) - 2) WHERE `rule` LIKE '#%#' AND CHAR_LENGTH(`rule`) > 1;

-- @down

UPDATE `xcore_url_redirect` SET `rule` = CONCAT('#', `rule`, '#') WHERE `rule` NOT LIKE '#%#';
