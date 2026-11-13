<?php

namespace Omnihub\Tests;

use Omnihub\HubManifest;
use Omnihub\Source;
use Omnihub\StaticHub;
use Omnihub\Theme;
use PHPUnit\Framework\TestCase;

/** A hub's look in the manifest: "theme", as Forge Store reads it, and the older color and logo. */
class ThemeTest extends TestCase
{
    use Reads;

    public function testTheManifestCarriesItsThemeAndTheOlderKeys(): void
    {
        $manifest = (new HubManifest('Acme', [], theme: new Theme('#369', '#6699cc', 'https://acme.example/logo.png', 'https://acme.example/banner.png')))->toArray();
        self::assertSame(['accent' => '#369', 'accentDark' => '#6699cc', 'logo' => 'https://acme.example/logo.png', 'banner' => 'https://acme.example/banner.png'], $manifest['theme']);
        self::assertSame('#336699', $manifest['color'], 'the accent as #rrggbb, for the clients without themes');
        self::assertSame('https://acme.example/logo.png', $manifest['logo']);

        $own = (new HubManifest('Acme', [], 'https://acme.example/old.png', '#000000', theme: new Theme('#ffffff')))->toArray();
        self::assertSame(['https://acme.example/old.png', '#000000'], [$own['logo'], $own['color']], 'the hub\'s own keys kept');
        self::assertArrayNotHasKey('theme', (new HubManifest('Acme', [], theme: new Theme()))->toArray(), 'an empty theme is none');
    }

    public function testAStaticHubPublishesItsPictures(): void
    {
        $hub = new StaticHub('https://acme.github.io/hub/', 'Acme', ['com.acme'], [Source::github('acme', 'unity-*')], theme: new Theme('#336699', null, 'brand/logo.png', 'https://cdn.example/banner.png'));
        $manifest = json_decode($hub->files()['/-/hub.json'][1], true);
        self::assertSame(['accent' => '#336699', 'logo' => 'https://acme.github.io/hub/brand/logo.png', 'banner' => 'https://cdn.example/banner.png'], self::at($manifest, 'theme'));
        self::assertSame(['type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*'], self::at($manifest, 'sources', 1), 'passed through for the clients');
        self::assertStringContainsString('border-bottom:4px solid #336699', $hub->files()['/'][1]);
        self::assertStringContainsString('https://cdn.example/banner.png', $hub->files()['/'][1]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function wrong(): iterable
    {
        yield 'a named colour' => [['accent' => 'red'], 'theme.accent: a colour is #rgb or #rrggbb, not "red"'];
        yield 'four digits' => [['accentDark' => '#1234'], 'theme.accentDark: a colour'];
        yield 'a YAML number' => [['accent' => 336699], 'theme.accent: a text, not int'];
        yield 'an ftp logo' => [['logo' => 'ftp://acme.example/logo.png'], 'theme.logo: an https:// address or a file of the hub'];
        yield 'a typo' => [['acent' => '#fff'], 'theme.acent: not a key of a theme'];
    }

    /** @param array<string, mixed> $theme */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrong')]
    public function testAWrongThemeSaysWhy(array $theme, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Theme::fromArray($theme);
    }
}
