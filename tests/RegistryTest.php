<?php

namespace Omnihub\Tests;

use Omnihub\Package;
use Omnihub\Upm\Registry;
use Omnihub\Version;
use PHPUnit\Framework\TestCase;

/** The documents Unity's Package Manager reads: a package's, the search, the listing. */
class RegistryTest extends TestCase
{
    use Reads;

    public function testAPackagesDocument(): void
    {
        $document = Registry::document($this->package());
        self::assertSame('com.example.tool', $document['name']);
        self::assertSame(['latest' => '1.1.0'], $document['dist-tags'], 'the newest stable, not the pre-release');
        self::assertSame(['1.0.0', '1.1.0', '1.2.0-beta1'], array_keys(self::arrayAt($document, 'versions')), 'oldest first');
        $v = self::at($document, 'versions', '1.1.0');
        self::assertSame('com.example.tool', self::at($v, 'name'));
        self::assertSame('2021.3', self::at($v, 'unity'), 'its own package.json');
        self::assertSame('https://example.org/com.example.tool-1.1.0.tgz', self::at($v, 'dist', 'tarball'));
        self::assertSame(sha1('one-one'), self::at($v, 'dist', 'shasum'));
        self::assertSame('sha512-'.base64_encode(hash('sha512', 'one-one', true)), self::at($v, 'dist', 'integrity'));
        self::assertSame('2026-10-02T00:00:00.000Z', self::at($document, 'time', '1.1.0'));
        self::assertSame('{"_id":"x","name":"x","dist-tags":{},"versions":{}}', Registry::json(Registry::document(new Package('x'))), 'empty objects stay objects');
    }

    public function testTheSearchAndTheListing(): void
    {
        $packages = [$this->package(), new Package('com.other.thing', [Version::ofTarball('2.0.0', ['description' => 'Something else'], 'https://x/t.tgz', 't')])];
        $names = fn (array $search) => array_map(fn ($o) => self::at($o, 'package', 'name'), self::arrayAt($search, 'objects'));
        self::assertSame(['com.example.tool', 'com.other.thing'], $names(Registry::search($packages)));
        self::assertSame(['com.example.tool'], $names(Registry::search($packages, 'com.example')), 'Unity sends the scope');
        self::assertSame(['com.example.tool'], $names(Registry::search($packages, 'TOOL handy')), 'every word, any case');
        self::assertSame([], Registry::search($packages, 'com.example', 1)['objects'], 'the page after the last is empty');
        self::assertSame('1.1.0', self::at(Registry::search($packages)['objects'], 0, 'package', 'version'));
        $all = Registry::all($packages);
        self::assertSame(['latest' => '1.1.0'], self::at($all, 'com.example.tool', 'dist-tags'));
        self::assertArrayHasKey('_updated', $all);
    }

    public function testAHubNamesItselfAndGivesItsDefaults(): void
    {
        $forge = new Package('com.studio.bare', [Version::ofTarball('1.0.0', ['keywords' => []], 'https://x/b.tgz', 'b')], 'https://studio.example', 'https://git.example/bare', 'Bare', 'A bare tool.');
        $own = new Package('com.studio.own', [Version::ofTarball('1.0.0', ['displayName' => 'Own', 'description' => '', 'keywords' => 'not a list'], 'https://x/o.tgz', 'o')], displayName: 'Default', description: 'Default text.');

        $found = self::arrayAt(Registry::search([$forge, $own], publisher: 'studio'), 'objects');
        self::assertSame(['username' => 'studio'], self::at($found, 0, 'package', 'publisher'), 'the publisher a hub passes');
        self::assertSame(['username' => 'omnihub'], self::at(Registry::search([$own])['objects'], 0, 'package', 'publisher'), 'omnihub when none');
        self::assertSame([], self::at($found, 0, 'package', 'keywords'), 'an empty list of keywords stays, as a forge writes it');
        self::assertSame('Bare', self::at($found, 0, 'package', 'displayName'), 'the default when the package.json has none');
        self::assertSame('A bare tool.', self::at($found, 0, 'package', 'description'));
        self::assertSame(['com.studio.bare'], array_map(fn ($o) => self::at($o, 'package', 'name'), self::arrayAt(Registry::search([$forge, $own], 'bare tool'), 'objects')), 'the defaults are searched too');

        $package = self::arrayAt($found, 1, 'package');
        self::assertSame('Own', $package['displayName'], 'the package.json\'s own first');
        self::assertArrayNotHasKey('description', $package, 'an empty text is left out, not replaced');
        self::assertArrayNotHasKey('keywords', $package, 'keywords that are not a list are left out');

        $all = Registry::all([$forge, $own]);
        self::assertSame('A bare tool.', self::at($all, 'com.studio.bare', 'description'));
        self::assertSame([], self::at($all, 'com.studio.bare', 'keywords'));
        self::assertArrayNotHasKey('description', self::arrayAt($all, 'com.studio.own'));
        self::assertSame('A bare tool.', Registry::document($forge)['description'] ?? null, 'the package document reads the default as well');
    }

    private function package(): Package
    {
        $manifest = fn (string $v) => ['name' => 'com.example.tool', 'version' => $v, 'displayName' => 'Tool', 'description' => 'A handy tool.', 'unity' => '2021.3', 'keywords' => ['tool']];

        return new Package('com.example.tool', [
            Version::ofTarball('1.1.0', $manifest('1.1.0'), 'https://example.org/com.example.tool-1.1.0.tgz', 'one-one', new \DateTimeImmutable('2026-10-02')),
            Version::ofTarball('1.2.0-beta1', $manifest('1.2.0-beta1'), 'https://example.org/b.tgz', 'beta', new \DateTimeImmutable('2026-10-03')),
            Version::ofTarball('1.0.0', $manifest('1.0.0'), 'https://example.org/a.tgz', 'one', new \DateTimeImmutable('2026-10-01')),
        ]);
    }
}
