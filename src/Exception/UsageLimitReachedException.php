<?php

namespace Omnihub\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A request refused before it was sent: a rate limit of the provider's usage policy is reached,
 * or the provider asked to wait (a 429 and its Retry-After, a rate limit with nothing left).
 * $retryAfter says in how many seconds to try again, when it is known. A provider may name it
 * its own (omnihub/github's RateLimited).
 */
class UsageLimitReachedException extends UsageLimitReached implements OmnihubException
{
}
