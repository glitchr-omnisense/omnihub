# Omnihub

A Unity package hub, written once: the npm registry documents Unity's
Package Manager reads from a scoped registry, the versions and their
tarballs' hashes, and the hub manifest the hub clients read - for a forge
that serves them per request from its database (omnibase/forge), and for a
static hub that writes them once on GitHub Pages. No framework: PHP 8.2,
[omnisense/contracts](https://github.com/glitchr-omnisense/contracts) (the kernel
every omni family builds on: its HTTP kit and usage policies) and Symfony's HTTP
client contracts (interfaces only).

```php
use Omnihub\Package;
use Omnihub\Upm\Registry;
use Omnihub\Version;

$package = new Package('com.mystudio.tool', [
    Version::ofTarball('1.0.0', $packageJson, 'https://example.org/com.mystudio.tool-1.0.0.tgz', $tarballBytes),
]);

Registry::document($package);              // GET /<name>: versions, dist.tarball, dist.shasum, dist.integrity
Registry::search([$package], 'com.mystudio'); // GET /-/v1/search
Registry::all([$package]);                  // GET /-/all
```

## A static hub

```php
use Omnihub\StaticHub;
use Omnihub\TarballSource;
use Symfony\Component\HttpClient\HttpClient;

$hub = (new StaticHub('https://mystudio.github.io/hub', 'My Studio'))
    ->add((new TarballSource(['https://github.com/mystudio/tool/releases/download/v1.0.0/com.mystudio.tool-1.0.0.tgz'], HttpClient::create()))->packages());
$hub->writeTo('_site');   // or Omnihub\Bridge\Static\HubSite::site($hub), exported by omnibridge/static
```

(`HttpClient` is symfony/http-client's; any client of Symfony's contracts will do.)

Or from a configuration, as the GitHub template does:

```sh
vendor/bin/omnihub build hub.yaml _site --url=https://mystudio.github.io/hub
```

```yaml
name: My Studio
scopes: [com.mystudio]
theme:                                       # the hub's look in the hub clients (Forge Store, Forge Hub)
  accent: "#336699"                          # Unity's light skin; #rgb or #rrggbb, quoted
  accentDark: "#6699cc"                      # its dark skin
  logo: logo.png                             # a file of the hub's repository (published with it), or https://
  banner: https://example.org/banner.png     # 1 MB at most, as the logo
packages:
  - github: mystudio/tool                    # omnihub/github: the releases' .tgz assets
  - file: tarballs/com.mystudio.small-1.0.0.tgz   # kept in the hub's repository
  - tarball: https://example.org/other-1.0.0.tgz  # anywhere on the web
sources:
  - { name: OpenUPM, type: upm, url: https://package.openupm.com, scopes: [com.cysharp.unitask] }
  - { name: A tool, type: git, url: https://github.com/acme/unity-tool.git, package: com.acme.tool }
  - { name: Acme on GitHub, type: github, owner: acme, pattern: "unity-*", install: false }
licensing:                                   # the public key the hub's licences are signed with
  publicKey: "<base64>"                      # omnihub key generate <a path outside the repository>
```

`omnihub check hub.yaml` reads it without building: every error at once,
each naming its key (`sources[2].pattern: ...`, `theme.accent: ...`).

### Licences signed offline: `licensing`

A static hub has no server, so it counts no seats and revokes nothing; it can
still sell, with licences its author signs on their own machine and a "get"
link to any shop (Gumroad, a Stripe Payment Link...). The hub declares the
Ed25519 **public** key that signs them, and Forge Store pins it the first
time a user adds the hub:

```sh
vendor/bin/omnihub key generate ~/.config/omnihub/mystudio.key   # the private key: written there only (0600)
```

```yaml
licensing:
  publicKey: "<what key generate printed: 32 bytes, base64>"
```

- **The private key never goes into the hub's repository**, nor into
  `hub.yaml`. `key generate` takes the path you give, refuses to write over a
  file and warns when the path is inside a git work tree; `omnihub check`
  refuses a `licensing.privateKey` (or any `secret`/`seed` key), a 64-byte
  private key pasted as `publicKey`, and a private key listed in `copy` -
  without repeating its value. Keep a copy in a password manager: a lost key
  means a new one and a rotation nobody can sign.
- **Sign a licence**: `omnihub licence sign <private-key-file> <payload.json|->`
  prints a token in Forge Seal's form (`base64url(payload JSON) "."
  base64url(Ed25519 signature)`, as omnibase/forge signs them). The payload is
  yours (for a game, Forge Seal's `LicenseToken.Check` reads `software`,
  `machine`, `valid_until`); what binds a licence to its registry (plan item
  17.2) is not fixed yet.
- **Change the key** with a rotation the old key signs: Forge Store then moves
  its users' pin without asking them.

  ```sh
  vendor/bin/omnihub key generate ~/.config/omnihub/mystudio-2.key
  vendor/bin/omnihub key rotate ~/.config/omnihub/mystudio.key ~/.config/omnihub/mystudio-2.key
  ```

  prints the section to paste: `publicKey` (the new key), `previousKey` (the
  old one) and `rotation`, the old key's Ed25519 signature of the UTF-8 text
  `forge-key-rotation:v1:` + the new key in base64. `omnihub check` verifies
  it (PHP's sodium). A rotation covers one step: a user who missed two is
  asked. Without a valid rotation, every user must accept the new key by hand.
  Either way Forge Store then trusts the new key only: licences signed with
  the old one stop passing and are signed again with the new key.
- `omnihub key public <private-key-file>` prints a private key's public key.
  The key file is sodium's 64-byte secret key in base64 - the form
  omnibase/forge reads from `FORGE_LICENSE_SIGNING_KEY`, so a hub's key can
  move to a forge.

This is light protection, not DRM: a key checked offline can be shared, and
nothing counts or revokes it - that takes a forge.

### A GitHub owner as a source

A `github` source names an organisation or a user (`owner`) and globs for
its repositories' names (`pattern`: `*` any run, `?` one character, several
separated by commas, case ignored); `install: true` puts every package it
finds in each project of the team (Forge Hub's Prepare). It is written into
`hub.json` as it is, and the hub clients resolve it - with the user's own
GitHub token, so a team's private repositories show to its members.

`--resolve-github` (with omnihub/github) resolves it at build time instead:
the owner's **public** repositories that match and have a `package.json` at
their root, each written as a `git` source at its newest version tag. A
private repository is never read, even with a token that could. GitHub's
REST API is read with the last ETag of each answer (`--github-cache=<file>`
keeps them between builds) and nothing is sent once the rate limit is
reached; a source that cannot be resolved is left for the clients. No page
of github.com is scraped.

See [docs/static-hub.md](docs/static-hub.md) for what GitHub Pages serves and
how Unity takes it, [docs/manifest.md](docs/manifest.md) for the hub manifest.

## HTTP: omnisense/contracts' kit

Every request omnihub makes goes through the HTTP kit of
[omnisense/contracts](https://github.com/glitchr-omnisense/contracts), as in
the other omni families (`Omnihub\Http\JsonClient`, `Omnihub\Http\Downloader`):

- **sent once**: the client given is kept from retrying (`Http\NoRetry`), so a
  refusal reaches omnihub and no request goes out that the usage policy did not
  count; omnihub decides what to try again (nothing, today);
- **a timeout** on every request (30 s without a byte for an API call, 60 s for
  a tarball) and **omnihub's User-Agent**,
  `omnihub (+https://github.com/glitchr-omnisense/omnihub)`;
- **a provider's waits honoured**: a `429` and its `Retry-After` (and the
  `RateLimit` fields) hold every further request to that host until the wait
  is over (`Omnihub\Exception\UsageLimitReachedException`, which is the kit's
  `UsageLimitReached`);
- **errors without secrets**: an error status is an
  `Omnihub\Exception\ProviderException` naming the path only (never the query),
  a network failure an `UnreachableException`, both without the token
  (`Http\Redaction`); every one implements `Omnihub\Exception\OmnihubException`
  and the kit's `ExceptionInterface`.

`TarballSource` takes the HTTP client (`new TarballSource($tarballs, $http)`);
`bin/omnihub` gives it symfony/http-client's whenever it is installed
(omnihub/github requires it). Without any HTTP client, a tarball is still read
through PHP's streams (`TarballSource::download()`), as before. GitHub's API,
its rate limit, ETags and usage policy: see omnihub/github.

## Providers

- `omnihub/github`: a repository's published releases and their `.tgz`
  assets (`npm pack`'s tarball); a `github` source's public repositories,
  with `--resolve-github`.
- A forge (omnibase/forge) reads its own database and serves the documents
  per request, licences deciding who sees which version.

## Tests: the harness

`docker/` is the family's harness, as in the other omni families: this core
and every omnihub/* provider (from its checkout beside this one, or from
GitHub), with omnisense/contracts (its checkout, or GitHub), installed together
in a PHP 8.4 image. One service, Docker's default bridge: no network is created.

```sh
cd docker
cp .env.dist .env                       # OMNIHUB_PLUGINS=../.. and OMNISENSE_CONTRACTS=../../../omnisense-contracts in a workspace
docker compose run --rm omnihub test    # every package's PHPUnit suite, contracts' too
docker compose run --rm omnihub phpstan # PHPStan at level max on each package
docker compose run --rm omnihub bare    # a hub built in plain PHP from recorded answers: no class of a framework may load
docker compose run --rm omnihub build /omnihub/core/docker/harness/hub.yaml /harness/_site --url=...   # bin/omnihub itself
```

`tests/BareTest.php` (the kit's `BareTestCase`) checks that every class of the
core loads with nothing but omnisense/contracts and Symfony's HTTP client
contracts, and runs the bare script. PHPStan runs at level max; its baseline
holds only the licensing key code's findings, and may only shrink.

MIT.
