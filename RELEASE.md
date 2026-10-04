# Module_Plex Release Preparation Checklist

Step-by-step guide for preparing and publishing a release of the `plex` module.

How releases work here: the panel's weekly cron (`cron:module_updates`) reads this
repo's releases, and `ModuleManager::updateModuleFromSource()` downloads the asset
**`module.tar.gz`** at the tag **equal to the new version** (md5-verified via
`hashes.md5`). The GitHub Actions workflow (`.github/workflows/release.yml`) builds
and attaches both assets automatically on tag push — no manual builds are needed.

---

## 1. Prepare Release Baseline

Finish all feature/fix work and make sure it is already in `main`.

Set the version variable once and reuse it in all commands below:

```bash
VERSION="X.Y.Z"
```

> ⚠️ The git tag must be the **bare semver** (`1.1.0`, no `v` prefix) — the panel
> downloads the asset at the tag `== module.json version`.

---

## 2. Bump the Version — It Lives in TWO Places

The module declares its version twice. **Keep them identical:**

| Place | What to edit |
| --- | --- |
| `module.json` | `"version": "X.Y.Z"` |
| `PlexModule.php` | `getVersion()` return value |

```bash
sed -i "s/\"version\": *\"[0-9.]*\"/\"version\": \"${VERSION}\"/" module.json
sed -i "s/return '[0-9.]*'; *$/return '${VERSION}';/" PlexModule.php
grep -n "$VERSION" module.json PlexModule.php   # verify both hit
```

Also check:

- [ ] `requires_core` in `module.json` still matches the minimum core version the
      module actually needs (bump it if this release uses newer core APIs).
- [ ] `dependencies` still lists `watch` — and if this release relies on newer
      `watch` APIs (e.g. `WatchService`), make sure that `watch` version is
      released first.
- [ ] `hash_id` is **untouched** — it is the module's permanent identity
      (identity-pinned on update; the panel rejects an archive whose `hash_id`
      differs from the installed one). Never regenerate it.

---

## 3. Pre-Release Validation

**Syntax check** (module has no own CI test suite — lint locally):

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \; | grep -v 'No syntax errors' || echo OK
```

**Local build sanity check** — same target CI runs on the tag:

```bash
make release
tar -tzf module.tar.gz | sort | head -30   # module.json must be at the archive root
md5sum -c hashes.md5
make clean
```

Verify the archive contains **no** `.git/`, `.github/`, `graphify-out/`, `Makefile`,
`README.md`, `LICENSE`, `RELEASE.md` — the Makefile excludes them.

**Live test in a panel** (recommended for sync or settings changes): copy the
tree into a dev panel as `src/Modules/plex_20cd9/` (`{name}_{hash5}`, hash5 =
first 5 chars of `hash_id`), with the `watch` module installed, run
`php console.php status`, open the Plex pages, save the settings, and run a sync
against a test Plex server.

---

## 4. Changelog / Release Notes

Notes are generated automatically on tag push by
[git-cliff](https://git-cliff.org) (default config) from the conventional commits
since the previous tag; non-conventional commits are skipped. Preview locally:

```bash
npx git-cliff --unreleased --strip header
```

> 💡 To write the notes by hand instead, **create the GitHub release with notes
> first** — the workflow is idempotent and will only upload assets to it.

---

## 5. Single Release Commit

```bash
git add module.json PlexModule.php
git commit -m "chore: prepare release ${VERSION}"
git push
```

---

## 6. Tag and Publish

```bash
git tag "${VERSION}"
git push origin "${VERSION}"
```

GitHub Actions (`release.yml`) will then:

- run `make release` (builds `module.tar.gz` + `hashes.md5`),
- create the release for the tag with generated notes if it doesn't exist,
- upload/overwrite both assets (`--clobber`).

> ✅ Wait for the Actions run to finish, then check
> [Releases](https://github.com/Vateron-Media/Module_Plex/releases).

---

## 7. Post-Release

- [ ] Both assets (`module.tar.gz`, `hashes.md5`) are attached and downloadable.
- [ ] `md5sum -c hashes.md5` passes on the downloaded pair.
- [ ] Generated release notes read well (edit the release description if needed).
- [ ] On a panel with the previous version installed: the **Update to X.Y.Z**
      button appears (weekly cron `cron:module_updates`, or trigger the check
      manually) and the update completes — the panel backs up, replaces files,
      and rolls back automatically on failure.
- [ ] Close related issues/milestones.

---

## Command Reference

| Command | Purpose |
| --- | --- |
| `make release` | Build `module.tar.gz` + `hashes.md5` locally (same as CI) |
| `make clean` | Remove locally built release assets |
| `npx git-cliff --unreleased --strip header` | Preview the release notes |
| `git tag ${VERSION} && git push origin ${VERSION}` | Trigger the CI release build |
