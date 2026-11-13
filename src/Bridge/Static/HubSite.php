<?php

namespace Omnihub\Bridge\Static;

use Omnibridge\Static\Render\CallableRenderer;
use Omnibridge\Static\Route\PageList;
use Omnibridge\Static\Site;
use Omnibridge\Static\StaticPage;
use Omnibridge\Static\StaticResponse;
use Omnihub\StaticHub;

/**
 * A static hub as an omnibridge/static site: each of its files an address, exported to a folder
 * GitHub Pages serves (.nojekyll written) - with the files of the hub's repository published
 * beside them (its logo, its kept tarballs), so the export's link check finds them.
 *
 * @suggested omnibridge/static
 */
final class HubSite
{
    /** @param array<string, string> $assets address in the hub => file on disk */
    public static function site(StaticHub $hub, bool $githubPages = true, array $assets = []): Site
    {
        $files = $hub->files();
        foreach ($assets as $address => $path) {
            $files['/'.ltrim($address, '/')] ??= ['application/octet-stream', fn () => (string) file_get_contents($path)];
        }

        return (new Site($hub->url))
            ->githubPages($githubPages && str_ends_with((string) parse_url($hub->url, \PHP_URL_HOST), '.github.io'))
            ->sitemap(false)
            ->provide(new PageList(array_keys($files)))
            ->renderWith(new CallableRenderer(function (StaticPage $page) use ($files) {
                $file = $files[$page->path] ?? null;
                if (null === $file) {
                    return StaticResponse::notFound();
                }

                $body = $file[1] instanceof \Closure ? ($file[1])() : $file[1];

                return match ($file[0]) {
                    'text/html' => StaticResponse::html($body),
                    'application/json' => StaticResponse::json($body),
                    default => StaticResponse::bytes($body),
                };
            }));
    }
}
