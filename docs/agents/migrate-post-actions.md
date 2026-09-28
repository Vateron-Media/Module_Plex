# Agent task: move Plex's form saves off core `post.php`

Self-contained brief for a coding agent. Work in this repository (`Module_Plex`). The
core repository is `../XC_VM`; read it for reference, but change it only in the phase
that says so.

## Why

Core's `src/Public/Views/admin/post.php` still knows this module by name:

- it imports `XcVm\Module\Plex\PlexService` at the top;
- `case 'settings_plex'` calls `PlexService::editPlexSettings($rData)`;
- `case 'plex_add'` calls `PlexService::processPlexSync($rData)`.

Core must not depend on a module; until they move, core also has to list them in
`PageAuthorization::MODULE_POST_ACTIONS` (post actions are refused by default). The module already
owns its other admin endpoints through `$router->api(...)` in
`PlexModule::registerRoutes()` (`enable_plex`, `kill_plex`, `plex_sections`, …). Move
the two saves the same way.

## Current contract (keep it)

| Action | Handler | Success response | Failure response |
| --- | --- | --- | --- |
| `settings_plex` | `PlexService::editPlexSettings($data)` | `{"result":true,"location":"settings_plex?status=<n>","status":<n>}` | `{"result":false,"data":…,"status":<n>}` |
| `plex_add` | `PlexService::processPlexSync($data)` | `{"result":true,"location":"plex?status=<n>","status":<n>}` | same shape |

`$data` is the posted form (`RequestManager::getAll()`). Callers:

- `views/settings.php` / `views/settings_scripts.php`: `fetch('post.php?action=settings_plex', …)`;
- `views/library_edit_scripts.php`: `fetch('post.php?action=plex_add', …)`.

## Phase 1: module (this repo)

1. In `PlexController`, add `apiSaveSettings()` and `apiSaveLibrary()`. Each one:
   - **refuses anything but POST**: answer `405` with
     `{"result":false,"status":0,"error":"Method Not Allowed"}`, as `post.php` does.
     State changes must never run from a GET (CSRF via `<img src>`);
   - calls the same service method with `RequestManager::getAll()`;
   - echoes the same JSON as the table above, then `exit()`.
2. In `PlexModule::registerRoutes()`, add:
   - `$router->api('settings_plex_save', [PlexController::class, 'apiSaveSettings'], ['permission' => ['adv', 'folder_watch_settings']]);`
   - `$router->api('plex_library_save', [PlexController::class, 'apiSaveLibrary'], ['permission' => ['adv', 'folder_watch_add']]);`

   Use new action names: core's `post.php` keeps the old ones until phase 2, and a
   collision is refused silently (`beginModuleRegistration`).
3. Point both views at `./api?action=settings_plex_save` / `./api?action=plex_library_save`
   (same `fetch` options, POST `FormData`, `X-Requested-With: XMLHttpRequest`).
4. Bump `module.json` `version` (minor) and set `requires_core` to the core version that
   ships phase 2's cleanup, or leave it until that version is known. Note it in the
   README changelog.
5. Verify:
   - `php -l` on every changed file;
   - in a panel, save Plex settings and add a library: success redirects as before, and
     a failure keeps the page with the error toast;
   - a GET to `./api?action=settings_plex_save` answers 405 and changes nothing;
   - an admin without `folder_watch_settings` gets `{"result":false}`.

## Phase 2: core (`../XC_VM`, separate PR, only after phase 1 is released)

1. Remove `case 'settings_plex'`, `case 'plex_add'` and the `PlexService` import from
   `src/Public/Views/admin/post.php`.
   Drop the same actions from `PageAuthorization::MODULE_POST_ACTIONS`
   (`src/Core/Auth/PageAuthorization.php`), which holds them to the module's
   permissions until then.
2. Coordinate with the Watch module's matching task (`Module_Watchfolder/docs/agents/migrate-post-actions.md`):
   once both have moved, `post.php` has no module imports left.
3. Run `make gates`, `make phpstan`, the unit suite, and the CRAP gate (see core `CLAUDE.md`).

## Rules

- English code comments, only where they explain why.
- Commit with Conventional Commits. Do not push unless the user asks.
- Don't change the JSON contract. The views and any user scripts rely on it.
