# Omnihub

A Unity package hub, written once: the npm registry documents Unity's
Package Manager reads from a scoped registry, the versions and their
tarballs' hashes, and the hub manifest the hub clients read - for a forge
that serves them per request from its database (omnibase/forge), and for a
static hub that writes them once on GitHub Pages. No framework: PHP 8.2,
[omnisense/contracts](https://github.com/glitchr-omnisense/contracts) (the kernel
every omni family builds on: its HTTP kit and usage policies) and Symfony's HTTP
client contracts (interfaces only).

## Installation and a first call

```sh
composer require omnisense/omnihub
```

No account and no network for the documents themselves: a package, its
versions (the package.json and the tarball's address and bytes), and the
registry's answers.

```php
require __DIR__.'/vendor/autoload.php';

use Omnihub\Package;
use Omnihub\Upm\Registry;
use Omnihub\Version;

$package = new Package('com.mystudio.tool', [
    Version::ofTarball('1.0.0', ['displayName' => 'Tool', 'unity' => '2022.3'], 'https://example.org/com.mystudio.tool-1.0.0.tgz', 'the .tgz bytes'),
]);

echo Registry::json(Registry::document($package)), "\n";      // GET /<name>: versions, dist.tarball, shasum, integrity
echo Registry::json(Registry::search([$package], 'com.mystudio')), "\n"; // GET /-/v1/search
echo Registry::json(Registry::all([$package])), "\n";          // GET /-/all
```

A static hub on GitHub Pages needs `symfony/yaml` (to read `hub.yaml`),
`omnihub/github` (packages from GitHub releases; anonymous, or a token of
your own for a higher rate limit) and `omnibridge/static`; the
[template](https://github.com/glitchr-studio/unity-hub-template) has them.
Selling with offline licences needs PHP's sodium extension (bundled with
PHP) and nothing else: no payment account is involved in omnihub.

## A forge

A forge with a database (omnibase/forge) keeps its accounts, licences and
routes, and hands omnihub one `Package` per request, holding only the
versions the caller may install:

```php
$package = new Package($name, $installableVersions, $homepage, $repository,
    displayName: $software->name, description: $tagline);  // used when the package.json has none

Registry::document($package);
Registry::search($listing, $text, $from, $size, publisher: 'mystudio'); // publisher.username, "omnihub" by default
Registry::all($listing);

(new HubManifest('My Studio', [$ownRegistry], $logo, '#336699', $gamesUrl,
    checkout: ['device' => $device, 'token' => $token, 'quote' => $quote, 'purchases' => $purchases, 'methods' => $methods],
    licensing: new Licensing($publicKey, $previousKey, $rotation, registry: $registryUrl, previousKeyValidUntil: $until),
    licenses: ['list' => $list, 'activate' => $activate, 'release' => $release],
))->toArray();   // the keys in the order a forge serves them; HubManifest::scopesOf() for default scopes
```

`Omnihub\Tarball::create()` writes the package's `.tgz` byte for byte as a
forge did (`package/` prefix, sorted, ustar, gzip level 9), so its shasum
does not change.

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
licensed:                                    # optional: the packages sold with a licence, and where to buy one
  - { package: com.mystudio.tool, get: "https://mystudio.example/buy/tool" }
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
licensed:                                    # optional (see below)
  - { package: com.mystudio.tool, get: "https://mystudio.example/buy/tool" }
```

- **The private key never goes into the hub's repository**, nor into
  `hub.yaml`. `key generate` takes the path you give, refuses to write over a
  file and warns when the path is inside a git work tree; `omnihub check`
  refuses a `licensing.privateKey` (or any `secret`/`seed` key), a 64-byte
  private key pasted as `publicKey`, and a private key listed in `copy` -
  without repeating its value. Keep a copy in a password manager: a lost key
  means a new one and a rotation nobody can sign.
- The build writes `licensing.registry` into `hub.json`: the hub's own
  registry, the address its manifest lists first (`sources[0].url`, which
  Forge Store writes as the project's scoped registry) - the address every
  licence names.

#### From the sale to the delivery

```sh
# The buyer paid through your link; you sign their licence, in the hub's folder (./hub.yaml):
vendor/bin/omnihub licence sign ~/.config/omnihub/mystudio.key \
    --package com.mystudio.tool --licensee "Ada <ada@example.org>" \
    --log ~/.config/omnihub/sales.jsonl > ada.licence
# Check it as Forge Store will (exit 1 when it would refuse it):
vendor/bin/omnihub licence verify hub.yaml "$(cat ada.licence)" --package com.mystudio.tool
# Send ada.licence's one line to the buyer: in Forge Store, the package's "Enter a licence".
```

`licence sign <private-key-file> [payload.json|-]` prints the token alone on
stdout (one line, for an e-mail) and a summary on stderr (package, licensee,
"no end" or "until", "any machine" or the machine's hash, the key's
fingerprint as Forge Store shows it) - never the private key. Its flags:

| Flag | |
|---|---|
| `--hub <hub.yaml>` | default `./hub.yaml` when there is one (and no `--registry`): the registry taken from it - the same function as the manifest's `sources[0].url` -, and the key checked against its `licensing.publicKey` ("this key does not sign for this hub: <fingerprint> vs <fingerprint>") |
| `--registry <url>` | without a hub.yaml: https (http only for localhost), a final slash dropped |
| `--package <upm-name>` | the package it opens (a UPM name; a warning when hub.yaml's scopes do not cover it, when hub.yaml installs it from Git, or when its `licensed` list does not name it) |
| `--software <slug>` | a game or an application instead (Forge Seal's `LicenseToken.Check` with its slug): then no `--package` |
| `--licensee <text>` | required: who it was sold to; no control characters, 200 characters at most |
| `--valid-until <YYYY-MM-DD\|unix>` or `--days <n>` | optional; nothing: no end. A day is its first second, UTC - the day Forge Store shows as "until" |
| `--updates-until <YYYY-MM-DD\|unix>` | optional: the releases it covers (Forge Seal's `LicensePayload.Covers`) |
| `--machine <identifier>` | optional: one machine - Unity's `SystemInfo.deviceUniqueIdentifier` as the buyer's Unity reports it, hashed here (SHA-256, lowercase hex, of the identifier trimmed of Unicode white space, in UTF-8: what the clients hash), never written in clear |
| `--machine-hash <64 hex>` | optional: the hash a buyer sent, taken as given (written in lowercase) |
| `--issued-at <YYYY-MM-DD\|unix>` | optional: default now |
| `--log <file.jsonl>` | optional: one line appended per licence - the payload and the key's fingerprint, never the key, the token only with `--log-token`; created 0600, with a warning inside a git work tree (it names your buyers) |

Without `--machine` or `--machine-hash` the licence opens on any machine. A
payload file (or stdin) is still read; the flags override it. What is signed
is checked first, one line per problem: `v` forced to 2, `registry` an
absolute https address, `package` or `software` (one of them), `machine` 64
lowercase hex digits, times JSON integers, `valid_until` after `issued_at`,
no other field. The payload, in omnibase/forge's field order:

```json
{"v": 2, "registry": "https://mystudio.github.io/hub", "package": "com.mystudio.tool",
 "licensee": "Ada <ada@example.org>", "issued_at": 1792972800}
```

| Field | |
|---|---|
| `v` | 2: bound to its store. A format 1 token (no registry) is refused by Forge Store and by Forge Seal's bound checks |
| `software` | a game's slug (then no `package`) |
| `registry` | the hub's registry as clients know it, `sources[0].url`; compared with a final slash, the scheme's and the host's case and a default port aside, the path keeping its case |
| `package` | the UPM name it opens |
| `licensee` | who it was sold to; Forge Store shows "Licensed to ..." |
| `machine` | absent: any machine. SHA-256 hex of the machine's identifier; present and different: refused |
| `issued_at` | UNIX seconds |
| `valid_until` | absent: no end |
| `updates_until` | absent: every release |

`omnihub licence verify <public-key|hub.yaml|hub.json> <token|->` (or
`licence verify <token> --hub <file>` / `--key <base64>`) reads a token as
Forge Store, Forge Seal and Forge Hub check it, in their order and with their
words - signature (the hub's key, or its previous key while
`previousKeyValidUntil` runs), format, registry, package, machine, end - and
says "Forge Store opens it." (exit 0) or "Forge Store refuses it: <why>"
(exit 1). The registry comes from the hub (`hub.yaml`, or a published
`hub.json`) or `--registry`; `--package`, `--software`, `--machine` /
`--machine-hash` and `--at <day>` compare more; what is not given is said not
compared. It takes public keys only: a private key given by mistake is
refused without being printed.

What a static hub's licence checks: the store, the package, an end, and one
machine when the seller asked for it. What it cannot: seats and revocation -
a copied token opens wherever it is pasted. **Light protection, not DRM.**

#### Changing the key

```sh
vendor/bin/omnihub key generate ~/.config/omnihub/mystudio-2.key
vendor/bin/omnihub key rotate ~/.config/omnihub/mystudio.key ~/.config/omnihub/mystudio-2.key [--previous-until 2026-12-31]
```

prints the section to paste: `publicKey` (the new key), `previousKey` (the
old one), `rotation` - the old key's Ed25519 signature of the UTF-8 text
`forge-key-rotation:v1:` + the new key in base64 - and
`previousKeyValidUntil` (UNIX seconds; 30 days from now unless
`--previous-until`). `omnihub check` verifies the rotation (PHP's sodium).
Forge Store then moves its users' pin without asking them, and keeps the old
key for licences until min(`previousKeyValidUntil`, the moment it saw the
rotation + 30 days) - never without `previousKeyValidUntil`. A rotation
covers one step: a user who missed two is asked. Without a valid rotation,
every user must accept the new key by hand.

**Offline licences without an end signed with the old key stop opening in
Forge Store at most 30 days after a rotation.** Sign them again with the new
key - the `--log` file has every payload sold:

```sh
vendor/bin/omnihub licence resign ~/.config/omnihub/mystudio-2.key ~/.config/omnihub/sales.jsonl --log ~/.config/omnihub/sales.jsonl
```

prints one line per licence, `licensee<TAB>package<TAB>token`, each payload
as it was sold, for you to send each buyer their new token; licences that ran
out, and those the new key already signed, are left. A game ships its key:
after a rotation it is released again with the new one.

`omnihub key public <private-key-file>` prints a private key's public key.
The key file is sodium's 64-byte secret key in base64 - the form
omnibase/forge reads from `FORGE_LICENSE_SIGNING_KEY`, so a hub's key can
move to a forge.

#### What is sold: `licensed`

A static hub has no catalogue, so a client cannot tell a paid package from a
free one. `licensed` (optional, in `hub.yaml`) lists the packages sold with a
licence, each with the address where one is bought (`get`, https, optional),
and the build writes it into `hub.json` as it is:

```json
"licensed": [{"package": "com.mystudio.tool", "get": "https://mystudio.example/buy/tool"}]
```

It needs the `licensing` key. The hub's page marks those packages and links
to `get`. Forge Store and Forge Hub do not read it yet (they offer "Enter a
licence" on every package of a store that publishes a key); once they do,
they show "Get" and the licence field only there.

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
contracts, and runs the bare script. PHPStan runs at level max; its baseline is empty. The
package alone (no provider) runs in GitHub Actions: `.github/workflows/ci.yml`
(glitchr-studio/ci's php-package workflow), and `release.yml` on a `v*` tag.

MIT.
