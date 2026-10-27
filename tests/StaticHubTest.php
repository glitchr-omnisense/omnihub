<?php

namespace Omnihub\Tests;

use Omnihub\Package;
use Omnihub\Source;
use Omnihub\StaticHub;
use Omnihub\Version;
use PHPUnit\Framework\TestCase;

/** A hub without a server: its files, its manifest without checkout, written to a folder. */
class StaticHubTest extends TestCase
{
    public function testItsFilesAndItsManifest(): void
    {
        $hub = (new StaticHub('https://studio.github.io/hub/', 'Studio', [], [new Source(Source::GIT, 'https://github.com/x/y.git', 'A tool')], 'logo.png', '#336699'))
            ->add([new Package('com.studio.one', [Version::ofTarball('1.0.0', ['displayName' => 'One'], 'https://github.com/studio/one/releases/download/v1.0.0/com.studio.one-1.0.0.tgz', 'bytes')]), new Package('com.studio.empty')]);

        $files = $hub->files();
        self::assertSame(['/-/hub.json', '/-/v1/search', '/-/all', '/com.studio.one', '/'], array_keys($files), 'a package without version is left out');
        $manifest = json_decode($files['/-/hub.json'][1], true);
        self::assertSame('Studio', $manifest['name']);
        self::assertSame('https://studio.github.io/hub/logo.png', $manifest['logo']);
        self::assertSame(['name' => 'Studio', 'type' => 'upm', 'url' => 'https://studio.github.io/hub', 'scopes' => ['com.studio']], $manifest['sources'][0]);
        self::assertSame('git', $manifest['sources'][1]['type']);
        self::assertArrayNotHasKey('checkout', $manifest, 'a static hub sells nothing');
        self::assertSame('https://github.com/studio/one/releases/download/v1.0.0/com.studio.one-1.0.0.tgz', json_decode($files['/com.studio.one'][1], true)['versions']['1.0.0']['dist']['tarball']);
        self::assertStringContainsString('&quot;scopedRegistries&quot;', $files['/'][1]);

        $dir = sys_get_temp_dir().'/omnihub-static-'.bin2hex(random_bytes(4));
        $written = $hub->writeTo($dir);
        self::assertSame(['-/hub.json', '-/v1/search', '-/all', 'com.studio.one', 'index.html', '.nojekyll'], $written);
        self::assertJson((string) file_get_contents($dir.'/-/v1/search'));
        array_map('unlink', array_map(fn ($f) => $dir.'/'.$f, $written));
    }

    public function testAColourIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StaticHub('https://x.github.io/h', 'X', [], [], null, 'red'))->manifest();
    }
}
