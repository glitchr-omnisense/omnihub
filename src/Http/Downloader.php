<?php

namespace Omnihub\Http;

use Omnihub\Exception\UsageLimitReachedException;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A tarball's bytes from its address - a release asset, any .tgz on the web - through the
 * application's HTTP client and omnihub's JsonClient: redirections followed (Symfony's client
 * does), sent once (no retry), omnihub's User-Agent, a timeout, an error status or a network
 * failure thrown without the address' query, and each host's Retry-After honoured: a 429 is a
 * UsageLimitReachedException and nothing more is asked of that host until the wait is over
 * (the limiter given, shared with other calls, or one of its own).
 *
 * The whole tarball is read into memory, as before: a hub's tarballs are hashed and opened
 * whole (Tarball::read()).
 */
final class Downloader
{
    /** Seconds without a byte before a download is given up. */
    public const TIMEOUT = 60.0;

    /** @var array<string, JsonClient> by host */
    private array $clients = [];

    private readonly RateLimiterInterface $limiter;

    /** @param array<string, string> $headers sent with every download (a token's Authorization, an Accept) */
    public function __construct(
        private readonly HttpClientInterface $http,
        ?RateLimiterInterface $limiter = null,
        private readonly array $headers = [],
        private readonly float $timeout = self::TIMEOUT,
    ) {
        $this->limiter = $limiter ?? new InMemoryRateLimiter();
    }

    /** The address' bytes. */
    public function __invoke(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host || !\in_array(strtolower((string) parse_url($url, \PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('A tarball is downloaded from an http:// or https:// address.');
        }
        $host = strtolower($host);
        $this->clients[$host] ??= new JsonClient($this->http, $host, $this->headers, new Backoff($this->limiter, $host, UsageLimitReachedException::class), $this->timeout);

        return $this->clients[$host]->raw('GET', $url, pass: [])->body;
    }
}
