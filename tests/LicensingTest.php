<?php

namespace Omnihub\Tests;

use Omnihub\HubConfig;
use Omnihub\InvalidHubConfig;
use Omnihub\Licensing;
use Omnihub\SigningKey;
use PHPUnit\Framework\TestCase;

/**
 * A hub's licensing key: read from hub.yaml, checked (a rotation verified with sodium), written into
 * hub.json as Forge Store reads and pins it - and the author's private key, kept out of the hub.
 */
class LicensingTest extends TestCase
{
    // Forge Store's own test vectors (io.glitchr.store, Tests/Editor/TrustTests.cs): throwaway keys,
    // their private halves never kept; ROTATION is OLD's signature of "forge-key-rotation:v1:" + NEW.
    private const OLD = 'Y/T6mi9iPOI6Zz76FZg2g9W9fQWvQvFKE55o6s75pY4=';
    private const NEW = '5Wr6MvD/0ktioPo2QsSyiHKTCPeraqZf94+MwEYZ6cc=';
    private const ROTATION = 'Tb6sYPiqPaiKVkvqPJWI4L6D1/ZqD0OqtbHuRnUaoUULuJ2BAoOZYsxfJZIf7CSlthewgR6svrxxdbVXMEtxBA==';
    private const OTHER = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=';

    // Forge Seal's (io.glitchr.licensing, Tests/Editor/LicenseTokenTests.cs): a token omnibase/forge signed with a fixed test pair.
    private const SEAL_PUBLIC_KEY = '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=';
    private const SEAL_TOKEN = 'eyJ2IjoxLCJsaWNlbnNlIjo0Miwic29mdHdhcmUiOiJwcm9iZS1nYW1lIiwiZW1haWwiOiJhZGFAZXhhbXBsZS5vcmciLCJtYWNoaW5lIjoiMzE3ZjA2Zjc3YmY3MmUwMmNiNjgyYzM2NWZkNWViMDQwMGM2NDc5NTA2NTI4ZDc0NWY1ZWMzZjIzYjA0Y2FhMSIsImlzc3VlZF9hdCI6MTc5Mjk3MjgwMCwidmFsaWRfdW50aWwiOjE3OTU1NjQ4MDAsImxpY2Vuc2VfZXhwaXJlcyI6bnVsbCwidXBkYXRlc191bnRpbCI6MTgyNDUwODgwMH0.lQZSS34z_XRwGVE22WJux9fWE4SrIKtQ6tJa0rnhdZTOHlNsWkPPL37Tj8ML_xd6FuW2FHBVWS7DZUm54rA3AA';

    private const URL = 'https://acme.github.io/hub';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/omnihub-licensing-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<array-key, mixed>
     */
    private static function manifest(array $settings): array
    {
        $manifest = json_decode(HubConfig::fromArray($settings + ['name' => 'Acme'], self::URL)->hub()->files()['/-/hub.json'][1], true);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @return non-empty-string the bytes of a base64 text */
    private static function raw(string $base64): string
    {
        $bytes = (string) base64_decode($base64, true);
        if ('' === $bytes) {
            self::fail('not base64: '.$base64);
        }

        return $bytes;
    }

    /** @return list<string> */
    private static function errors(mixed $licensing): array
    {
        try {
            HubConfig::fromArray(['name' => 'Acme', 'licensing' => $licensing], self::URL);
        } catch (InvalidHubConfig $e) {
            return $e->errors;
        }

        return [];
    }

    public function testWithoutLicensingTheManifestHasNoKey(): void
    {
        self::assertArrayNotHasKey('licensing', self::manifest([]));
        self::assertArrayNotHasKey('licensing', self::manifest(['licensing' => null]), 'an empty "licensing:" in YAML');
    }

    public function testTheKeyIsExportedAsForgeStoreReadsIt(): void
    {
        $manifest = self::manifest(['licensing' => ['publicKey' => ' '.self::OLD.' ']]);
        self::assertSame(['publicKey' => self::OLD, 'registry' => self::URL], $manifest['licensing'], 'HubLicensing.Of reads licensing.publicKey, Forge Store 1.5.0 licensing.registry');
        self::assertArrayNotHasKey('checkout', $manifest, 'a static hub still sells through no server');

        $page = HubConfig::fromArray(['licensing' => ['publicKey' => self::OLD]], self::URL)->hub()->files()['/'][1];
        self::assertStringContainsString((new Licensing(self::OLD))->fingerprint(), $page, 'the page shows the fingerprint Forge Store shows');
        self::assertMatchesRegularExpression('/^([0-9a-f]{2}:){7}[0-9a-f]{2}$/', (new Licensing(self::OLD))->fingerprint());
    }

    public function testForgeStoresRotationVectorVerifies(): void
    {
        $manifest = self::manifest(['licensing' => ['publicKey' => self::NEW, 'previousKey' => self::OLD, 'rotation' => self::ROTATION]]);
        self::assertSame(['publicKey' => self::NEW, 'registry' => self::URL, 'previousKey' => self::OLD, 'rotation' => self::ROTATION], $manifest['licensing']);
        self::assertTrue(sodium_crypto_sign_verify_detached(self::raw(self::ROTATION), 'forge-key-rotation:v1:'.self::NEW, self::raw(self::OLD)), 'the signed text is KeyPins\' RotationMessage');
    }

    public function testARotationThatDoesNotVerifyIsRefused(): void
    {
        $refused = 'licensing.rotation: does not verify with previousKey';
        self::assertStringStartsWith($refused, self::errors(['publicKey' => self::OTHER, 'previousKey' => self::OLD, 'rotation' => self::ROTATION])[0], 'signed for another key');
        self::assertStringStartsWith($refused, self::errors(['publicKey' => self::NEW, 'previousKey' => self::OTHER, 'rotation' => self::ROTATION])[0], 'signed by another key');
        self::assertStringStartsWith($refused, self::errors(['publicKey' => self::NEW, 'previousKey' => self::OLD, 'rotation' => base64_encode(str_repeat("\0", 64))])[0]);
        $tampered = base64_decode(self::ROTATION);
        $tampered[10] = \chr(\ord($tampered[10]) ^ 1);
        self::assertStringStartsWith($refused, self::errors(['publicKey' => self::NEW, 'previousKey' => self::OLD, 'rotation' => base64_encode($tampered)])[0], 'one bit changed');

        self::assertSame(['licensing.rotation: a previousKey goes with its signature of the new key (omnihub key rotate).'], self::errors(['publicKey' => self::NEW, 'previousKey' => self::OLD]));
        self::assertSame(['licensing.previousKey: a rotation goes with the key that signed it (omnihub key rotate).'], self::errors(['publicKey' => self::NEW, 'rotation' => self::ROTATION]));
        self::assertStringStartsWith('licensing.previousKey: the same key as publicKey', self::errors(['publicKey' => self::OLD, 'previousKey' => self::OLD, 'rotation' => self::ROTATION])[0]);
    }

    public function testAWrongKeySaysWhyAndNeverRepeatsASecret(): void
    {
        $secret = SigningKey::generate();
        $path = $this->dir.'/key';
        $secret->writeTo($path);
        $private = trim((string) file_get_contents($path));

        $errors = self::errors(['publicKey' => $private, 'privateKey' => $private, 'secret' => 'x', 'colour' => 'red']);
        self::assertSame([
            'licensing.privateKey: a private key never belongs in the hub\'s configuration - remove it, keep it out of the repository (and make a new one if it was ever committed: omnihub key generate).',
            'licensing.secret: a private key never belongs in the hub\'s configuration - remove it, keep it out of the repository (and make a new one if it was ever committed: omnihub key generate).',
            'licensing.colour: not a key of licensing (publicKey, registry, previousKey, rotation, previousKeyValidUntil).',
            'licensing.publicKey: 64 bytes is a private key (sodium\'s secret key), not a public one - remove it from the configuration and from the repository\'s history, and make a new pair (omnihub key generate).',
        ], $errors);
        self::assertStringNotContainsString($private, implode("\n", $errors));

        self::assertSame(['licensing.publicKey: an Ed25519 public key is 16 bytes, not 32.'], self::errors(['publicKey' => base64_encode(str_repeat('k', 16))]));
        self::assertSame(['licensing.publicKey: not base64 (standard alphabet, with its "=" padding).'], self::errors(['publicKey' => 'not a key!']));
        self::assertSame(['licensing.publicKey: not standard base64 (its "=" padding, "+" and "/", no line break): Forge Store would not read it.'], self::errors(['publicKey' => rtrim(self::OLD, '=')]), 'Convert.FromBase64String needs the padding');
        self::assertSame(['licensing.publicKey: a text in base64, not int.'], self::errors(['publicKey' => 42]));
        self::assertSame(['licensing.rotation: an Ed25519 signature is 32 bytes, not 64.'], self::errors(['publicKey' => self::NEW, 'previousKey' => self::OLD, 'rotation' => self::OTHER]));
        self::assertStringStartsWith('licensing.publicKey: the Ed25519 public key', self::errors([])[0] ?? '');
        self::assertSame(['licensing: a mapping of publicKey (and, after a change of key, previousKey, rotation and previousKeyValidUntil).'], self::errors(self::OLD));
    }

    public function testThePrivateKeyStaysWithTheAuthor(): void
    {
        $key = SigningKey::generate();
        $path = $this->dir.'/hub-signing.key';
        $key->writeTo($path);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($path)), -4), 'readable by its owner only');
        self::assertSame($key->publicKey(), SigningKey::fromFile($path)->publicKey());
        self::assertTrue(SigningKey::looksLikePrivateKey((string) file_get_contents($path)));
        self::assertFalse(SigningKey::looksLikePrivateKey(self::OLD), 'a public key is no private key');
        self::assertFalse(SigningKey::looksLikePrivateKey(base64_encode(random_bytes(64))), '64 random bytes are not taken for one');
        self::assertStringNotContainsString(trim((string) file_get_contents($path)), print_r($key, true), 'not even in a dump');

        try {
            $key->writeTo($path);
            self::fail('A private key is never written over.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        // A public key, or a bare seed, is not taken for a private key.
        file_put_contents($this->dir.'/seed', base64_encode(random_bytes(32)));
        try {
            SigningKey::fromFile($this->dir.'/seed');
            self::fail('A seed is refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not an Ed25519 private key', $e->getMessage());
        }
        file_put_contents($this->dir.'/public', self::OLD);
        $this->expectExceptionMessage('not an Ed25519 private key');
        SigningKey::fromFile($this->dir.'/public');
    }

    public function testARotationSignedHereVerifiesAsForgeStoreChecksIt(): void
    {
        $old = SigningKey::generate();
        $new = SigningKey::generate();
        $licensing = $old->rotateTo($new->publicKey());
        self::assertSame($old->publicKey(), $licensing->previousKey);
        self::assertTrue(sodium_crypto_sign_verify_detached(self::raw((string) $licensing->rotation), Licensing::ROTATION_PREFIX.$new->publicKey(), self::raw($old->publicKey())));
        self::assertSame(['publicKey' => $licensing->publicKey, 'registry' => self::URL] + $licensing->toArray(), self::manifest(['licensing' => $licensing->toArray()])['licensing'], 'and it passes hub.yaml\'s check');
    }

    public function testALicenceTokenInForgeSealsForm(): void
    {
        $payload = SigningKey::verifyToken(self::SEAL_TOKEN, self::SEAL_PUBLIC_KEY);
        self::assertSame('probe-game', $payload['software'] ?? null, 'Forge Seal\'s vector, signed by omnibase/forge, reads here');
        self::assertNull(SigningKey::verifyToken(self::SEAL_TOKEN, self::OLD), 'another key');

        $key = SigningKey::generate();
        $token = $key->signToken(['v' => 1, 'software' => 'com.acme.tool', 'email' => 'ada@example.org', 'issued_at' => 1792972800]);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{86}$/', $token, 'base64url without padding, a 64-byte signature');
        self::assertSame('com.acme.tool', SigningKey::verifyToken($token, $key->publicKey())['software'] ?? null);
        [$json, $signature] = explode('.', $token);
        self::assertNull(SigningKey::verifyToken(rtrim(strtr(base64_encode(str_replace('acme', 'acmf', (string) base64_decode(strtr($json, '-_', '+/')))), '+/', '-_'), '=').'.'.$signature, $key->publicKey()), 'a payload changed');
    }

    public function testAPrivateKeyIsNeverPublishedWithTheHub(): void
    {
        SigningKey::generate()->writeTo($this->dir.'/signing.key');
        file_put_contents($this->dir.'/notes.txt', 'notes');
        try {
            HubConfig::fromArray(['name' => 'Acme', 'copy' => ['notes.txt', 'signing.key']], self::URL, $this->dir);
            self::fail('A private key in "copy" is refused.');
        } catch (InvalidHubConfig $e) {
            self::assertSame(['copy: "signing.key" holds a private signing key - it is never published with the hub; move it out of the repository (and make a new pair if it was ever committed).'], $e->errors);
        }
    }

    /** @return array{0: int, 1: string, 2: string} [exit code, stdout, stderr] */
    private function bin(string $arguments, ?string $stdin = null): array
    {
        $process = proc_open(\sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg(\dirname(__DIR__).'/bin/omnihub'), $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);
        fwrite($pipes[0], (string) $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testTheAuthorsCommands(): void
    {
        // A key made inside a git work tree: written, with a warning.
        mkdir($this->dir.'/repository/.git', 0777, true);
        [$code, $out, $err] = $this->bin('key generate '.escapeshellarg($this->dir.'/repository/old.key'));
        self::assertSame(0, $code, $err);
        $private = trim((string) file_get_contents($this->dir.'/repository/old.key'));
        self::assertStringNotContainsString($private, $out.$err, 'the private key is never printed');
        self::assertStringContainsString('inside the git work tree', $err);
        self::assertMatchesRegularExpression('~licensing:\n  publicKey: "([A-Za-z0-9+/]{43}=)"~', $out);
        if (1 !== preg_match('~publicKey: "([^"]+)"~', $out, $match)) {
            self::fail('no public key printed');
        }
        $old = $match[1];

        [$code, , $err] = $this->bin('key generate '.escapeshellarg($this->dir.'/repository/old.key'));
        self::assertSame(1, $code, 'never over an existing file');
        self::assertStringContainsString('already exists', $err);

        // Outside any work tree: no warning.
        [$code, $out, $err] = $this->bin('key generate '.escapeshellarg($this->dir.'/new.key'));
        self::assertSame(0, $code, $err);
        self::assertStringNotContainsString('git work tree', $err);
        [$code, $new] = $this->bin('key public '.escapeshellarg($this->dir.'/new.key'));
        self::assertSame(0, $code);
        $new = trim($new);
        self::assertStringContainsString($new, $out);

        // The rotation, to the new public key or from its private key file: the same section, which hub.yaml's check accepts.
        [$code, $out, $err] = $this->bin('key rotate '.escapeshellarg($this->dir.'/repository/old.key').' '.escapeshellarg($new));
        self::assertSame(0, $code, $err);
        if (1 !== preg_match('~licensing:\n  publicKey: "([^"]+)"\n  previousKey: "([^"]+)"\n  rotation: "([^"]+)"\n  previousKeyValidUntil: (\d+)   # (\d{4}-\d\d-\d\d)\n~', $out, $section)) {
            self::fail('no licensing section printed: '.$out);
        }
        self::assertSame([$new, $old], [$section[1], $section[2]]);
        self::assertEqualsWithDelta(time() + 30 * 86400, (int) $section[4], 60, 'previousKeyValidUntil: 30 days from now by default');
        self::assertSame(gmdate('Y-m-d', (int) $section[4]), $section[5]);
        self::assertStringContainsString('omnihub licence resign', $out, 'what to do with the licences the old key signed');
        self::assertSame(['publicKey' => $new, 'registry' => self::URL, 'previousKey' => $old, 'rotation' => $section[3], 'previousKeyValidUntil' => (int) $section[4]], self::manifest(['licensing' => ['publicKey' => $section[1], 'previousKey' => $section[2], 'rotation' => $section[3], 'previousKeyValidUntil' => (int) $section[4]]])['licensing']);
        [, $dated] = $this->bin('key rotate '.escapeshellarg($this->dir.'/repository/old.key').' '.escapeshellarg($new).' --previous-until 2026-12-31');
        self::assertStringContainsString('previousKeyValidUntil: 1798675200   # 2026-12-31', $dated);
        [, $again] = $this->bin('key rotate '.escapeshellarg($this->dir.'/repository/old.key').' '.escapeshellarg($this->dir.'/new.key'));
        self::assertStringContainsString($section[3], $again, 'Ed25519 is deterministic');

        [$code, , $err] = $this->bin('key rotate '.escapeshellarg($this->dir.'/repository/old.key').' not-a-key');
        self::assertSame(1, $code);
        self::assertStringContainsString('32 bytes in base64', $err);

        // A licence signed offline, its payload on stdin (a format 1 payload of before: made format 2, its registry given).
        [$code, $token, $err] = $this->bin('licence sign '.escapeshellarg($this->dir.'/new.key').' - --registry '.self::URL.'/ --licensee Ada', '{"v": 1, "software": "com.acme.tool", "email": "ada@example.org"}');
        self::assertSame(0, $code, $err);
        self::assertSame(['v' => 2, 'software' => 'com.acme.tool', 'registry' => self::URL, 'licensee' => 'Ada', 'email' => 'ada@example.org'], array_diff_key(SigningKey::verifyToken(trim($token), $new) ?? [], ['issued_at' => 1]));
        self::assertStringContainsString('becomes 2', $err);
        [$code, , $err] = $this->bin('licence sign '.escapeshellarg($this->dir.'/new.key').' -', '[1, 2]');
        self::assertSame(1, $code);
        self::assertStringContainsString('a JSON object', $err);

        [$code, , $err] = $this->bin('key');
        self::assertSame(2, $code);
        self::assertStringContainsString('omnihub key generate <private-key-file>', $err);
    }

    public function testBinBuildsAndChecksAHubWithItsKey(): void
    {
        file_put_contents($this->dir.'/hub.json', json_encode(['name' => 'Acme', 'licensing' => ['publicKey' => self::NEW, 'previousKey' => self::OLD, 'rotation' => self::ROTATION]]));
        [$code, $out, $err] = $this->bin('check '.escapeshellarg($this->dir.'/hub.json').' --url='.self::URL);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('a licensing key ('.(new Licensing(self::NEW))->fingerprint().', a verified rotation)', $out);

        [$code, , $err] = $this->bin('build '.escapeshellarg($this->dir.'/hub.json').' '.escapeshellarg($this->dir.'/_site').' --url='.self::URL);
        self::assertSame(0, $code, $err);
        $manifest = json_decode((string) file_get_contents($this->dir.'/_site/-/hub.json'), true);
        self::assertIsArray($manifest);
        self::assertSame(['publicKey' => self::NEW, 'registry' => self::URL, 'previousKey' => self::OLD, 'rotation' => self::ROTATION], $manifest['licensing']);

        file_put_contents($this->dir.'/bad.json', json_encode(['name' => 'Acme', 'licensing' => ['publicKey' => self::OTHER, 'previousKey' => self::OLD, 'rotation' => self::ROTATION]]));
        [$code, , $err] = $this->bin('build '.escapeshellarg($this->dir.'/bad.json').' '.escapeshellarg($this->dir.'/_bad').' --url='.self::URL);
        self::assertSame(1, $code);
        self::assertStringContainsString('licensing.rotation: does not verify with previousKey', $err);
        self::assertDirectoryDoesNotExist($this->dir.'/_bad', 'nothing written');
    }
}
