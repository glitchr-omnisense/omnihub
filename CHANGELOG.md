# Changelog

## Unreleased

- Offline licences that Forge Store 1.5.0, Forge Seal 1.1.0 and Forge Hub 1.3.1
  accept (format 2, bound to the hub's registry and the package):
  `omnihub licence sign <private-key-file> [payload.json|-]` takes `--hub`
  (default `./hub.yaml`: its registry, and the key checked against its
  `licensing.publicKey`) or `--registry`, `--package` or `--software`,
  `--licensee`, `--valid-until`/`--days`, `--updates-until`, `--machine`
  (hashed as the clients hash Unity's device identifier) or `--machine-hash`,
  `--issued-at`, `--log <file.jsonl>` (`--log-token`); `v` forced to 2, the
  payload in omnibase/forge's field order and checked before signing (one line
  per problem), the token alone on stdout, a summary on stderr. A payload
  file or stdin is still read, the flags overriding it; a misspelt flag is
  refused. `Omnihub\OfflineLicence` (the payload, its rules, the machine's
  hash, the registry comparison), `Omnihub\LicenceCheck` (the clients' check,
  their order and words), `Omnihub\LicenceLog`.
- `omnihub licence verify <public-key|hub.yaml|hub.json> <token|->` (or
  `<token> --hub|--key`): what Forge Store would say, exit 1 when it refuses;
  public keys only.
- `omnihub licence resign <new-private-key-file> <log.jsonl>`: every logged
  licence signed again with the new key after a rotation.
- `omnihub key rotate ... [--previous-until <day|unix>]` writes
  `previousKeyValidUntil` (30 days from now by default).
- `hub.json`'s `licensing` carries `registry` (the hub's registry,
  `sources[0].url`, from one function: `StaticHub::registry()`,
  `HubConfig::registry()`) and, after a rotation, `previousKeyValidUntil`;
  `Licensing::KEYS` accepts both (a `registry` in `hub.yaml` must be the
  hub's own).
- `licensed` in `hub.yaml` and `hub.json`: the packages sold with a licence and
  their "get" link, for the clients to show "Get" only there; the hub's page
  links them.
- PHPStan's baseline is gone: the licensing files' findings are fixed.

- On omnisense/contracts (`^1.0@dev`), the kernel of the omni families, and
  `symfony/http-client-contracts`: every request through the kit's HTTP client
  (`Omnihub\Http\JsonClient`, `Omnihub\Http\Downloader`) - sent once
  (`Http\NoRetry`: no retry by the application's client), a timeout (30 s idle
  for an API call, 60 s for a tarball), the User-Agent
  `omnihub (+https://github.com/glitchr-omnisense/omnihub)`, a `429` and its
  `Retry-After` holding that host's further requests, errors naming the path
  only, without a token. `TarballSource` takes an HTTP client (or, as before, a
  callable; PHP's streams without either); `bin/omnihub` gives it
  symfony/http-client's when installed, and reads GitHub through one
  `omnihub/github` Api for the whole build (its rate limit, ETags and usage
  policy counting across `github:` entries and sources; `--github-cache` keeps
  the releases' ETags too).
- `Omnihub\Exception\`: `OmnihubException` (the kit's `ExceptionInterface`),
  `ProviderException`, `UnreachableException`, `UsageLimitReachedException`
  (the kit's `UsageLimitReached`).
- `docker/`: the family's harness - the core, omnihub/github and
  omnisense/contracts from their checkouts (or GitHub), `test`, `phpstan`,
  `bare` and bin/omnihub's commands; no Docker network created.
- PHPStan at level max on `src/` and `tests/` (it was level 8 on `src/`),
  without a baseline. `BareTest`: every
  class loads with omnisense/contracts and the HTTP client contracts alone.
- A package.json field that is neither text nor a number (an object as a
  `displayName`, a list as a `name`) is left out of the hub's page and search,
  or refused as a name, rather than cast to "Array".

- `licensing` in `hub.yaml` and in the manifest (`Omnihub\Licensing`): the
  Ed25519 public key a hub's licences are signed with, and after a change of
  key the previous key with its rotation signature - written as Forge Store
  reads and pins it, checked by `omnihub check` (length, standard base64,
  the rotation verified with sodium; a private key refused without being
  repeated). The hub's page shows the key's fingerprint.
- `omnihub key generate|public|rotate` and `omnihub licence sign`
  (`Omnihub\SigningKey`): the author's key pair, the private key written only
  to the path given (0600, never over a file, a warning inside a git work
  tree), a rotation signed by the old key, a licence token in Forge Seal's
  form. A file of `copy` that holds a private key is refused.
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
