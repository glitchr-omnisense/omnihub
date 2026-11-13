<?php

namespace Omnihub\Tests;

use Omnihub\HubManifest;
use Omnihub\Licensing;
use Omnihub\Source;
use PHPUnit\Framework\TestCase;

/** The manifest a forge serves, written by omnihub: its keys, in the forge's order. */
class HubManifestTest extends TestCase
{
    private const KEY = 'GZ3vAm9eHLlh2aASYB4jjXo3VbK0QCrmqY0Y4m8w6xk=';

    public function testAForgesManifest(): void
    {
        $checkout = [
            'device' => 'https://forge.example/hub/device',
            'token' => 'https://forge.example/hub/token',
            'quote' => 'https://forge.example/hub/quote',
            'purchases' => 'https://forge.example/hub/purchases',
            'methods' => 'https://forge.example/hub/payment-methods',
        ];
        $licenses = ['list' => 'https://forge.example/hub/licenses', 'activate' => 'https://forge.example/api/license/activate', 'release' => 'https://forge.example/api/license/release'];
        $manifest = (new HubManifest(
            'Forge',
            [new Source(Source::UPM, 'https://forge.example/upm', 'Forge', ['com.studio'], 'https://forge.example/upm/-/hub/catalog.json')],
            'https://forge.example/logo.png',
            '#112233',
            'https://forge.example/upm/-/hub/catalog.json',
            $checkout,
            licensing: new Licensing(self::KEY, registry: 'https://forge.example/upm'),
            licenses: $licenses,
        ))->toArray();

        self::assertSame(['version', 'name', 'logo', 'color', 'sources', 'games', 'checkout', 'licenses', 'licensing'], array_keys($manifest), 'the order omnibase/forge serves');
        self::assertSame($checkout, $manifest['checkout'], 'the one-click purchase\'s addresses beside the device flow, in their order');
        self::assertSame($licenses, $manifest['licenses']);
        self::assertSame(['algorithm' => 'Ed25519', 'publicKey' => self::KEY, 'registry' => 'https://forge.example/upm'], $manifest['licensing'], 'the forge\'s licensing fields, algorithm first');
        self::assertSame(['algorithm' => 'Ed25519', 'publicKey' => self::KEY, 'registry' => 'https://forge.example/upm'], Licensing::fromArray($manifest['licensing'])->toArray(), 'a manifest\'s licensing reads back');

        $device = (new HubManifest('Forge', [], checkout: ['device' => 'https://f.example/d', 'token' => 'https://f.example/t']))->toArray();
        self::assertSame(['device' => 'https://f.example/d', 'token' => 'https://f.example/t'], $device['checkout'], 'the device flow alone, as before');
        self::assertArrayNotHasKey('licenses', $device);
    }

    public function testACheckoutNeedsItsDeviceFlowAndAddresses(): void
    {
        foreach ([
            [['device' => 'https://f.example/d'], 'checkout.token: missing'],
            [['device' => 'https://f.example/d', 'token' => 'https://f.example/t', 'quote' => 'quote'], 'checkout.quote: an address'],
            [['device' => 'https://f.example/d', 'token' => 'https://f.example/t', 'methods' => 3], 'checkout.methods: an address (https://), not int'],
        ] as [$checkout, $message]) {
            try {
                new HubManifest('Forge', [], checkout: $checkout); // @phpstan-ignore argument.type (a wrong value on purpose)
                self::fail($message);
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith($message, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('licenses.release: missing');
        new HubManifest('Forge', [], licenses: ['list' => 'https://f.example/l', 'activate' => 'https://f.example/a']);
    }

    public function testTheDefaultScopes(): void
    {
        self::assertSame(['com.studio', 'io.acme'], HubManifest::scopesOf(['com.studio.one', 'io.acme.tool', 'com.studio.two']));
        self::assertSame([], HubManifest::scopesOf([]));
    }

    public function testOnlyEd25519(): void
    {
        self::assertSame([], Licensing::problems(['algorithm' => 'Ed25519', 'publicKey' => self::KEY]));
        self::assertSame(['licensing.algorithm: Ed25519, the only one the hub clients check (or leave it out).'], Licensing::problems(['algorithm' => 'RSA', 'publicKey' => self::KEY]));
    }
}
