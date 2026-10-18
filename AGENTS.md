# AGENTS.md - unee-core

Rules for any AI agent or developer working on this repository. Read them before writing code.

## Project

- `h2lsoft/unee-core`: the core of the Unee framework, installed with Composer in `vendor/h2lsoft/unee-core`.
- `h2lsoft/unee-skeleton`: the project template (`composer create-project`). Project files live there, never in the core.
- Stack: PHP 8.4, Symfony 7.4 LTS components (no full-stack framework), MySQL / MariaDB, `h2lsoft/db-manager` (PDO + soft mode), `h2lsoft/validator`, Twig 3.
- Symfony packages stay on 7.x (LTS). Do not add a dependency for a few lines of code.

## Paths and loading

- `APP_PATH`: project root, defined by the project `index.php`. `CORE_PATH`: core package root.
- Core files are referenced with `CORE_PATH`, project and instance files with `APP_PATH`. Never write `APP_PATH."/core/..."`.
- Never edit `vendor/`: change the core in its own repository.
- `index.php` (project) requires `vendor/autoload.php`, `core/bootstrap.php`, then `core/run.php` (request handling).
- `core/bootstrap.php`: constants, core functions, config, class autoloader.
- Core functions live in `core/lib/func/*.php` and are listed one by one in `bootstrap.php` (no glob): a new file must be added to that list.
- Core classes live in `core/lib/class/`, namespace `Core\`, loaded by the custom autoloader (no PSR-4).

## Instances (multi-tenant)

- One project serves several clients: one instance = one folder `instances/<APP_DIR>/` with its own config and database.
- `APP_DIR` comes from the environment (`SetEnvIf Host` in `.htaccess`).
- An instance overrides the core by file location, the instance version is loaded first:
  - models `instances/<x>/model/`, modules `instances/<x>/module/`, commands `instances/<x>/command/`
  - translations: `core/i18n/<lang>.php` then `instances/<x>/i18n/<lang>.php` (instance keys win)
  - views: Twig loader roots are `APP_PATH` then `CORE_PATH`

## Configuration

- Merge order: `core config/default/app.php` < `config/app.php` (project) < `config/app_<env>.php` < `instances/<x>/config/app.php`.
- Merge is recursive: a key overrides, a new key adds.
- Lists (`middlewares`, `routes`, `events`, `commands`) are keyed arrays: same key overrides, new key adds, `null` disables.
- Middleware paths are absolute (`CORE_PATH.'/core/...'`).
- `db` is a flat array (`host`, `database`, `username`...), no package notion.
- Credentials never go in the core: use `config/app_<env>.php` (not versioned) or the instance config.
- Config keys are read with `\Core\Config::get('path/to/key', $default)` (separator `/`).

## Console and migrations

- Console: `php vendor/bin/unee <command> [--instance=name]` (Symfony Console). A command is a class `Command\<Package>\<Name>` in `core/command/<Package>/<Name>.php` (overridable in `instances/<x>/command/`), registered in config `commands`.
- Migrations: `php vendor/bin/unee migrate [--instance=name] [--status]`. Every instance is migrated when `--instance` is omitted.
- Files: `migrations/YYYYMMDDHHMMSS_description.sql` in the core, the project and `instances/<x>/`, applied in this order and recorded in `xcore_migration` as `<scope>/<name>`.
- Never modify an applied migration: a change is a new file. Write re-runnable SQL (`IF NOT EXISTS`): mysql ddl is not transactional. Back up before deploying.
- Users: `php vendor/bin/unee user:create --instance=name [--group=id]`, the password is always asked hidden. Fixed ids: group 1 superadmin (rights not checked), group 2 system, user 1 system (inactive, no login, used as `created_by` by console and cron). Client groups are created by the superadmin.

## Routes

- Rule: every route ends with a slash (`/users/`, `/api/users/{id}/`). Files are the only exception (`/robots.txt`). A route without a final slash throws an exception.
- Routes are declared in route files (config `routes`) or with the symfony attribute `Symfony\Component\Routing\Attribute\Route` on public controller methods (loader `AttributeClassLoader`, symfony/config). Each module controller declares its prefix and name prefix on the class: `#[Route('/api/globals', name: 'api.globals.')]`; method routes are relative (`#[Route('/{id}/', name: 'update', methods: ['PUT'])]` gives `api.globals.update`). Always set `methods` (no methods = any method). Routes declared on a parent class are inherited (`\Core\CrudController`).
- `{id}` and `{page}` are digits unless the route sets its own requirement.
- API documentation: OpenAPI 3.1 built from the routes (`\Core\OpenApi`): summary = first line of the docblock, description = the next lines, a controller adds details with `apiDoc(string $method)` (CrudController: schemas from the grid and the form). `GET /api/openapi.json` and Swagger UI `GET /api/docs/`, superadmin only.
- `.htaccess`: missing final slash redirects with 301, and with 308 under `/api/` (method and body are kept).

## API

- Under `/api/`, the backend (React/MUI) is a client of the api. Authentication uses the PHP session cookie (`HttpOnly`, `SameSite=Lax`), so no token is visible to JavaScript.
- Unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE`) require the header `X-Requested-With: XMLHttpRequest` (csrf protection), otherwise 403.
- Request body: JSON (`Content-Type: application/json`). It is available as `$request->request` and `$_POST`; an invalid JSON body gives 400.
- Errors always use `\Core\JsonResponse::error($code, $message, $extra, $headers)`, which gives `{"error": {"code": 422, "message": "...", "fields": {...}}}`. Exceptions under `/api/` are converted to this format; 5xx messages are hidden unless debug is on.
- Status codes: 400 invalid body, 401 not logged / session expired / account disabled, 403 csrf or forbidden, 404, 405, 422 validation (`fields` = `{name: [messages]}`), 429 throttled (with `Retry-After`).
- Validation: `$this->validator` (h2lsoft/validator ^3.0). `required()` is checked even on an empty body.
- Auth endpoints: `POST /api/auth/login/` (`{"login", "password"}`), `POST /api/auth/logout/` (204), `GET /api/auth/me/`.
- Every `/api/` route requires a logged user (middleware `auth.api`), except the route names listed in config `auth/api/public_routes`. The user and its group are checked in the database on each request; config `auth/api/idle_timeout_seconds` logs out inactive sessions.
- Superadmin (group 1): rights are not checked (`\Model\User::isSuperAdmin()`).
- Rights: a backend module is a row of `xcore_plugin` whose `route_prefix_name` is the folder of the module and `actions` a json array (`["list","add","edit","delete"]`). `\Model\User::hasRight($plugin, $action)` and `getRights($plugin)` fail closed (unknown or inactive plugin = no right); type `core` = every backend user, `normal` = rights of the group in `xcore_group_right`. The group is refreshed from the database on each api request.
- Menu: `GET /api/backend/menu/`, categories (`xcore_menu`) with the visible plugins the user can `list`, names translated. Icons are material symbol names mapped in `backend/src/components/icons.tsx` (unknown = extension).
- CRUD module: a controller extending `\Core\CrudController` declares `$table`, `$title`, `$object_label`, `grid(\Component\DataGrid)` and `form(\Component\Form, $record)`, and gets `GET /api/<module>/` (grid json), `GET /api/<module>/form/`, `GET|PUT|DELETE /api/<module>/{id}/`, `POST /api/<module>/`, `POST /api/<module>/batch/delete/` (`{"ids"}`), `PATCH /api/<module>/{id}/toggle/` (boolean columns declared with toggle). Rights: list, add, edit, delete. Hooks: `rulesOnSubmit`, `rulesOnCreate`, `rulesOnUpdate`, `rulesOnDelete`, `getRecord`, `onSaveBefore`, `onSaveAfterCreate`, `onSaveAfterEdit`, `onDeleteAfter`. Writes are logged in `xcore_log` (password, secret and token columns masked). The react page `/backend/<module>/` is generic.
- Batch actions (selection menu of the grid, no button in the header): mass edit of the fields declared with `$grid->batchEdit([...])` (`GET /api/<module>/batch/form/`, `POST /api/<module>/batch/update/` `{"ids", "values"}`, edit right, form rules then `rulesOnSubmit`/`rulesOnUpdate` on each record, all or nothing), delete (delete right), actions of the module `$grid->batchAction('name', 'Label', 'icon', 'right')` handled by `batch<Name>(array $records)` (`POST /api/<module>/batch/<name>/` `{"ids"}`).
- Row and grid actions: `$grid->rowAction('name', 'Label', 'icon', 'right', 'url')` adds an entry to the menu of each row, a link of the backend when `url` is set (`{id}` replaced, ex: `right/?group={id}`, check the right of the target module before declaring it), otherwise `POST /api/<module>/{id}/action/<name>/` handled by `row<Name>(array $record)` (answer `{"message"}` or `{"redirect"}` for a full reload). `$grid->gridAction('name', 'Label', 'icon', 'right', 'Question?', $danger)` adds a button to the grid header, `POST /api/<module>/action/<name>/` handled by `action<Name>(Request $request)`, confirmed first when a question is given.
- Link of a column: `->link('plugin/?search[]=xcore_menu_id||{id}')` after a column makes its value a link to a page of the backend (`{id}`, `{column}` replaced by the row), check the right of the target module before declaring it.
- Grid toggles (`PATCH /{id}/toggle/`) run `rulesOnSubmit`/`rulesOnUpdate` on the record with the new value, like the mass edit: rules that protect a record also protect its switches.
- Tabs of a grid: `$grid->addFilterChips('column', [value => label], $all)`, `$all = false` removes the tab All (the first tab is active by default, ex: plugins by menu). Badge colors can come from a column of the row: `addColBadge('label', 'Label', false, [], 'color')`. `addColChip('label', 'Label', 'class', 'style', 'icon')` shows a mui chip whose color and variant (ex: `success outlined`), inline css and icon come from columns of the row (values of `xcore_list`).
- Form: `->showIf('field', ['value'])` shows a field only for some values of another one, its rules are checked only then. `addTags('name', 'Label', $required, ['suggestions'])` stores a json array of words (sent and read as an array).
- Form layout: `$form->tab('key', 'Label', 'flag')` puts the next fields in a tab (`tab('')` ends the tabs, the active tab goes to the first field in error, flag = country code of `backend/src/components/Flag.tsx`), `addCode('name', 'Label', $required, 'html')` edits source code with CodeMirror (loaded on demand, written as typed), `$form->addAction('name', 'Label', 'icon', 'right')` adds a button to the form of a record: `POST /api/<module>/{id}/action/<name>/` with the values of the form and `tab`, handled by `row<Name>(array $record, Request $request)`.
- Read only view: `$grid->setDetail()` opens `GET /api/<module>/{id}/detail/` (list right) on a click on a row, the controller method `detail(array $record)` returns `{"title", "fields": [{"label", "value", "type": "text|badge|code", "wide"}]}` (ex: logs).
- Modules of the backend shell (core/module/core-backend, namespace `Plugin\Core_Backend`): dashboard (`GET /api/dashboard/`, home page with the tabs Dashboard and Menu, the active tab is kept per browser), menu, plugin, right (own page `backend/src/pages/RightsPage.tsx`, `GET /api/right/?group=`, `PUT /api/right/{group}/` `{"rights": ["<plugin id>:<action>"]}`), list.
- Administration modules (core/module/core): globals, group, user (connect as: right `connect_as`, logged), layout, url-redirect, mail-template (variables of the column `variables` shown as help, declared by the code that creates the template, test mail to the logged user), log (read only, purge: right `purge`). Menu tools: system-info (own page `backend/src/pages/SystemInfoPage.tsx`, `GET /api/system-info/`: php, server, database, values whose name looks like a secret masked).
- Icons: Material Symbols names (https://fonts.google.com/icons), font self hosted in the backend build (`MenuIcon`), checked with `^[a-z0-9_]+$`. Grid columns `addColIcon` (with a color column), `addColColor`, `addColCount`, `addColPosition` (drag and drop when sorted by position, `POST /api/<module>/batch/position/` with the ids in their new order, new records go last). Form fields `addIcon`, `addColor` (#rrggbb).
- `\Component\DataGrid` only sends the declared columns (and id); sort and filters are whitelisted by the declaration, values are bound. `\Component\Form` fields are the whitelist of written columns and give the automatic rules (required, max length, type, options, unique). Never send a password value to the browser (`addPassword`: empty on update = unchanged).
- Grid url (also the api query string): `search[]=column||value` (equal), `search[]=column||operator||value` (contains, eq, !eq, begins, ends, lte, gte, in, `column||empty||`, `column||!empty||`), `search[]=q||text` (search box), the short form on the tabs column is the active tab, `match=any` (default all), `sort`, `dir`, `page`, `per_page`. `q` is reserved: no filter named q.
- Form inputs: `addRadio('name', 'Label', [value => label], $required)` shows radio buttons on one line (value checked against the options). `addHtml('name', 'Label')` edits rich text with TipTap (loaded on demand), the html is sanitized by php (profile `basic` of `app.sanitizer`).
- Uppercase initial: `public array $ucfirst = ['category', 'title', 'name', 'description']` of CrudController capitalizes these fields on save and batch edit (html: first letter of the text), set `[]` or a shorter list when a field is a key (globals, lists, name of a mail template).
- Generic tags: `$form->addTagManager($this->table, 'Tags')` adds the field `_tags` stored in `xcore_tag` (signature = table, record_id) by `\Core\Tag::get/set/suggestions` (CrudController reads, saves, logs and soft deletes them with the record). In the grid: `addColTags()` (chips, `\Core\Tag::sql($table)` gives the subquery) and `addFilterTags()`. `->labels([value => label])` translates the values shown by a badge.
- Hidden plugins (`visible = 'no'`): allowed by the rights and reachable by url, but not listed in the sidebar nor the dashboard menu (`hidden` in the menu api), ex: My tasks opened from the user menu.
- User menu of the header: My profile (`GET|PUT /api/profile/`, same fields and rules as the user module without group, password never sent) and My tasks (module `user-task`, `xcore_user_task`, each user only sees his own tasks).
- Bookmarks of the users: `xcore_user_bookmark`, `GET /api/backend/bookmarks/` (plugins still allowed, in order), `POST /api/backend/bookmarks/` (`{"module"}`, list right), `DELETE /api/backend/bookmarks/{module}/`. The icon next to the title of a grid toggles the bookmark, the menu of the header lists them.
- Saved filters of the grids: `xcore_user_filter` (personal, or shared with the group of the user when `xcore_group_id` is set), `GET /api/backend/filters/?module=`, `POST /api/backend/filters/` (`{"module", "name", "shared", "match", "conditions"}`, list right on the module), `DELETE /api/backend/filters/{id}/` (owner only). Conditions are checked by the grid when applied.
- Translations: english text as key, `ts('text', ['KEY' => value])` with `core/i18n/<lang>.php` then the instance file. Api error messages, grid and form labels are translated by php (locale of the user, of the browser before logon, config `backend.languages`), the react interface by `backend/src/i18n.ts`. `PATCH /api/auth/me/` (`{"language"}`) changes the language of the user.
- Html fields: `\Core\Sanitizer::clean($html, $profile)` (symfony/html-sanitizer, profiles in config `sanitizer`), applied by `Form::addHtml`. Plain text is stored as typed and escaped on output.
- Errors: php errors, uncaught exceptions and 5xx responses are written in `xcore_log` (action `php_error`, config `error_log/level`), file `<tmp_dir>/log/php-errors.log` when the database is down.
- Password recovery: `POST /api/auth/password/forgot/` (`{"login"}`, same answer whether the account exists or not, throttled like the logon) mails a one-time link (mail template `core.password.forgot`), `POST /api/auth/password/reset/` (`{"token", "password", "password_confirmation"}`). Only the sha256 of the token is stored, it expires after `auth/password_reset_token_expiration_hours`.
- A password reset mails a confirmation (template `core.password.changed`) and signs out every open session: the session keeps a fingerprint of the password hash (`auth.password_check`), checked by `auth.api` on each request.
- Production: set `url` in each instance config. The recovery link is built from it and the default comes from `HTTP_HOST`, sent by the client (password reset poisoning).
- Mails: `\Core\Mailer::sendTemplate()` with templates in `xcore_mail_template` (en, fr, other languages fall back to en) and layouts in `xcore_layout` (`[CONTENT]`). `[[vars]]` are html-escaped. Transport from globals `mailer.smtp.host` (empty = php mail()), `mailer.smtp.port`, `mailer.smtp.security` (empty, tls, ssl), `mailer.smtp.username` (empty = no authentication), `mailer.smtp.password`. Dev: mailpit on localhost:25. Mails will go through the jobber (sent outside the request).
- Name api routes `api.<module>.<action>` (ex: `api.auth.login`).
- React backend: sources in `backend/` (TypeScript, MUI, React Router), `npm run build` writes `core/backend/dist/` (committed: projects never need node), served under `/backend/` by the skeleton `.htaccess`. Dev: `npm run dev`, `/api` is proxied to the local site.
- Dialogs (lightboxes) of the backend: a close cross at the top right, no Cancel button, a click on the backdrop does not close them, shown at the top of the screen.
- Login page settings: globals `backend.logo`, `backend.login.image`, `backend.login.title`, `backend.login.subtitle`, `backend.maintenance.message` (warning under the form, empty = hidden), exposed by the public route `GET /api/auth/config/` (whitelist only).

## Models

- Every model declares its table: `public static string $table = 'xcore_user';` (or inherits it from a model that does). A model without its own table throws an exception.

## Security

- Visitor IP: `getVisitorIp()` only. Forwarded headers are read only from proxies listed in config `proxy/trusted`.
- HTML: use `\Core\Html` helpers, attributes are escaped. Tag content (`$text`, `$label`) is raw HTML: escape user data before passing it.
- Passwords: 8 to 72 bytes, all characters allowed (bcrypt limit).
- Failed logons are logged in `xcore_log` (`logon_failed`), counted per login and per ip (`auth/logon_throttle`).
- Never execute code stored in the database.
- Session cookie is `secure`, `HttpOnly`, `SameSite=Lax`.

## Database conventions

- Core tables are prefixed `xcore_`, singular, snake_case: `xcore_user`, `xcore_mail_template`.
- Primary key: `id bigint(20) unsigned NOT NULL AUTO_INCREMENT`.
- Foreign key: `<full table name>_id` with the primary key type (`bigint(20) unsigned`): `xcore_user_id`.
- Foreign key with a role: `<role>_<table>_id`: `locked_xcore_user_id`. Parent in the same table: `<table>_id`.
- Soft mode columns, always last: `deleted enum('yes','no') NOT NULL DEFAULT 'no'`, `created_at`, `created_by`, `updated_at`, `updated_by`, `deleted_at`, `deleted_by` (`*_at datetime DEFAULT NULL`, `*_by varchar(255) DEFAULT NULL`). They are filled by the db manager.
- Booleans: `enum('yes','no') NOT NULL DEFAULT 'yes'|'no'` (`active`, `visible`, `locked`...).
- Display order: `position` unsigned integer, default 0, indexed.
- Free classification: `category`, `type`, `status` as `varchar(255)`.
- Language: `language char(5)`.
- Business columns are `NOT NULL` with an empty default; optional dates are `datetime DEFAULT NULL`.
- JSON is stored in `TEXT`.
- Log tables are append-only (no `updated_*` / `deleted_*`).
- Indexes are named after their column; `deleted` is always indexed.
- Unique values with soft delete: add `deleted_key tinyint(1) GENERATED ALWAYS AS (if(deleted = 'no',1,NULL)) VIRTUAL` and `UNIQUE KEY name (name, deleted_key)`. Never write `deleted_key` (exclude it when re-inserting a read record).
- Charset `utf8mb4`, collation `utf8mb4_unicode_ci` everywhere. No `ENGINE` clause: the DBMS default engine is used.

## Code style (PHP)

- Variables: lowercase with underscores (`$user_id`). Classes: PascalCase. Methods and functions: camelCase.
- Braces on their own line; no space after control keywords: `if(`, `foreach(`, `while(`.
- Indentation with tabs, Windows line endings (CRLF) in the working copy.
- Comments in English, lowercase.
- Never use the em dash character, use `-`.

```php
// load instance config
if(file_exists($config_file))
{
	$tmp = require $config_file;
	$config = array_replace_recursive($config, $tmp);
}
```

## Workflow

- Target PHP 8.4: code must run without deprecation on 8.4.
- Change one file at a time and get it validated before writing the next one.
- Test before committing; never push without explicit approval.
