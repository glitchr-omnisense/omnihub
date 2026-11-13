# The hub manifest

What a hub client reads first: Forge Store, the window in the Unity editor
(`io.glitchr.store`), and Forge Hub, the desktop app.

```json
{
  "version": 1,
  "name": "My Studio",
  "logo": "https://mystudio.github.io/hub/logo.png",
  "color": "#336699",
  "theme": {"accent": "#336699", "accentDark": "#6699cc", "logo": "https://mystudio.github.io/hub/logo.png", "banner": "https://mystudio.github.io/hub/banner.png"},
  "sources": [
    {"name": "My Studio", "type": "upm", "url": "https://mystudio.github.io/hub", "scopes": ["com.mystudio"]},
    {"name": "A tool", "type": "git", "url": "https://github.com/acme/unity-tool.git", "package": "com.acme.tool"},
    {"name": "Acme on GitHub", "type": "github", "owner": "acme", "pattern": "unity-*"}
  ],
  "licensing": {"algorithm": "Ed25519", "publicKey": "5Wr6MvD/0ktioPo2QsSyiHKTCPeraqZf94+MwEYZ6cc=", "registry": "https://mystudio.github.io/hub"},
  "licensed": [{"package": "com.mystudio.tool", "get": "https://mystudio.example/buy/tool"}]
}
```

- `sources`: `upm` (a registry, its scopes, an optional `catalog` - a
  forge's richer listing), `git` (one package; `package` names it,
  `install: true` puts it in every project of the team) or `github` (an
  `owner` and a `pattern` of repository names, `install` as for `git`: the
  client lists the matching repositories with a `package.json`). The first
  is the hub's own registry.
- `theme`: the hub's look, data only - `accent` and `accentDark` (`#rgb` or
  `#rrggbb`, Unity's light and dark skins), `logo` and `banner` (https://
  pictures of 1 MB at most). `color` and `logo`, the older keys, are written
  too (the accent as `#rrggbb`) for the clients that read no theme.
- `licensing`: the Ed25519 public key the hub's licences are signed with,
  data only - `algorithm` (always `Ed25519`, written first, as a forge
  writes it; `hub.yaml` may leave it out) and `publicKey` (32 bytes,
  standard base64 with its padding).
  After a change of key, `previousKey` and `rotation` too: the previous key's
  Ed25519 signature of the UTF-8 text `forge-key-rotation:v1:` followed by
  `publicKey`. Forge Store pins the first key it reads (trust on first use)
  and moves the pin on a rotation that verifies with the pinned key; any
  other change waits for the user. `Omnihub\Licensing` checks it (the
  rotation with sodium); a static hub's author makes the keys with
  `omnihub key` (README, "Licences signed offline").
  - `registry`: the address the hub's licences name (their `registry`) -
    a static hub's own registry, `sources[0].url`, written by the build; a
    forge's `https://<site>/upm`. Forge Store 1.5.0 reads it
    (`HubLicensing.Registry`); the binding itself compares a licence with the
    registry the package is installed from.
  - `previousKeyValidUntil` (UNIX seconds, a JSON integer; after a rotation
    only): until when licences the previous key signed may open. Forge Store
    keeps the previous key until min(that, the rotation seen + 30 days), and
    never without it; `omnihub key rotate --previous-until` writes it (30 days
    from now by default).
- `licensed` (a static hub, optional): the packages sold with a licence its
  author signs offline, each `{"package": <UPM name>, "get": <https address
  where one is bought, optional>}`. Written only with `licensing`. A client
  that reads it offers "Get" and the licence field on those packages only;
  without it (an older hub), on every package of a store that publishes a
  key.
- `games`: where a forge lists its games (a static hub has none).
- `checkout`: `{"device": ..., "token": ...}`, a forge's device flow to buy
  and link an editor, and, when the forge takes payments itself, the
  one-click purchase's addresses after them: `quote` (the price to confirm),
  `purchases`, `methods` (the buyer's saved payment methods). A client shows
  "Buy" only when it is there; a static hub never writes it.
- `licenses`: a forge's licence API, `{"list": ..., "activate": ...,
  "release": ...}` - the key's licences and their machines, and the calls a
  machine activates and releases itself with. A static hub never writes it.

The keys are written in the order a forge serves them: `version`, `name`,
`logo`, `color`, `theme`, `sources`, `games`, `checkout`, `licenses`,
`licensing`, `licensed` (JSON readers do not care; a byte-for-byte snapshot
does). `checkout` needs `device` and `token`, `licenses` its three
addresses, each an `http(s)://` address; `color` must be `#rrggbb`.

`Omnihub\HubManifest` builds it; `Omnihub\StaticHub::manifest()` for a
static hub, from `hub.yaml` through `Omnihub\HubConfig`.
`HubManifest::scopesOf($names)` gives the default scopes of the hub's
own source (the first two segments of each package name, once each).
