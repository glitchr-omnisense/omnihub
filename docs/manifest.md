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
  "licensing": {"publicKey": "5Wr6MvD/0ktioPo2QsSyiHKTCPeraqZf94+MwEYZ6cc="}
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
  data only - `publicKey` (32 bytes, standard base64 with its padding).
  After a change of key, `previousKey` and `rotation` too: the previous key's
  Ed25519 signature of the UTF-8 text `forge-key-rotation:v1:` followed by
  `publicKey`. Forge Store pins the first key it reads (trust on first use)
  and moves the pin on a rotation that verifies with the pinned key; any
  other change waits for the user. `Omnihub\Licensing` checks it (the
  rotation with sodium); a static hub's author makes the keys with
  `omnihub key` (README, "Licences signed offline").
- `games`: where a forge lists its games (a static hub has none).
- `checkout`: `{"device": ..., "token": ...}`, a forge's device flow to buy
  and link an editor. A client shows "Buy" only when it is there; a static
  hub never writes it.

`Omnihub\HubManifest` builds it; `Omnihub\StaticHub::manifest()` for a
static hub, from `hub.yaml` through `Omnihub\HubConfig`.
