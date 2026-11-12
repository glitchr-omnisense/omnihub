<?php

namespace Omnihub\Tests;

use Omnihub\Source;
use PHPUnit\Framework\TestCase;

/** The sources a manifest lists, as the hub clients read them: upm, git, github. */
class SourceTest extends TestCase
{
    public function testAGitHubSourceIsAnOwnerAndAPattern(): void
    {
        $source = Source::fromArray(['name' => 'Our packages', 'type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*', 'install' => true]);
        self::assertSame(['name' => 'Our packages', 'type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*', 'install' => true], $source->toArray());
        self::assertSame(['type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*, tool-?'], Source::github('acme', ' unity-*, tool-? ')->toArray(), 'no name, no install: left out');
    }

    public function testItsPatternMatchesAsForgeStoreMatchesIt(): void
    {
        $source = Source::github('acme', 'unity-*, tool-?');
        self::assertTrue($source->matches('unity-tool'));
        self::assertTrue($source->matches('Unity-Store'), 'case ignored');
        self::assertTrue($source->matches('tool-a'));
        self::assertFalse($source->matches('tool-ab'));
        self::assertFalse($source->matches('my-unity-tool'), 'the whole name');
        self::assertFalse($source->matches(''));
        self::assertTrue(Source::github('acme', 'a.b')->matches('a.b'));
        self::assertFalse(Source::github('acme', 'a.b')->matches('axb'), 'a dot is a dot');
    }

    public function testAGitSourceCarriesItsPackageAndInstall(): void
    {
        $source = Source::fromArray(['name' => 'Tool', 'type' => 'git', 'url' => 'https://github.com/acme/unity-tool.git#v1.0.0', 'package' => 'com.acme.tool', 'install' => true]);
        self::assertSame(['name' => 'Tool', 'type' => 'git', 'url' => 'https://github.com/acme/unity-tool.git#v1.0.0', 'package' => 'com.acme.tool', 'install' => true], $source->toArray());
        self::assertSame(['name' => 'OpenUPM', 'type' => 'upm', 'url' => 'https://package.openupm.com', 'scopes' => ['com.cysharp']], Source::fromArray(['name' => 'OpenUPM', 'url' => 'https://package.openupm.com/', 'scopes' => ['com.cysharp']])->toArray(), 'upm by default');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function wrong(): iterable
    {
        yield 'an unknown type' => [['type' => 'svn', 'url' => 'https://x.org'], 'type: a source is "upm", "git" or "github", not "svn"'];
        yield 'no owner' => [['type' => 'github', 'pattern' => 'unity-*'], 'owner: a "github" source names an organisation or a user'];
        yield 'an owner with a slash' => [['type' => 'github', 'owner' => 'acme/unity', 'pattern' => '*'], 'not "acme/unity"'];
        yield 'no pattern' => [['type' => 'github', 'owner' => 'acme'], 'pattern: a "github" source names its repositories with globs'];
        yield 'a regular expression' => [['type' => 'github', 'owner' => 'acme', 'pattern' => '^unity-.*$'], 'not "^unity-.*$"'];
        yield 'install not a boolean' => [['type' => 'github', 'owner' => 'acme', 'pattern' => '*', 'install' => 'yes'], 'install: true or false'];
        yield 'a typo' => [['type' => 'github', 'owner' => 'acme', 'patern' => '*'], 'patern: not a key of a source'];
        yield 'no url' => [['type' => 'git'], 'url: a "git" source has an https:// address'];
        yield 'a registry installing' => [['type' => 'upm', 'url' => 'https://x.org', 'install' => true], 'install: only a "git" or a "github" source'];
        yield 'scopes not a list of texts' => [['url' => 'https://x.org', 'scopes' => [1]], 'scopes: a list of scopes'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrong')]
    public function testAWrongSourceSaysWhy(array $source, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Source::fromArray($source);
    }
}
