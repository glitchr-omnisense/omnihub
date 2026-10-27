# A static hub

A static host serves files; Unity's Package Manager asks a registry for
addresses. What a static hub writes, and why it works:

| Address | File | Served as |
|---|---|---|
| `/-/hub.json` | `-/hub.json` | `application/json` |
| `/-/v1/search?text=<scope>&from=0&size=250` | `-/v1/search` | `application/octet-stream` |
| `/-/all` | `-/all` | `application/octet-stream` |
| `/<name>` | `<name>` | `application/octet-stream` |
| a version's tarball | where it is published: a GitHub Release asset, or `tarballs/` in the hub's repository | `application/gzip` |

- **Content type.** A static host - GitHub Pages included - serves a file
  without a known extension as `application/octet-stream`. Unity's Package
  Manager reads it as JSON all the same (checked against Unity 6000.0, a
  package document and the search so served: listed and installed).
- **Query string.** A static host ignores it: `/-/v1/search?text=...&from=1`
  is the same file as `from=0`. The search holds every package of the hub;
  Unity filters what it gets by its scopes, and asks a second page, which
  gives the same packages again - harmless while the hub has fewer than 250
  packages.
- **A package's document and its folder.** `/<name>` is a file, so nothing
  may live under `/<name>/`: the tarballs are elsewhere.
- **Scopes.** A project maps each scope to one registry. Give the hub scopes
  of its own (`com.mystudio`), or the most specific ones
  (`com.mystudio.tool`) when another registry has `com.mystudio`.

The export is omnibridge/static's: a JSON document at a path without
extension is answered as bytes (`StaticResponse::bytes()`), which it exports
at its path; `.nojekyll` is written for GitHub Pages.
