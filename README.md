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
logo: logo.png
color: "#336699"
scopes: [com.mystudio]
packages:
  - github: mystudio/tool                    # omnihub/github: the releases' .tgz assets
  - file: tarballs/com.mystudio.small-1.0.0.tgz   # kept in the hub's repository
  - tarball: https://example.org/other-1.0.0.tgz  # anywhere on the web
sources:
  - { name: OpenUPM, type: upm, url: https://package.openupm.com, scopes: [com.cysharp.unitask] }
```

See [docs/static-hub.md](docs/static-hub.md) for what GitHub Pages serves and
how Unity takes it, [docs/manifest.md](docs/manifest.md) for the hub manifest.

## Providers

- `omnihub/github`: a repository's published releases and their `.tgz`
  assets (`npm pack`'s tarball).
- A forge (omnibase/forge) reads its own database and serves the documents
  per request, licences deciding who sees which version.

## Tests

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install && vendor/bin/phpunit'
```

MIT.
