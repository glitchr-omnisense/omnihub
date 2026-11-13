# Omnihub

A Unity package hub, written once: the npm registry documents Unity's
Package Manager reads from a scoped registry, the versions and their
tarballs' hashes, and the hub manifest the hub clients read - for a forge
that serves them per request from its database (omnibase/forge), and for a
static hub that writes them once on GitHub Pages. No framework: PHP 8.2 and
nothing else.

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

$hub = (new StaticHub('https://mystudio.github.io/hub', 'My Studio'))
    ->add((new TarballSource(['https://github.com/mystudio/tool/releases/download/v1.0.0/com.mystudio.tool-1.0.0.tgz']))->packages());
$hub->writeTo('_site');   // or Omnihub\Bridge\Static\HubSite::site($hub), exported by omnibridge/static
```

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

## Providers

- `omnihub/github`: a repository's published releases and their `.tgz`
  assets (`npm pack`'s tarball); a `github` source's public repositories,
  with `--resolve-github`.
- A forge (omnibase/forge) reads its own database and serves the documents
  per request, licences deciding who sees which version.

## Tests

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install && vendor/bin/phpunit'
```

PHPStan (level 8, `phpstan.neon`): `vendor/bin/phpstan analyse` with
phpstan/phpstan installed beside it.

MIT.
