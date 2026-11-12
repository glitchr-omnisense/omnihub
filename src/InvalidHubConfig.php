<?php

namespace Omnihub;

/** A hub configuration that cannot be built: every problem found, each naming its key. */
final class InvalidHubConfig extends \InvalidArgumentException
{
    /** @param list<string> $errors */
    public function __construct(public readonly string $configuration, public readonly array $errors)
    {
        parent::__construct(\sprintf("%s:\n  - %s", $configuration, implode("\n  - ", $errors)));
    }
}
