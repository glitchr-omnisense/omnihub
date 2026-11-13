<?php

namespace Omnihub\Tests;

use Omnihub\Exception\OmnihubException;
use Omnihub\Exception\ProviderException;
use Omnihub\Exception\UnreachableException;
use Omnihub\Exception\UsageLimitReachedException;
use Omnihub\Http\Downloader;
use Omnihub\Http\JsonClient;
use Omnihub\Tarball;
use Omnihub\TarballSource;
use Omnisense\Contracts\Policy\UsageLimitReached;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

/** Tarballs downloaded through omnisense/contracts' kit: sent once, a timeout, omnihub's User-Agent, a Retry-After honoured. */
class DownloaderTest extends TestCase
{
    public function testATarballIsDownloadedWithOmnihubsUserAgentAndATimeout(): void
    {
        $sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent[] = [$method, $url, \is_array($options['headers'] ?? null) ? $options['headers'] : [], $options['timeout'] ?? null];

            return new MockResponse(Tarball::create(['package.json' => '{"name":"com.acme.tool","version":"1.0.0"}']));
        });

        $packages = iterator_to_array((new TarballSource(['https://example.org/com.acme.tool-1.0.0.tgz'], $client))->packages(), false);

        self::assertSame('com.acme.tool', $packages[0]->name);
        self::assertSame('GET', $sent[0][0]);
        self::assertSame('https://example.org/com.acme.tool-1.0.0.tgz', $sent[0][1]);
        self::assertContains('User-Agent: '.JsonClient::USER_AGENT, $sent[0][2]);
        self::assertSame(Downloader::TIMEOUT, $sent[0][3]);
    }

    public function testAnErrorStatusNamesThePathNeverTheQuery(): void
    {
        $download = new Downloader(new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404])));
        try {
            $download('https://example.org/files/tool.tgz?token=not-a-real-token');
            self::fail('A 404 is an error.');
        } catch (ProviderException $e) {
            self::assertInstanceOf(OmnihubException::class, $e);
            self::assertSame(404, $e->status());
            self::assertStringContainsString('example.org answered 404 for GET /files/tool.tgz', $e->getMessage());
            self::assertStringNotContainsString('not-a-real-token', $e->getMessage());
        }
    }

    public function testANetworkFailureIsUnreachableWithoutTheToken(): void
    {
        $download = new Downloader(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host: example.org'])), headers: ['Authorization' => 'Bearer not-a-real-token']);
        try {
            $download('https://example.org/tool.tgz');
            self::fail('No answer, no bytes.');
        } catch (UnreachableException $e) {
            self::assertInstanceOf(OmnihubException::class, $e);
            self::assertStringNotContainsString('not-a-real-token', $e->getMessage());
        }
    }

    public function testA429HoldsThatHostAndNoOther(): void
    {
        $calls = [];
        $download = new Downloader(new MockHttpClient(function (string $method, string $url) use (&$calls) {
            $calls[] = (string) parse_url($url, \PHP_URL_HOST);

            return str_contains($url, 'busy.example.org') ? new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '120']]) : new MockResponse('bytes');
        }));
        foreach ([1, 2] as $attempt) {
            try {
                $download('https://busy.example.org/tool.tgz');
                self::fail('A 429 is a refusal.');
            } catch (UsageLimitReachedException $e) {
                self::assertInstanceOf(UsageLimitReached::class, $e, 'omnisense/contracts\' refusal');
                self::assertGreaterThanOrEqual(119, $e->retryAfter);
            }
        }
        self::assertSame('bytes', $download('https://other.example.org/tool.tgz'));
        self::assertSame(['busy.example.org', 'other.example.org'], $calls, 'the busy host asked once, then held; another host still asked');
    }

    public function testARefusalIsNotReplayedByARetryingClient(): void
    {
        $mock = new MockHttpClient([new MockResponse('', ['http_code' => 502]), new MockResponse('bytes')]);
        $retrying = new RetryableHttpClient($mock, new GenericRetryStrategy(GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 0), 3);
        try {
            (new Downloader($retrying))('https://example.org/tool.tgz');
            self::fail('The 502 reaches omnihub.');
        } catch (ProviderException $e) {
            self::assertSame(502, $e->status());
        }
        self::assertSame(1, $mock->getRequestsCount(), 'sent once: omnihub decides what to try again');
    }

    public function testOnlyAnHttpAddressIsDownloaded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Downloader(new MockHttpClient()))('file:///etc/hosts');
    }

    public function testACallableStillFetchesTheBytes(): void
    {
        $tgz = Tarball::create(['package.json' => '{"name":"com.acme.kept","version":"0.1.0"}']);
        $packages = iterator_to_array((new TarballSource(['https://example.org/kept.tgz'], fn (string $url): string => $tgz))->packages(), false);
        self::assertSame(['0.1.0'], array_map(fn ($v) => $v->version, $packages[0]->versions));
    }
}
