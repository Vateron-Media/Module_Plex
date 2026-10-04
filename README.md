# Module_Plex

Plex integration module

XC_VM module distributed as a standalone repository. The panel installs it from
this repo's GitHub releases (see `update` in `module.json`).

## Release

Each release tag equals the `module.json` `version` (plain semver, no `v`).
Pushing a tag runs `.github/workflows/release.yml`, which builds and uploads:

- `module.tar.gz` — the module tree (`module.json` at the archive root)
- `hashes.md5` — `<md5>  module.tar.gz`

Release notes are generated from conventional commits by git-cliff. Full
checklist: [RELEASE.md](RELEASE.md).

## License

AGPL-3.0 — see [LICENSE](LICENSE).
