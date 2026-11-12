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
  ]
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
- `games`: where a forge lists its games (a static hub has none).
- `checkout`: `{"device": ..., "token": ...}`, a forge's device flow to buy
  and link an editor. A client shows "Buy" only when it is there; a static
  hub never writes it.

`Omnihub\HubManifest` builds it; `Omnihub\StaticHub::manifest()` for a
static hub, from `hub.yaml` through `Omnihub\HubConfig`.
