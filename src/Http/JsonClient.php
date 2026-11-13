<?php

namespace Omnihub\Http;

use Omnihub\Exception\ProviderException;
use Omnihub\Exception\UnreachableException;
use Omnisense\Contracts\Http\Answer;
use Omnisense\Contracts\Http\Data;
use Omnisense\Contracts\Http\NoRetry;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Omnihub's calls over the application's HTTP client, on omnisense/contracts' JsonClient: the
 * client kept from replaying anything (Http\NoRetry: a 429 reaches the provider's Backoff, no
 * request goes out that the policy did not count), omnihub's User-Agent and a timeout on every
 * request, a network failure as an UnreachableException and an error status as a
 * ProviderException naming the path only (never the query, nor the token: Redaction). Given the
 * provider's Backoff, it waits while the provider asked to and reads every answer's Retry-After
 * and RateLimit fields: a 429 is then a UsageLimitReachedException with the wait asked.
 */
final class JsonClient extends \Omnisense\Contracts\Http\JsonClient
{
    /** What every request says it is (GitHub's API refuses a request without one). */
    public const USER_AGENT = 'omnihub (+https://github.com/glitchr-omnisense/omnihub)';

    /** Seconds without a byte before a request is given up (Symfony's "timeout": idle, not total). */
    public const TIMEOUT = 30.0;

    /** @param array<string, string|list<string>> $headers sent with every request, a User-Agent of the caller's among them */
    public function __construct(HttpClientInterface $http, string $provider, array $headers = [], ?Backoff $backoff = null, private readonly float $timeout = self::TIMEOUT)
    {
        parent::__construct(NoRetry::of($http), $provider, $headers + ['User-Agent' => self::USER_AGENT], $backoff);
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $options = [], array $headers = []): ?Data
    {
        return parent::request($method, $url, $options + ['timeout' => $this->timeout], $headers);
    }

    /**
     * @param array<string, mixed>               $options
     * @param array<string, string|list<string>> $headers
     * @param list<int>                          $pass
     */
    public function raw(string $method, string $url, array $options = [], array $headers = [], array $pass = [404]): Answer
    {
        return parent::raw($method, $url, $options + ['timeout' => $this->timeout], $headers, $pass);
    }

    protected function answered(string $what, int $status, string $detail): \Throwable
    {
        return ProviderException::answered($this->provider, $what, $status, $detail);
    }

    protected function unreachable(string $message, \Throwable $previous): \Throwable
    {
        return new UnreachableException($message, 0, $previous);
    }
}
