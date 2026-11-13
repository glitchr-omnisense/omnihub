<?php

namespace Omnihub\Tests;

use PHPUnit\Framework\Assert;

/** A decoded document read along a path of keys, each step checked: what a test asserts on. */
trait Reads
{
    /** The value at $path in $document ("versions", "1.0.0", "dist"); the test fails where a key is missing. */
    private static function at(mixed $document, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            Assert::assertIsArray($document, \sprintf('an array where "%s" is read', $key));
            Assert::assertArrayHasKey($key, $document);
            $document = $document[$key];
        }

        return $document;
    }

    /**
     * The array at $path.
     *
     * @return array<mixed>
     */
    private static function arrayAt(mixed $document, string|int ...$path): array
    {
        $value = self::at($document, ...$path);
        Assert::assertIsArray($value);

        return $value;
    }
}
