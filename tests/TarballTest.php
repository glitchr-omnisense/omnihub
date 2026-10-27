<?php

namespace Omnihub\Tests;

use Omnihub\Tarball;
use Omnihub\TarballSource;
use PHPUnit\Framework\TestCase;

/** The tarball npm installs: written under package/, the same tree the same bytes, read back. */
class TarballTest extends TestCase
{
    public function testWrittenAndReadBack(): void
    {
        $long = 'Runtime/'.str_repeat('Deep/', 22).'Thing.cs';
        $files = ['package.json' => '{"name":"com.example.tool","version":"1.0.0"}', 'Runtime/A.cs' => 'class A {}', $long => [str_repeat('x', 700), 0755]];
        $tgz = Tarball::create($files, 1792972800);
        self::assertSame($tgz, Tarball::create($files, 1792972800), 'the same tree, the same bytes: the same shasum');
        $read = Tarball::read($tgz);
        self::assertSame(['package/Runtime/A.cs', 'package/'.$long, 'package/package.json'], array_keys($read));
        self::assertSame(str_repeat('x', 700), $read['package/'.$long]);
        self::assertSame(['name' => 'com.example.tool', 'version' => '1.0.0'], Tarball::manifest($tgz));

        // PHP's own tar reader agrees.
        $path = sys_get_temp_dir().'/omnihub-'.bin2hex(random_bytes(4)).'.tgz';
        file_put_contents($path, $tgz);
        $phar = new \PharData($path);
        self::assertTrue(isset($phar['package/package.json']));
        unlink($path);
    }

    public function testATarballSourceReadsItsPackages(): void
    {
        $tarballs = [
            'https://example.org/a-1.0.0.tgz' => Tarball::create(['package.json' => '{"name":"com.example.a","version":"1.0.0","unity":"2021.3"}']),
            'https://example.org/a-1.1.0.tgz' => Tarball::create(['package.json' => '{"name":"com.example.a","version":"1.1.0"}']),
            'https://example.org/b-2.0.0.tgz' => Tarball::create(['package.json' => '{"name":"com.example.b","version":"2.0.0"}']),
        ];
        $source = new TarballSource([['url' => 'https://example.org/a-1.0.0.tgz', 'changelog' => 'First'], 'https://example.org/a-1.1.0.tgz', 'https://example.org/b-2.0.0.tgz'], fn (string $url) => $tarballs[$url]);
        $packages = iterator_to_array($source->packages(), false);
        self::assertSame(['com.example.a', 'com.example.b'], array_map(fn ($p) => $p->name, $packages));
        self::assertSame(['1.0.0', '1.1.0'], array_map(fn ($v) => $v->version, $packages[0]->versions));
        self::assertSame(sha1($tarballs['https://example.org/a-1.0.0.tgz']), $packages[0]->versions[0]->sha1);
        self::assertSame('First', $packages[0]->versions[0]->changelog);
    }
}
