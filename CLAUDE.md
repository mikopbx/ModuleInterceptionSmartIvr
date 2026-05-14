# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ModuleInterceptionSmartIvr` is an extension module for MikoPBX (an Asterisk-based phone system built on the Phalcon framework). It "intercepts" incoming calls and routes the caller to the employee who is responsible for / last spoke with that phone number, optionally playing a Yandex-synthesized IVR prompt. It is loaded into a running MikoPBX installation, not run standalone.

## Commands

There is no build, lint, or test framework in this repo. PHP is the deployment artifact directly.

- `composer install` — installs the single `cesargb/log-rotation` dependency into `vendor/`. The `mikopbx/core` requirement is satisfied by the host PBX, not pulled here. Run `composer dump-autoload` after adding/moving classes.
- `php -l <file>` — syntax check a PHP file.
- JS sources are in `public/assets/js/src/` (ES6) — **edit only `src/`**. The `public/assets/js/*.js` + `*.js.map` files are generated (ES5) and committed; never hand-edit them. Build via Babel (`--presets airbnb --source-maps`), normally a PHPStorm File Watcher. CSS framework is Semantic/Fomantic UI (not Bootstrap); views are Volt templates.
- `ModuleInterceptionSmartIvr.zip` is the packaged module for installation into MikoPBX.
- **CI/CD**: `.github/workflows/build.yml` calls the shared reusable workflow `mikopbx/.github-workflows/.github/workflows/extension-publish.yml@master` on push to `master`/`develop` — it builds and publishes the release archive. `module.json`'s `version` is the literal `%ModuleVersion%`; the workflow substitutes the real version at build time (baseline `initial_version` is set in `build.yml`). Do not hardcode a numeric version in `module.json`.

## MikoPBX module conventions

This is a MikoPBX extension module loaded into a running PBX. The points below are MikoPBX-wide conventions, adapted to this module.

### Critical prohibitions

- **Never modify files outside the module directory** — `/offload/`, `/usr/www/src/`, other system dirs are off-limits.
- **Never use `rsync` / `cp -r` / `tar` / manual copy to install the module** — it overwrites system files and breaks the module DB and asset symlinks.
- **Never `rm -rf` the installed module directory** — that permanently deletes the module's database (`db/`).
- **Never `git commit` / `git push` without explicit user permission.**

### Installation / deployment

The only supported install path is via `WorkerModuleInstaller` (it preserves the module DB on reinstall). Install path on the PBX: `/storage/usbdisk1/mikopbx/custom_modules/ModuleInterceptionSmartIvr/`.

```bash
# 1. Build the zip from the module root
zip -r ../ModuleInterceptionSmartIvr.zip . -x "*.git*" -x "*tasks.md*" -x "*.DS_Store*" -x "*CLAUDE.md*"
# 2. scp to /tmp/ on the server, then create /tmp/settings.json:
#   { "currentModuleDir": "/storage/usbdisk1/mikopbx/custom_modules/ModuleInterceptionSmartIvr",
#     "filePath": "/tmp/ModuleInterceptionSmartIvr.zip", "uniqid": "ModuleInterceptionSmartIvr" }
# 3. Install (preserves db/):
php -f /usr/www/src/PBXCoreREST/Workers/WorkerModuleInstaller.php start /tmp/settings.json
```

`WorkerModuleInstaller` also (re)creates the `Globals.php` symlinks and the asset symlinks — a manual `scp` of single files skips this and can break them.

### Cache clearing

Required after deploying PHP code (OPcache), changing `Messages/*.php`, or changing Volt templates. Three layers:

```bash
redis-cli -n 4 FLUSHDB                  # Redis DB 4 — translations cache (~1h TTL)
rm -rf /var/tmp/www_cache/volt/*         # compiled Volt templates
php -r "if(function_exists('opcache_reset'))opcache_reset();"   # OPcache — compiled PHP bytecode
```

Without an OPcache reset the server keeps running old PHP. Nginx serves assets with `expires 3d` — hard-refresh (Ctrl+Shift+R) to pick up new CSS/JS.

### Asset symlinks

Assets are served via symlinks under `/usr/www/sites/admin-cabinet/assets/{css,js,img}/cache/ModuleInterceptionSmartIvr/`, created by `PbxExtensionUtils::createAssetsLinks()` on install/enable. They can vanish after a firmware update or manual deploy. Symptom: 404 on `module-interception-smart-ivr-*.js`, MIME-type console errors, blank UI. Fix with `ln -sf`.

### `Globals.php`

Every script in `bin/` and `agi-bin/` starts with `require_once('Globals.php')`. `Globals.php` must be a symlink to `/usr/www/src/Core/Config/Globals.php`, created by `WorkerModuleInstaller`. Recreate after a manual deploy: `ln -sf /usr/www/src/Core/Config/Globals.php <module_dir>/bin/Globals.php`.

### Background worker lifecycle

`ConnectorDB` (`bin/ConnectorDB.php`) is registered via `getModuleWorkers()` and watched by core's `WorkerSafeScriptsCore` cron watchdog, which respawns dead workers within ~1 min. **After a deploy, restart it manually** — it keeps running old code until the watchdog notices:

```bash
ps aux | grep ConnectorDB | grep -v grep | awk '{print $2}' | xargs kill -9
```

IPC is via Beanstalk: other processes call `ConnectorDB::invoke('methodName', [args])`; `ConnectorDB` owns all ORM/DB access.

### DB schema / migrations

- No SQL migration files. Schema is generated from the `@Column` / `@Indexes` PHPDoc annotations on `Models/*` by `Setup/PbxExtensionSetup` → `installDB()`. To change schema, edit the annotations.
- Module tables use the `m_` prefix; the SQLite DB lives in `db/` under the install dir.
- Boolean fields are stored as `string(1)` (`'0'`/`'1'`) — MikoPBX standard (see `disableIvr`, `simpleMode`).
- Force a schema rebuild on a server:
  `php -r "require_once 'Globals.php'; (new \MikoPBX\Core\System\Upgrade\UpdateDatabase())->updateDatabaseStructure();"`

### Logs / debugging

- Module logs go to a per-module dir (`/storage/usbdisk1/mikopbx/log/ModuleInterceptionSmartIvr/` or `/var/log/pbx/...`), one file per class, rotated via `cesargb/log-rotation` (see `Lib/Logger.php`).
- `linkedid` ties together all legs of a call — grep logs by `linkedid` to trace a call; `UNIQUEID` is a single leg.
- An uncaught exception escaping a module script causes MikoPBX to **auto-disable the module** — wrap script/worker logic in try/catch.

### PHP / Phalcon compatibility

Code must run on **PHP 7.4 + 8.x** and **Phalcon 4.x + 5.x** simultaneously.

- Forbidden PHP 8+ syntax: `match`, union types, named args, constructor property promotion, `?->`, `str_contains/str_starts_with/str_ends_with`, enums, readonly. Use `switch`, PHPDoc types, `strpos() !== false`.
- `findFirst()` returns `false` on Phalcon 4 and `null` on Phalcon 5 — always check `if (!$record)`.
- Get version-dependent classes (Di, Logger, Validation, Text) through `Lib/MikoPBXVersion.php` — never import those `\Phalcon\...` classes directly.
- Always use parameterized queries: `['conditions' => 'field = :v:', 'bind' => ['v' => $x]]`.
- Translations (`Messages/*.php`): new keys must be added to at least `en.php` and `ru.php`. Used as `$this->translation->_('key')` in PHP, `{{ t._('key') }}` in Volt, `globalTranslate.key` in JS.

## Architecture

The module has three runtime entry points, all driven by MikoPBX core:

1. **Web UI (Phalcon MVC)** — `App/Controllers/*`, `App/Forms/*`, `App/Views/*.volt`. `ModuleInterceptionSmartIvrController` renders the settings form backed by the `ModuleInterceptionSmartIvr` model (a single settings row). `App/Module.php` registers the Phalcon dispatcher.

2. **Dialplan + AGI script** — `Lib/InterceptionSmartIvrConf.php` (`extends ConfigClass`) generates Asterisk dialplan contexts via `extensionGenContexts()` and hooks incoming routes via `generateIncomingRoutBeforeDial()`. The generated `[interception-smart-ivr]` context runs `agi-bin/smartIVR.php`, which is the per-call logic: it looks up the responsible employee, checks extension status, and either dials them directly (simple mode) or plays a synthesized IVR menu and creates an outgoing `.call` file for the interception bridge.

3. **Background worker** — `bin/ConnectorDB.php` (`extends WorkerBase`) is a long-running Beanstalk listener registered via `getModuleWorkers()`. It owns all DB access for the call-routing data and is invoked cross-process through `ConnectorDB::invoke()` (serializes a request onto a Beanstalk tube, optionally waits for a `PBXApiResult` reply). The AGI script and REST API never touch these models directly — they go through `ConnectorDB::invoke()`.

### Data flow for "who is responsible for this number"

- `HistoryParser::getHistoryData()` walks the PBX CDR table incrementally (tracked by `cdrOffset` in settings), determines the last internal employee who handled each external number, and `ConnectorDB::syncCdrData()` writes that into the `CdrResponsible` model. This sync runs on the worker's ping callback.
- A CRM can also push explicit data via the REST API: `CrmUsers` (employees) and `Responsibles` (client→employee assignments).
- `getResponsibleByPhone()` resolves a caller: in full mode it prefers explicit `Responsibles` joined to `CrmUsers`, falling back to CDR history; in simple mode it uses CDR history only.
- Phone numbers are normalized everywhere to a `phoneId` = last 10 digits (`ConnectorDB::getPhoneIndex()`). Always use this when querying by number.

### REST API

`getPBXCoreRESTAdditionalRoutes()` in `InterceptionSmartIvrConf.php` registers routes under `/pbxcore/api/module-interception-ivr/v1/...`, handled by `Lib/RestAPI/Controllers/ApiController.php`. Each handler just forwards to a `ConnectorDB::invoke()` call. Example `curl` commands are in the docblocks of `ApiController`.

### Models

`Models/*` are Phalcon models extending `ModulesModelsBase`. Schema is defined by the `@Column` / `@Indexes` annotations — MikoPBX creates/migrates the module's DB tables from these annotations at install time. `ModuleInterceptionSmartIvr` still carries several unused example fields (`text_field`, `checkbox_field`, etc.) inherited from the module template.

## Module-specific gotchas

- **Namespace typo**: real code uses `Modules\ModuleInterceptionSmartIvr\...`, but `composer.json` autoload and the dispatcher default namespace in `App/Module.php` say `ModuleInerceptionSmartIvr` (missing "t"). The classes work because `module.json`'s `moduleUniqueID` is the correct spelling and MikoPBX autoloads by that. Don't "fix" one side without the other.
- The module targets PHP 7.4 (`composer.json` platform config) and `min_pbx_version` 2023.2.150 (`module.json`).
- `Models/ModuleInterceptionSmartIvr` still carries unused example fields (`text_field`, `checkbox_field`, etc.) inherited from the module template.
- Most code comments and docblocks are in Russian; keep that convention when editing.
