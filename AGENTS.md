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
- Routes are declared in route files (config `routes`) or with `@route` annotations on controller methods.
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
- Name api routes `api.<module>.<action>` (ex: `api.auth.login`).
- React backend: sources in `backend/` (TypeScript, MUI, React Router), `npm run build` writes `core/backend/dist/` (committed: projects never need node), served under `/backend/` by the skeleton `.htaccess`. Dev: `npm run dev`, `/api` is proxied to the local site.
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
