<?php

namespace Omnihub;

/**
 * Packages from tarballs named by their address - a release asset, any .tgz on the web: each
 * downloaded once (PHP's streams, nothing else), its package.json read, its hashes taken.
 */
final class TarballSource implements PackageSource
{
    /** @var \Closure(string): string */
    private \Closure $fetch;

    /**
     * @param list<string|array{url: string, changelog?: string, date?: string}> $tarballs
     * @param (callable(string): string)|null                                  $fetch an address's bytes
     */
    public function __construct(private readonly array $tarballs, ?callable $fetch = null)
    {
        $this->fetch = $fetch ? $fetch(...) : self::download(...);
    }

    /** An address' bytes, through PHP's streams (redirections followed). */
    public static function download(string $url): string
    {
        $bytes = @file_get_contents($url, false, stream_context_create(['http' => ['follow_location' => 1, 'user_agent' => 'omnihub']]));
        if (false === $bytes) {
            throw new \RuntimeException(\sprintf('Cannot download "%s".', $url));
        }

        return $bytes;
    }

    public function packages(): iterable
    {
        $byName = [];
        foreach ($this->tarballs as $entry) {
            $entry = \is_string($entry) ? ['url' => $entry] : $entry;
            $bytes = ($this->fetch)($entry['url']);
            $manifest = Tarball::manifest($bytes) ?? throw new \RuntimeException(\sprintf('"%s" has no package.json.', $entry['url']));
            $name = (string) ($manifest['name'] ?? throw new \RuntimeException(\sprintf('The package.json of "%s" has no name.', $entry['url'])));
            $byName[$name][] = Version::ofTarball((string) ($manifest['version'] ?? '0.0.0'), $manifest, $entry['url'], $bytes, isset($entry['date']) ? new \DateTimeImmutable($entry['date']) : null, $entry['changelog'] ?? null);
        }
        foreach ($byName as $name => $versions) {
            yield new Package($name, $versions);
        }
    }
}
