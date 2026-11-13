<?php

namespace Omnihub\Tests;

use Omnihub\HubConfig;
use Omnihub\InvalidHubConfig;
use Omnihub\Source;
use Omnihub\Tarball;
use PHPUnit\Framework\TestCase;

/** hub.yaml read and checked: the hub, its theme, its sources; every error at once, each naming its key. */
class HubConfigTest extends TestCase
{
    use Reads;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/omnihub-config-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/brand', 0777, true);
        file_put_contents($this->dir.'/brand/logo.png', 'png');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function testAConfigurationWithATheme(): void
    {
        $config = HubConfig::fromArray([
            'name' => 'Acme',
            'scopes' => ['com.acme'],
            'theme' => ['accent' => '#336699', 'accentDark' => '#6699cc', 'logo' => 'brand/logo.png'],
            'sources' => [
                ['name' => 'Acme on GitHub', 'type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*', 'install' => true],
                ['name' => 'OpenUPM', 'type' => 'upm', 'url' => 'https://package.openupm.com', 'scopes' => ['com.cysharp.unitask']],
            ],
            'packages' => [['github' => 'acme/unity-tool', 'asset' => '/\.tgz$/']],
        ], 'https://acme.github.io/hub', $this->dir);

        self::assertSame(['brand/logo.png'], $config->copy, 'the logo published with the hub');
        self::assertCount(1, $config->githubSources());
        $manifest = $config->hub()->manifest()->toArray();
        self::assertSame(['accent' => '#336699', 'accentDark' => '#6699cc', 'logo' => 'https://acme.github.io/hub/brand/logo.png'], $manifest['theme']);
        self::assertSame(['name' => 'Acme on GitHub', 'type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*', 'install' => true], self::at($manifest, 'sources', 1));
        self::assertSame('upm', self::at($manifest, 'sources', 2, 'type'));

        $resolved = $config->hub([new Source(Source::GIT, 'https://github.com/acme/unity-tool.git#v1.0.0', 'Tool', package: 'com.acme.tool')])->manifest()->toArray();
        self::assertSame(['upm', 'git'], array_column(self::arrayAt($resolved, 'sources'), 'type'), 'the sources as resolved replace the configured ones');
    }

    public function testEveryErrorAtOnceEachNamingItsKey(): void
    {
        try {
            HubConfig::fromArray([
                'name' => 'Acme',
                'colour' => '#336699',
                'color' => 336699,
                'scopes' => ['Com Acme'],
                'theme' => ['accent' => 'blue'],
                'sources' => [
                    ['type' => 'upm', 'url' => 'https://package.openupm.com'],
                    ['type' => 'github', 'owner' => 'acme'],
                    'acme/unity-*',
                ],
                'packages' => [['github' => 'acme'], ['file' => 'tarballs/missing.tgz'], ['github' => 'acme/a', 'tarball' => 'https://x.org/a.tgz']],
            ], null, $this->dir);
            self::fail('A wrong configuration is refused.');
        } catch (InvalidHubConfig $e) {
            self::assertSame([
                'colour: not a key of a hub (name, url, scopes, logo, color, theme, licensing, packages, sources, copy).',
                'url: the hub\'s address, https://<owner>.github.io/<repository> - in the configuration or --url.',
                'scopes[0]: a scope is reverse-domain, "com.mystudio", not "Com Acme".',
                'color: a text, not int.',
                'theme.accent: a colour is #rgb or #rrggbb, not "blue".',
                'sources[1].pattern: a "github" source names its repositories with globs ("unity-*"; "*" any run, "?" one character, several separated by commas), not "".',
                'sources[2]: a mapping (type, name, url or owner + pattern...).',
                'packages[0].github: owner/name, not "acme".',
                'packages[2]: one of github: owner/name, file: <path>, tarball: <address>.',
                'packages: "tarballs/missing.tgz" is not beside the configuration.',
            ], $e->errors);
            self::assertStringStartsWith("hub.yaml:\n  - colour: not a key", $e->getMessage());
        }
    }

    public function testAThemePictureOfMoreThanAMegabyteIsRefused(): void
    {
        file_put_contents($this->dir.'/banner.png', str_repeat('x', HubConfig::PICTURE_BYTES + 1));
        $this->expectExceptionMessage('theme.banner: "banner.png" is 1048577 bytes; Forge Store draws a picture of 1 MB at most.');
        HubConfig::fromArray(['url' => 'https://acme.github.io/hub', 'theme' => ['banner' => 'banner.png']], null, $this->dir);
    }

    public function testBinBuildsAndChecks(): void
    {
        file_put_contents($this->dir.'/com.acme.tool-1.0.0.tgz', Tarball::create(['package.json' => (string) json_encode(['name' => 'com.acme.tool', 'version' => '1.0.0', 'displayName' => 'Tool'])]));
        file_put_contents($this->dir.'/hub.json', json_encode([
            'name' => 'Acme',
            'theme' => ['accent' => '#336699', 'logo' => 'brand/logo.png'],
            'packages' => [['file' => 'com.acme.tool-1.0.0.tgz']],
            'sources' => [['type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*']],
        ]));
        $bin = \dirname(__DIR__).'/bin/omnihub';

        exec(\sprintf('php %s check %s --url=https://acme.github.io/hub 2>&1', escapeshellarg($bin), escapeshellarg($this->dir.'/hub.json')), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringContainsString('1 packages, 1 sources, a theme, no licensing key.', implode("\n", $output));

        exec(\sprintf('php %s build %s %s --url=https://acme.github.io/hub 2>&1', escapeshellarg($bin), escapeshellarg($this->dir.'/hub.json'), escapeshellarg($this->dir.'/_site')), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        $manifest = json_decode((string) file_get_contents($this->dir.'/_site/-/hub.json'), true);
        self::assertSame(['type' => 'github', 'owner' => 'acme', 'pattern' => 'unity-*'], self::at($manifest, 'sources', 1), 'without --resolve-github, left for the clients');
        self::assertSame('https://acme.github.io/hub/brand/logo.png', self::at($manifest, 'theme', 'logo'));
        self::assertFileExists($this->dir.'/_site/brand/logo.png');
        self::assertFileExists($this->dir.'/_site/com.acme.tool-1.0.0.tgz');
        self::assertSame('https://acme.github.io/hub/com.acme.tool-1.0.0.tgz', self::at(json_decode((string) file_get_contents($this->dir.'/_site/com.acme.tool'), true), 'versions', '1.0.0', 'dist', 'tarball'));

        file_put_contents($this->dir.'/bad.json', json_encode(['url' => 'https://acme.github.io/hub', 'sources' => [['type' => 'github', 'owner' => 'acme']]]));
        $output = [];
        exec(\sprintf('php %s build %s %s 2>&1', escapeshellarg($bin), escapeshellarg($this->dir.'/bad.json'), escapeshellarg($this->dir.'/_bad')), $output, $code);
        self::assertSame(1, $code);
        self::assertStringContainsString('sources[0].pattern: a "github" source names its repositories', implode("\n", $output));
        self::assertDirectoryDoesNotExist($this->dir.'/_bad', 'nothing written');
    }
}
