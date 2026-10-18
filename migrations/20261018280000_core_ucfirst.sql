-- uppercase initial of category, title, name and description of the existing records (see CrudController::$ucfirst, globals, lists and mail template name excluded)
-- leading spaces and html tags are skipped: the first letter of the text is changed

UPDATE `xcore_group` SET
	`name` = CONCAT(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 2)),
	`description` = CONCAT(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 2));

UPDATE `xcore_plugin` SET
	`name` = CONCAT(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 2));

UPDATE `xcore_menu` SET
	`name` = CONCAT(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 2));

UPDATE `xcore_layout` SET
	`category` = CONCAT(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`category`, CHAR_LENGTH(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`category`, CHAR_LENGTH(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*')) + 2)),
	`name` = CONCAT(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`name`, CHAR_LENGTH(REGEXP_SUBSTR(`name`, '^([[:space:]]|<[^>]*>)*')) + 2)),
	`description` = CONCAT(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 2));

UPDATE `xcore_mail_template` SET
	`category` = CONCAT(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`category`, CHAR_LENGTH(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`category`, CHAR_LENGTH(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*')) + 2)),
	`description` = CONCAT(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 2));

UPDATE `xcore_url_redirect` SET
	`description` = CONCAT(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 2));

UPDATE `xcore_user_task` SET
	`category` = CONCAT(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`category`, CHAR_LENGTH(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`category`, CHAR_LENGTH(REGEXP_SUBSTR(`category`, '^([[:space:]]|<[^>]*>)*')) + 2)),
	`title` = CONCAT(REGEXP_SUBSTR(`title`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`title`, CHAR_LENGTH(REGEXP_SUBSTR(`title`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`title`, CHAR_LENGTH(REGEXP_SUBSTR(`title`, '^([[:space:]]|<[^>]*>)*')) + 2)),
	`description` = CONCAT(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*'), UPPER(SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 1, 1)), SUBSTRING(`description`, CHAR_LENGTH(REGEXP_SUBSTR(`description`, '^([[:space:]]|<[^>]*>)*')) + 2));
