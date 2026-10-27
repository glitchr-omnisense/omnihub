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
 * GitHub Pages serves (.nojekyll written).
 */
final class HubSite
{
    public static function site(StaticHub $hub, bool $githubPages = true): Site
    {
        $files = $hub->files();

        return (new Site($hub->url))
            ->githubPages($githubPages && str_ends_with((string) parse_url($hub->url, \PHP_URL_HOST), '.github.io'))
            ->sitemap(false)
            ->provide(new PageList(array_keys($files)))
            ->renderWith(new CallableRenderer(function (StaticPage $page) use ($files) {
                $file = $files[$page->path] ?? null;
                if (null === $file) {
                    return StaticResponse::notFound();
                }

                return match ($file[0]) {
                    'text/html' => StaticResponse::html($file[1]),
                    'application/json' => StaticResponse::json($file[1]),
                    default => StaticResponse::bytes($file[1]),
                };
            }));
    }
}
