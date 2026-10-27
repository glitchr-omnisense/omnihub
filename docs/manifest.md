# The hub manifest

What a hub client reads first: the Glitch Art Hub window in the Unity
editor (`com.glitchr.hub`), the desktop hub.

```json
{
  "version": 1,
  "name": "My Studio",
  "logo": "https://mystudio.github.io/hub/logo.png",
  "color": "#336699",
  "sources": [
    {"name": "My Studio", "type": "upm", "url": "https://mystudio.github.io/hub", "scopes": ["com.mystudio"]},
    {"name": "A tool", "type": "git", "url": "https://github.com/someone/tool.git"}
  ]
}
```

- `sources`: `upm` (a registry, its scopes, an optional `catalog` - a
  forge's richer listing) or `git` (one package). The first is the hub's own
  registry.
- `games`: where a forge lists its games (a static hub has none).
- `checkout`: `{"device": ..., "token": ...}`, a forge's device flow to buy
  and link an editor. A client shows "Buy" only when it is there; a static
  hub never writes it.

`Omnihub\HubManifest` builds it; `Omnihub\StaticHub::manifest()` for a
static hub.
