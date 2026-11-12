# Changelog

## Unreleased

- `github` sources: an owner (organisation or user) and a pattern of
  repository names (`unity-*`, several globs separated by commas), an
  optional `install`; written into `hub.json` as Forge Store reads them, and
  matched as it matches them (`Source::matches()`). `git` sources carry
  `package` and `install` too.
- `theme`: `accent`, `accentDark`, `logo`, `banner` (`Omnihub\Theme`), in
  `hub.yaml` and in the manifest; a picture kept in the hub's repository is
  published with it at the hub's address; `color` and `logo` written too for
  the clients that read no theme.
- `Omnihub\HubConfig`: hub.yaml checked as a whole, every error naming its key
  (`InvalidHubConfig`); `omnihub check <hub.yaml>`.
- `omnihub build --resolve-github [--github-cache=<file>]`: a `github`
  source resolved at build time by omnihub/github (public repositories only);
  without the flag it is left for the clients.
- The files of the hub's repository (logo, theme pictures, kept tarballs)
  are exported by omnibridge/static with the hub (`HubSite::site()`'s
  `$assets`), so its link check finds them; `--strict` also fails on an
  export error.
- The hub clients named as they are now: Forge Store (`io.glitchr.store`),
  Forge Hub.

## 1.0.0

- A Unity package hub written once: the UPM registry documents, the hub
  manifest, a static hub for GitHub Pages, `bin/omnihub build`.
