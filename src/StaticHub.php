<?php

namespace Omnihub;

use Omnihub\Upm\Registry;

/**
 * A hub without a server: its registry and its manifest as files, for GitHub Pages or any static
 * host. The files, by address:
 *
 *   /-/hub.json        the hub manifest (its own registry as its first source, no checkout)
 *   /-/v1/search       every package (a static host ignores the query string; Unity filters by its scopes)
 *   /-/all             every package, the older listing
 *   /<name>            each package's document; its tarballs stay where they are published (GitHub Release assets)
 *   /                  a page that lists them and says how to add the hub
 *
 * Unity's Package Manager reads a document served as application/octet-stream - what GitHub
 * Pages sends for a file without a known extension (checked against Unity 6, 2026-10-27).
 */
final class StaticHub
{
    /** @var array<string, Package> by name */
    private array $packages = [];

    /**
     * @param string       $url     where it is published: https://<owner>.github.io/<repository>
     * @param list<string> $scopes  the scopes of its packages (their first two name segments when empty)
     * @param list<Source> $sources other sources its manifest lists
     * @param list<array{package: string, get?: string}> $licensed the packages sold with a licence its author signs offline, and where to get one
     */
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly array $scopes = [],
        public readonly array $sources = [],
        public readonly ?string $logo = null,
        public readonly ?string $color = null,
        public readonly ?Theme $theme = null,
        public readonly ?Licensing $licensing = null,
        public readonly array $licensed = [],
    ) {
    }

    /**
     * Its own registry: the address of its first source in the manifest, Forge Store's scoped
     * registry url, the "registry" its manifest's licensing and its licences name - one function,
     * so the three never differ.
     */
    public function registry(): string
    {
        return self::registryOf($this->url);
    }

    /** The registry of a static hub published at $url. */
    public static function registryOf(string $url): string
    {
        return OfflineLicence::normalizeRegistry($url);
    }

    /** @param iterable<Package> $packages */
    public function add(iterable $packages): self
    {
        foreach ($packages as $package) {
            if ($package->versions) {
                $this->packages[$package->name] = $package;
            }
        }
        ksort($this->packages);

        return $this;
    }

    /** @return list<Package> */
    public function packages(): array
    {
        return array_values($this->packages);
    }

    public function manifest(): HubManifest
    {
        $scopes = $this->scopes;
        if (!$scopes) {
            foreach ($this->packages as $package) {
                $scopes[] = implode('.', \array_slice(explode('.', $package->name), 0, 2));
            }
            $scopes = array_values(array_unique($scopes));
        }
        $base = $this->registry();
        // A picture kept in the hub's repository is published with it: its address is the hub's.
        $address = fn (string $picture) => preg_match('~^https?://~', $picture) ? $picture : $base.'/'.ltrim($picture, '/');

        return new HubManifest(
            $this->name,
            [new Source(Source::UPM, $base, $this->name, $scopes), ...$this->sources],
            $this->logo ? $address($this->logo) : null,
            $this->color,
            theme: $this->theme?->withPictures($address),
            licensing: $this->licensing?->withRegistry($base),
            licensed: $this->licensing ? $this->licensed : [],
        );
    }

    /** @return array<string, array{0: string, 1: string}> address => [content type, body] */
    public function files(): array
    {
        $files = [
            '/-/hub.json' => ['application/json', $this->manifest()->json()],
            // Paths without extension: served as bytes, which the registry clients read as JSON.
            '/-/v1/search' => ['application/octet-stream', Registry::json(Registry::search($this->packages))],
            '/-/all' => ['application/octet-stream', Registry::json(Registry::all($this->packages))],
        ];
        foreach ($this->packages as $package) {
            $files['/'.$package->name] = ['application/octet-stream', Registry::json(Registry::document($package))];
        }
        $files['/'] = ['text/html', $this->page()];

        return $files;
    }

    private function page(): string
    {
        $e = fn (?string $text) => htmlspecialchars((string) $text, \ENT_QUOTES);
        $text = fn (mixed $value): ?string => \is_scalar($value) ? (string) $value : null;
        $hub = $this->manifest();
        $manifest = $hub->toArray();
        $registry = $hub->sources[0];
        $banner = \is_array($manifest['theme'] ?? null) ? $text($manifest['theme']['banner'] ?? null) : null;
        $logo = $text($manifest['logo'] ?? null);
        $rows = '';
        $licensed = array_column($hub->licensed, null, 'package');
        foreach ($this->packages as $package) {
            $latest = $package->latest();
            $sold = $licensed[$package->name] ?? null;
            $rows .= '<tr><td><code>'.$e($package->name).'</code></td><td>'.$e($text($package->field('displayName', ''))).'</td><td>'.$e($latest?->version).'</td><td>'.$e($text($package->field('description', '')))
                .(null === $sold ? '' : ' <em>'.(isset($sold['get']) ? '<a href="'.$e($sold['get']).'">Licensed: get a licence</a>' : 'Licensed').'</em>').'</td></tr>';
        }
        $scoped = (string) json_encode(['scopedRegistries' => [['name' => $this->name, 'url' => $registry->url, 'scopes' => $registry->scopes]]], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $color = $this->theme?->color() ?? $this->color ?? '#222222';

        return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$e($this->name).'</title>'
            .'<style>body{font:16px/1.5 system-ui,sans-serif;max-width:60rem;margin:2rem auto;padding:0 1rem;color:#222}h1{border-bottom:4px solid '.$e($color).'}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:.4rem;border-bottom:1px solid #ddd;vertical-align:top}pre{background:#f4f4f4;padding:1rem;overflow:auto}</style>'
            .(null !== $banner ? '<img src="'.$e($banner).'" alt="" style="width:100%;height:auto">' : '')
            .'<h1>'.(null !== $logo ? '<img src="'.$e($logo).'" alt="" height="40"> ' : '').$e($this->name).'</h1>'
            .'<p>A Unity package hub. Add it to a project\'s <code>Packages/manifest.json</code>, then install from Window &gt; Package Manager &gt; My Registries:</p>'
            .'<pre>'.$e($scoped).'</pre>'
            .'<p>Or point a hub client at its manifest: <a href="-/hub.json"><code>'.$e($registry->url).'/-/hub.json</code></a>.</p>'
            .($this->licensing ? '<p>Its licences are signed offline by its author; the hub\'s key, as Forge Store shows it: <code>'.$e($this->licensing->fingerprint()).'</code>.</p>' : '')
            .'<table><thead><tr><th>Package</th><th>Name</th><th>Version</th><th>Description</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'</html>';
    }

    /**
     * The files written to a folder directly - where omnibridge/static is not installed (it is the
     * exporter bin/omnihub prefers: Bridge\Static\HubSite). .nojekyll too, for GitHub Pages.
     *
     * @return list<string> the files written, relative to the folder
     */
    public function writeTo(string $directory): array
    {
        $written = [];
        foreach ($this->files() as $address => [$type, $body]) {
            $path = '/' === $address ? 'index.html' : ltrim($address, '/');
            $target = rtrim($directory, '/').'/'.$path;
            if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0777, true) && !is_dir(\dirname($target))) {
                throw new \RuntimeException(\sprintf('Cannot make "%s".', \dirname($target)));
            }
            file_put_contents($target, $body);
            $written[] = $path;
        }
        file_put_contents(rtrim($directory, '/').'/.nojekyll', '');
        $written[] = '.nojekyll';

        return $written;
    }
}
