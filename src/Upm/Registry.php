<?php

namespace Omnihub\Upm;

use Omnihub\Package;
use Omnihub\Version;

/**
 * The npm registry documents Unity's Package Manager reads from a scoped registry, as Unity 6
 * was seen asking (2026-10-26): the search, GET /-/v1/search?text=<scope>&from=0&size=250, page
 * after page until one comes back empty (GET /-/all when the search is a 404); the package
 * document, GET /<name>; then the version's dist.tarball. Shared by a forge, which serves them
 * per request, and a static hub, which writes them once - where a host ignores the query
 * string, the search holds every package, which Unity filters by its scopes.
 */
final class Registry
{
    /**
     * The package document ("packument"): its versions oldest first, dist-tags.latest the newest.
     *
     * @return array<string, mixed>
     */
    public static function document(Package $package, ?string $description = null): array
    {
        $versions = $times = [];
        foreach ($package->versions as $version) {
            $versions[$version->version] = array_merge($version->manifest, [
                'name' => $package->name,
                'version' => $version->version,
                '_id' => $package->name.'@'.$version->version,
                'dist' => array_filter(['tarball' => $version->tarball, 'shasum' => $version->sha1, 'integrity' => $version->integrity()]),
            ]);
            $times[$version->version] = self::time($version->publishedAt);
        }
        $latest = $package->latest();
        $published = array_filter(array_map(fn (Version $v) => $v->publishedAt?->getTimestamp(), $package->versions));

        return array_filter([
            '_id' => $package->name,
            'name' => $package->name,
            'description' => $package->field('description', $description),
            'dist-tags' => $latest ? ['latest' => $latest->version] : new \ArrayObject(),
            'versions' => $versions ?: new \ArrayObject(),
            'time' => ($times + ($published ? ['created' => gmdate('Y-m-d\TH:i:s.000\Z', min($published)), 'modified' => gmdate('Y-m-d\TH:i:s.000\Z', max($published))] : [])) ?: null,
            'keywords' => \is_array($package->field('keywords')) ? array_values($package->field('keywords')) : null,
            'homepage' => $package->homepage,
            'license' => $package->field('license'),
        ], fn ($value) => null !== $value);
    }

    /**
     * One page of the search: the packages whose name, display name, description or keywords
     * hold every word of the text (Unity sends the scope). $publisher is the hub's name for
     * itself in each result's publisher.username (a forge passes its own; Unity shows it).
     *
     * @param iterable<Package> $packages
     *
     * @return array{objects: list<array<string, mixed>>, total: int, time: string}
     */
    public static function search(iterable $packages, string $text = '', int $from = 0, int $size = 250, string $publisher = 'omnihub'): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($text))) ?: []);
        $objects = [];
        foreach ($packages as $package) {
            $latest = $package->latest();
            if (!$latest) {
                continue;
            }
            $keywords = \is_array($package->field('keywords')) ? array_values($package->field('keywords')) : null;
            $text = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
            $haystack = mb_strtolower(implode(' ', [$package->name, $text($package->field('displayName', '')), $text($package->field('description', '')), implode(' ', array_map($text, $keywords ?? []))]));
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $objects[] = [
                'package' => array_filter([
                    'name' => $package->name,
                    'displayName' => $package->field('displayName'),
                    'version' => $latest->version,
                    'description' => $package->field('description'),
                    'keywords' => $keywords,
                    'date' => self::time($latest->publishedAt),
                    'links' => array_filter(['homepage' => $package->homepage, 'repository' => $package->repository]) ?: new \ArrayObject(),
                    'publisher' => ['username' => $publisher],
                    'maintainers' => [],
                ], self::given(...)),
                'score' => ['final' => 1, 'detail' => ['quality' => 1, 'popularity' => 1, 'maintenance' => 1]],
                'searchScore' => 1,
            ];
        }

        return [
            'objects' => \array_slice($objects, max(0, $from), max(1, min(250, $size))),
            'total' => \count($objects),
            'time' => gmdate('D M d Y H:i:s \G\M\TO'),
        ];
    }

    /**
     * The older listing of every package, which the Package Manager reads when the search is not there.
     *
     * @param iterable<Package> $packages
     *
     * @return array<string, mixed>
     */
    public static function all(iterable $packages): array
    {
        $all = ['_updated' => time()];
        foreach ($packages as $package) {
            $latest = $package->latest();
            if (!$latest) {
                continue;
            }
            $all[$package->name] = array_filter([
                'name' => $package->name,
                'description' => $package->field('description'),
                'dist-tags' => ['latest' => $latest->version],
                'versions' => [$latest->version => 'latest'],
                'time' => ['modified' => self::time($latest->publishedAt)],
                'keywords' => \is_array($package->field('keywords')) ? array_values($package->field('keywords')) : null,
            ], self::given(...));
        }

        return $all;
    }

    /**
     * JSON as npm writes it: slashes and accents as they are, an empty object as {}.
     *
     * @param array<mixed> $document
     */
    public static function json(array $document): string
    {
        return json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /** A field the search and the listing write: not null, not an empty text (an empty list of keywords stays, as npm writes it). */
    private static function given(mixed $value): bool
    {
        return null !== $value && '' !== $value;
    }

    private static function time(?\DateTimeInterface $at): string
    {
        return ($at ? \DateTimeImmutable::createFromInterface($at) : new \DateTimeImmutable('@0'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }
}
