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
```

`omnihub check hub.yaml` reads it without building: every error at once,
each naming its key (`sources[2].pattern: ...`, `theme.accent: ...`).

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
