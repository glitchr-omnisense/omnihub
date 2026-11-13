<?php

namespace Omnihub;

use Omnihub\Http\Downloader;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Packages from tarballs named by their address - a release asset, any .tgz on the web: each
 * downloaded once, its package.json read, its hashes taken.
 *
 * Given an HTTP client (symfony/http-client's, or any of Symfony's contracts), the downloads go
 * through omnisense/contracts' kit (Http\Downloader): sent once, a timeout, omnihub's
 * User-Agent, a host's Retry-After honoured, errors without the address' query. Without one,
 * PHP's streams, as omnihub always did (download()): for a core installed with no HTTP client.
 */
final class TarballSource implements PackageSource
{
    /** @var \Closure(string): string */
    private \Closure $fetch;

    /**
     * @param list<string|array{url: string, changelog?: string, date?: string}> $tarballs
     * @param HttpClientInterface|(callable(string): string)|null                $fetch    the client the tarballs are downloaded with, or a callable of an address giving its bytes
     */
    public function __construct(private readonly array $tarballs, HttpClientInterface|callable|null $fetch = null)
    {
        $this->fetch = match (true) {
            $fetch instanceof HttpClientInterface => (new Downloader($fetch))(...),
            null !== $fetch => $fetch(...),
            default => self::download(...),
        };
    }

    /**
     * An address' bytes, through PHP's streams (redirections followed): the download of a core
     * with no HTTP client. Give TarballSource an HTTP client instead (Http\Downloader).
     */
    public static function download(string $url): string
    {
        $bytes = @file_get_contents($url, false, stream_context_create(['http' => ['follow_location' => 1, 'user_agent' => Http\JsonClient::USER_AGENT]]));
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
            $name = $manifest['name'] ?? null;
            if (!\is_scalar($name)) {
                throw new \RuntimeException(\sprintf('The package.json of "%s" has no name.', $entry['url']));
            }
            $version = $manifest['version'] ?? '0.0.0';
            $byName[(string) $name][] = Version::ofTarball(\is_scalar($version) ? (string) $version : '0.0.0', $manifest, $entry['url'], $bytes, isset($entry['date']) ? new \DateTimeImmutable($entry['date']) : null, $entry['changelog'] ?? null);
        }
        foreach ($byName as $name => $versions) {
            yield new Package((string) $name, $versions);
        }
    }
}
