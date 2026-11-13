<?php

namespace Omnihub\Tests;

use Omnihub\StaticHub;
use Omnisense\Contracts\Testing\BareTestCase;

/**
 * omnisense/omnihub with no framework: every class of the core loads with nothing from outside
 * but omnisense/contracts and Symfony's HTTP client contracts (interfaces only) - the bridge to
 * omnibridge/static aside, which is suggested -; and the harness's bare script builds a hub
 * from recorded answers through omnihub/github, in a PHP process of its own.
 */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return StaticHub::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }

    /** The classes sit under src/, the package's PSR-4 root. */
    protected static function packageDir(): string
    {
        return self::dirOf(StaticHub::class).'/src';
    }

    public function testAHubBuiltByHandFromRecordedAnswersLoadsNoFramework(): void
    {
        $script = __DIR__.'/../docker/harness/bin/bare';
        if (!class_exists('Omnihub\GitHub\Repository') || !is_file($script)) {
            self::markTestSkipped('The bare script needs omnihub/github and the harness.');
        }
        $report = self::php([$script, '--json']);

        self::assertSame([['com.acme.extra', ['2.0.0']], ['com.acme.tool', ['1.0.0']]], $report['packages'] ?? null);
        self::assertSame([['name' => 'Acme Paint', 'type' => 'git', 'url' => 'https://github.com/acme/unity-paint.git#v1.1.0', 'package' => 'com.acme.paint']], $report['sources'] ?? null, 'the public repository that matches, at its newest stable tag');
        self::assertSame('https://github.com/acme/unity-tool/releases/download/v1.0.0/com.acme.tool-1.0.0.tgz', $report['tarball'] ?? null);
        self::assertSame('Acme', $report['manifest'] ?? null);
        self::assertSame('omnihub (+https://github.com/glitchr-omnisense/omnihub)', $report['userAgent'] ?? null);
        self::assertCount(7, \is_array($report['requests'] ?? null) ? $report['requests'] : [], 'the busy host asked once');
        self::assertSame([120, 120], $report['busy'] ?? null);

        $loaded = \is_array($report['loaded'] ?? null) ? $report['loaded'] : [];
        $strings = static fn (string $key): array => array_values(array_filter(\is_array($loaded[$key] ?? null) ? $loaded[$key] : [], 'is_string'));
        self::assertContains('Omnihub\GitHub\Api', $strings('symbols'));
        self::assertSame([], self::foreign($strings('symbols')), 'no class of a framework, an HTTP client or an application');
    }
}
