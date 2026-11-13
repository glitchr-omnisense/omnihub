<?php

namespace Omnihub\Tests;

use Omnihub\HubConfig;
use Omnihub\InvalidHubConfig;
use Omnihub\LicenceCheck;
use Omnihub\LicenceLog;
use Omnihub\Licensing;
use Omnihub\OfflineLicence;
use Omnihub\Package;
use Omnihub\SigningKey;
use Omnihub\Version;
use PHPUnit\Framework\TestCase;

/**
 * Offline licences (format 2) as the clients check them: Forge Store 1.5.0 (io.glitchr.store,
 * LicenceCheck), Forge Seal 1.1.0 (io.glitchr.licensing, LicenseToken.CheckPackage) and Forge
 * Hub 1.3.1 (core/src/licence.rs). The clients' own recorded vectors verify here, the payloads
 * omnihub signs are byte for byte the ones they recorded, and every token signed here passes the
 * same check - the order of the checks and the refusals' words are theirs.
 */
class OfflineLicenceTest extends TestCase
{
    // Recorded by Forge Store 1.5.0 (Tests/Editor/LicenceTests.cs) and Forge Hub 1.3.1
    // (core/tests/fixtures/forge-store-licences.json): two tokens `omnihub licence sign` made with
    // a throwaway key whose private half was never kept; HUB_KEY is its public half.
    private const HUB_REGISTRY = 'https://example.github.io/hub';
    private const HUB_PACKAGE = 'com.example.tool';
    private const HUB_KEY = 'wI9lKrPyiFJOD1O7ZjUnVYL4XlfqM5idJ9Di3xjngvA=';
    private const ANY = 'eyJ2IjoyLCJyZWdpc3RyeSI6Imh0dHBzOi8vZXhhbXBsZS5naXRodWIuaW8vaHViIiwicGFja2FnZSI6ImNvbS5leGFtcGxlLnRvb2wiLCJsaWNlbnNlZSI6IkFkYSBFeGFtcGxlIDxhZGFAZXhhbXBsZS5vcmc-IiwiaXNzdWVkX2F0IjoxNzkyOTcyODAwfQ.dvqH85eBu4AAuPaAddcP5z0R6EO8dMKcmls79DJ9MOJaejFcrX2euD0nmc5PtgMfaj-lxBz6GStsLH-X3IAQAQ';
    private const BOUND = 'eyJ2IjoyLCJyZWdpc3RyeSI6Imh0dHBzOi8vZXhhbXBsZS5naXRodWIuaW8vaHViIiwicGFja2FnZSI6ImNvbS5leGFtcGxlLnRvb2wiLCJsaWNlbnNlZSI6ImFkYUBleGFtcGxlLm9yZyIsIm1hY2hpbmUiOiIzMTdmMDZmNzdiZjcyZTAyY2I2ODJjMzY1ZmQ1ZWIwNDAwYzY0Nzk1MDY1MjhkNzQ1ZjVlYzNmMjNiMDRjYWExIiwiaXNzdWVkX2F0IjoxNzkyOTcyODAwLCJ2YWxpZF91bnRpbCI6MTc5NTU2NDgwMCwidXBkYXRlc191bnRpbCI6MTgyNDUwODgwMH0.BsY86NMALtxcbjWAPZToGg0LecvXF91KrAvjdTHYl1HfE6DQKndQWjJ7U8g6BXQxcjtAyCmrWSvDfil2tw_vCQ';
    private const BOUND_MACHINE = 'machine-0001';
    private const BOUND_MACHINE_HASH = '317f06f77bf72e02cb682c365fd5eb0400c6479506528d745f5ec3f23b04caa1';
    private const T0 = 1792972800;
    private const DAY = 86400;

    // Forge Seal's format 1 vector (Tests/Editor/LicenseTokenTests.cs), signed by omnibase/forge with a fixed test pair.
    private const SEAL_PUBLIC_KEY = '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=';
    private const SEAL_TOKEN = 'eyJ2IjoxLCJsaWNlbnNlIjo0Miwic29mdHdhcmUiOiJwcm9iZS1nYW1lIiwiZW1haWwiOiJhZGFAZXhhbXBsZS5vcmciLCJtYWNoaW5lIjoiMzE3ZjA2Zjc3YmY3MmUwMmNiNjgyYzM2NWZkNWViMDQwMGM2NDc5NTA2NTI4ZDc0NWY1ZWMzZjIzYjA0Y2FhMSIsImlzc3VlZF9hdCI6MTc5Mjk3MjgwMCwidmFsaWRfdW50aWwiOjE3OTU1NjQ4MDAsImxpY2Vuc2VfZXhwaXJlcyI6bnVsbCwidXBkYXRlc191bnRpbCI6MTgyNDUwODgwMH0.lQZSS34z_XRwGVE22WJux9fWE4SrIKtQ6tJa0rnhdZTOHlNsWkPPL37Tj8ML_xd6FuW2FHBVWS7DZUm54rA3AA';

    // RFC 8032's test 1, which Forge Store's and Forge Hub's test signers are checked against.
    private const RFC8032_SEED = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
    private const RFC8032_PUBLIC = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
    private const RFC8032_SIGNATURE = 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/omnihub-offline-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
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

    /** @return array<array-key, mixed> */
    private static function payloadOf(string $token): array
    {
        $read = LicenceCheck::read($token);
        self::assertNotNull($read, 'a token');

        return $read[0];
    }

    /** A value deep in decoded JSON, or null. */
    private static function dig(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function jsonOf(string $token): string
    {
        return (string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'));
    }

    /**
     * A key file in the test's folder, and its key.
     *
     * @return array{0: string, 1: SigningKey}
     */
    private function key(string $name): array
    {
        $key = SigningKey::generate();
        $key->writeTo($this->dir.'/'.$name);

        return [$this->dir.'/'.$name, $key];
    }

    /**
     * A hub's configuration (JSON: no symfony/yaml needed) for $key.
     *
     * @param array<string, mixed> $extra
     */
    private function hub(SigningKey $key, array $extra = []): string
    {
        file_put_contents($this->dir.'/hub.json', json_encode(['name' => 'Example', 'url' => self::HUB_REGISTRY.'/', 'scopes' => ['com.example'], 'licensing' => ['publicKey' => $key->publicKey()]] + $extra));

        return $this->dir.'/hub.json';
    }

    public function testTheClientsRecordedVectorsOpenAsTheyRecordedIt(): void
    {
        $keys = new Licensing(self::HUB_KEY);

        // Forge Hub's omnihubs_offline_licences_open_with_its_key_and_a_foreign_one_is_refused, case by case.
        $any = LicenceCheck::verify(self::ANY, $keys, self::HUB_REGISTRY, self::HUB_PACKAGE, OfflineLicence::machineHash('a machine its seller never saw'), 4102444800);
        self::assertTrue($any->opens(), (string) $any->refusal);
        self::assertSame('Ada Example <ada@example.org>', $any->payload['licensee'] ?? null);
        self::assertArrayNotHasKey('machine', $any->payload ?? [], 'any machine');
        self::assertArrayNotHasKey('valid_until', $any->payload ?? [], 'no end');

        $bound = LicenceCheck::verify(self::BOUND, $keys, self::HUB_REGISTRY.'/', self::HUB_PACKAGE, OfflineLicence::machineHash(self::BOUND_MACHINE), self::T0 + self::DAY);
        self::assertTrue($bound->opens(), (string) $bound->refusal);
        self::assertSame(self::BOUND_MACHINE_HASH, $bound->payload['machine'] ?? null);
        self::assertSame('2026-11-25', OfflineLicence::day(LicenceCheck::number($bound->payload ?? [], 'valid_until')), 'Forge Store: "until 2026-11-25"');

        self::assertSame('the licence is another machine\'s.', LicenceCheck::verify(self::BOUND, $keys, self::HUB_REGISTRY, self::HUB_PACKAGE, OfflineLicence::machineHash('machine-0002'), self::T0 + self::DAY)->refusal);
        self::assertSame('the licence ran out on 2026-11-25: ask the store for a new one.', LicenceCheck::verify(self::BOUND, $keys, self::HUB_REGISTRY, self::HUB_PACKAGE, OfflineLicence::machineHash(self::BOUND_MACHINE), 1795564800)->refusal);
        self::assertSame('the licence is for com.example.tool, not com.example.other.', LicenceCheck::verify(self::ANY, $keys, self::HUB_REGISTRY, 'com.example.other', null, self::T0)->refusal);
        self::assertSame('the licence is another store\'s: it names https://example.github.io/hub, and com.example.tool comes from https://forge.example.com/upm.', LicenceCheck::verify(self::ANY, $keys, 'https://forge.example.com/upm', self::HUB_PACKAGE, null, self::T0)->refusal);
        self::assertSame('the licence\'s signature does not verify with the store\'s key: it is another store\'s, or it was changed.', LicenceCheck::verify(self::ANY, new Licensing(self::SEAL_PUBLIC_KEY), self::HUB_REGISTRY, self::HUB_PACKAGE)->refusal);

        // The registry compared as the clients compare it: a final slash, the scheme's and the host's case, a default port aside; the path keeps its case.
        self::assertTrue(LicenceCheck::verify(self::ANY, $keys, 'HTTPS://Example.GitHub.io:443/hub/', self::HUB_PACKAGE, null, self::T0)->opens());
        self::assertFalse(LicenceCheck::verify(self::ANY, $keys, 'https://example.github.io/Hub', self::HUB_PACKAGE, null, self::T0)->opens());
        self::assertFalse(LicenceCheck::verify(self::ANY, $keys, 'https://example.github.io:8443/hub', self::HUB_PACKAGE, null, self::T0)->opens());

        // Forge Seal's format 1 token: genuine, refused for a package.
        self::assertStringStartsWith('this licence is of format 1', (string) LicenceCheck::verify(self::SEAL_TOKEN, new Licensing(self::SEAL_PUBLIC_KEY), self::HUB_REGISTRY, self::HUB_PACKAGE)->refusal);
        self::assertSame('this is not a licence token (two base64url parts separated by a dot, the first a JSON payload).', LicenceCheck::verify('not.a.token', $keys)->refusal);
    }

    public function testThePayloadsOmnihubWritesAreTheClientsRecordedOnesByteForByte(): void
    {
        $any = OfflineLicence::payload(['registry' => self::HUB_REGISTRY.'/', 'package' => self::HUB_PACKAGE, 'licensee' => '  Ada Example <ada@example.org> ', 'issued_at' => self::T0]);
        self::assertSame(self::jsonOf(self::ANY), OfflineLicence::json($any), 'field order, no escaped slash, nothing empty');

        $bound = OfflineLicence::payload(['updates_until' => 1824508800, 'valid_until' => 1795564800, 'machine' => OfflineLicence::machineHash(self::BOUND_MACHINE), 'issued_at' => self::T0, 'licensee' => 'ada@example.org', 'package' => self::HUB_PACKAGE, 'registry' => self::HUB_REGISTRY]);
        self::assertSame(self::jsonOf(self::BOUND), OfflineLicence::json($bound), 'whatever order the fields come in');

        // And through the command line: the same payload, signed by a key of this test.
        [$file, $key] = $this->key('seller.key');
        [$code, $out, $err] = $this->bin(\sprintf('licence sign %s --registry %s --package %s --licensee %s --machine %s --issued-at %d --valid-until 2026-11-25 --updates-until 2027-10-26', escapeshellarg($file), self::HUB_REGISTRY, self::HUB_PACKAGE, escapeshellarg('ada@example.org'), self::BOUND_MACHINE, self::T0));
        self::assertSame(0, $code, $err);
        $token = trim($out);
        self::assertSame(self::jsonOf(self::BOUND), self::jsonOf($token));
        self::assertStringNotContainsString(self::BOUND_MACHINE, $out.$err, 'the machine\'s identifier is never written in clear');
        self::assertTrue(LicenceCheck::verify($token, new Licensing($key->publicKey()), self::HUB_REGISTRY, self::HUB_PACKAGE, OfflineLicence::machineHash(self::BOUND_MACHINE), self::T0 + self::DAY)->opens());
    }

    public function testTheMachineIsHashedAsTheClientsHashIt(): void
    {
        self::assertSame(self::BOUND_MACHINE_HASH, hash('sha256', self::BOUND_MACHINE));
        self::assertSame(self::BOUND_MACHINE_HASH, OfflineLicence::machineHash(" machine-0001 \n"), 'Forge Hub\'s machine_hash(" machine-0001 ")');
        // .NET's String.Trim() and Rust's str::trim() take Unicode's white space away, not only ASCII's.
        self::assertSame(self::BOUND_MACHINE_HASH, OfflineLicence::machineHash("\u{00A0}\u{3000}machine-0001\u{2029}\u{0085}"));
        self::assertNotSame(self::BOUND_MACHINE_HASH, OfflineLicence::machineHash('MACHINE-0001'), 'the identifier keeps its case');
        $uuid = '4C4C4544-0042-5810-8036-B4C04F4D4E32';
        self::assertSame(hash('sha256', $uuid), OfflineLicence::machineHash($uuid), 'UTF-8 bytes, lowercase hex');

        [$file] = $this->key('seller.key');
        $base = 'licence sign '.escapeshellarg($file).' --registry '.self::HUB_REGISTRY.' --package '.self::HUB_PACKAGE.' --licensee Ada ';
        [$code, $out] = $this->bin($base.'--machine-hash '.strtoupper(self::BOUND_MACHINE_HASH));
        self::assertSame(0, $code);
        self::assertSame(self::BOUND_MACHINE_HASH, self::payloadOf(trim($out))['machine'] ?? null, 'the hash a buyer sent, written in lowercase');
        [$code, $out] = $this->bin($base);
        self::assertSame(0, $code);
        self::assertArrayNotHasKey('machine', self::payloadOf(trim($out)), 'without either: any machine, never an empty machine');
        [$code, , $err] = $this->bin($base.'--machine '.escapeshellarg(strtolower($uuid)));
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('on macOS Unity reports the identifier in uppercase', $err);
    }

    public function testRfc8032AsTheClientsTestSignersAreChecked(): void
    {
        $seed = (string) hex2bin(self::RFC8032_SEED);
        if ('' === $seed) {
            self::fail('RFC 8032\'s seed');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);
        self::assertSame(self::RFC8032_PUBLIC, bin2hex(sodium_crypto_sign_publickey($pair)));
        self::assertSame(self::RFC8032_SIGNATURE, bin2hex(sodium_crypto_sign_detached('', sodium_crypto_sign_secretkey($pair))), 'the same Ed25519 as Forge Seal\'s managed one and Forge Hub\'s ring');
    }

    public function testEveryProblemOnALineOfItsOwn(): void
    {
        [$file] = $this->key('seller.key');
        [$code, $out, $err] = $this->bin('licence sign '.escapeshellarg($file).' --registry http://example.github.io/hub --package Com.Example --software game --valid-until 2026-01-01 --days 3 --machine-hash abc --machine x');
        self::assertSame(1, $code);
        self::assertSame('', $out, 'no token');
        self::assertSame([
            '--package, --software: a package\'s licence or a game\'s, not both.',
            '--valid-until, --days: one or the other.',
            '--machine, --machine-hash: one or the other.',
            'registry: https (http only for localhost): Forge Store reads hubs over https, not "http://example.github.io/hub".',
            'package: the UPM name the licence opens (--package com.example.tool), or, for a game or an application, its slug (--software).',
            'licensee: who it was sold to, a name and/or an e-mail (--licensee "Ada <ada@example.org>"); Forge Store shows it.',
        ], explode("\n", trim($err)));

        $problems = fn (array $fields): array => OfflineLicence::problems($fields + ['v' => 2, 'registry' => self::HUB_REGISTRY, 'package' => self::HUB_PACKAGE, 'licensee' => 'Ada', 'issued_at' => self::T0]);
        self::assertSame([], $problems([]));
        self::assertSame(['package: a UPM name, lowercase and reverse-domain ("com.example.tool"), not "Com.Example".'], $problems(['package' => 'Com.Example']));
        self::assertSame(['licensee: UTF-8 text without control characters (no line break, no tab).'], $problems(['licensee' => "Ada\nBcc: x"]));
        self::assertSame(['licensee: 200 characters at most, not 201.'], $problems(['licensee' => str_repeat('é', 201)]));
        self::assertSame([], $problems(['licensee' => str_repeat('é', 200)]), 'characters, not bytes');
        self::assertSame(['valid_until: UNIX seconds, a JSON integer (Forge Seal reads anything else as 0), not "1795564800".'], $problems(['valid_until' => '1795564800']));
        self::assertSame(['valid_until: after issued_at (2026-10-26), not 2026-10-25.'], $problems(['valid_until' => self::T0 - self::DAY]));
        self::assertSame(['machine: SHA-256 of the machine\'s identifier, 64 lowercase hex digits (--machine <identifier> hashes it, --machine-hash <hex> takes it as given); leave it out for any machine.'], $problems(['machine' => strtoupper(self::BOUND_MACHINE_HASH)]));
        self::assertSame(['seats: not a field of an offline licence (v, software, registry, package, licensee, email, machine, issued_at, valid_until, updates_until).'], $problems(['seats' => 3]));
        self::assertSame(['v: 2, the format bound to its store (a format 1 token is refused by Forge Store).'], $problems(['v' => 1]));
        self::assertSame(['registry: an absolute address, https://<owner>.github.io/<repository>, not "example.github.io/hub".'], $problems(['registry' => 'example.github.io/hub']));
        self::assertSame([], $problems(['registry' => 'http://localhost:8080/upm']), 'http on this machine only');

        // A misspelt flag would sign something else than meant.
        [$code, , $err] = $this->bin('licence sign '.escapeshellarg($file).' --registry '.self::HUB_REGISTRY.' --package '.self::HUB_PACKAGE.' --licencee Ada');
        self::assertSame(2, $code);
        self::assertStringStartsWith('--licencee: not a flag of these commands.', $err);
    }

    public function testSignedForAHubItsRegistryAndItsKeyComeFromIt(): void
    {
        [$file, $key] = $this->key('seller.key');
        [, $other] = $this->key('other.key');
        $hub = $this->hub($key, ['sources' => [['type' => 'git', 'name' => 'Paint', 'url' => 'https://github.com/example/unity-paint.git', 'package' => 'com.example.paint']]]);

        [$code, $out, $err] = $this->bin(\sprintf('licence sign %s --hub %s --package %s --licensee %s --days 365', escapeshellarg($file), escapeshellarg($hub), self::HUB_PACKAGE, escapeshellarg('Ada <ada@example.org>')));
        self::assertSame(0, $code, $err);
        $token = trim($out);
        self::assertStringNotContainsString("\n", trim($out), 'the token alone, on one line');
        $payload = self::payloadOf($token);
        self::assertSame(self::HUB_REGISTRY, $payload['registry'] ?? null, 'the hub\'s own registry, its final slash dropped');
        self::assertSame(LicenceCheck::number($payload, 'issued_at') + 365 * self::DAY, $payload['valid_until'] ?? null);

        // The registry is the one the hub's manifest lists first, and the one its licensing names.
        $manifest = json_decode(HubConfig::fromFile($hub)->hub()->files()['/-/hub.json'][1], true);
        self::assertIsArray($manifest);
        self::assertSame($payload['registry'], self::dig($manifest, 'sources', 0, 'url'));
        self::assertSame($payload['registry'], self::dig($manifest, 'licensing', 'registry'));

        // stderr: the summary, never the private key.
        self::assertStringContainsString('Licence (format 2) for package com.example.tool, from https://example.github.io/hub, licensed to Ada <ada@example.org>.', $err);
        self::assertStringContainsString('machine:  any machine', $err);
        self::assertStringContainsString('key:      '.$key->fingerprint(), $err);
        self::assertStringNotContainsString(trim((string) file_get_contents($file)), $out.$err);

        // hub.yaml by default, in the folder it is run from (JSON is YAML).
        rename($hub, $this->dir.'/hub.yaml.json');
        // bin/omnihub reads it with symfony/yaml when its autoloader has it; either way, it is the one read.
        copy($this->dir.'/hub.yaml.json', $this->dir.'/hub.yaml');
        [$code, $out, $err] = $this->bin(\sprintf('licence sign %s --package %s --licensee Ada', escapeshellarg($file), self::HUB_PACKAGE));
        if (0 === $code) {
            self::assertSame(self::HUB_REGISTRY, self::payloadOf(trim($out))['registry'] ?? null);
        } else {
            self::assertStringStartsWith("hub.yaml:\n  - hub.yaml needs symfony/yaml", $err);
        }
        unlink($this->dir.'/hub.yaml');
        [$code, , $err] = $this->bin(\sprintf('licence sign %s --hub hub.yaml.json --package com.example.paint --licensee Ada', escapeshellarg($file)));
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('lists com.example.paint as a git source', $err);
        [$code, , $err] = $this->bin(\sprintf('licence sign %s --hub hub.yaml.json --package org.other.tool --licensee Ada', escapeshellarg($file)));
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('org.other.tool is under none of hub.yaml.json\'s scopes (com.example)', $err);

        // Another key, another registry, a payload naming another hub: refused.
        [$code, , $err] = $this->bin(\sprintf('licence sign %s --hub hub.yaml.json --package %s --licensee Ada', escapeshellarg($this->dir.'/other.key'), self::HUB_PACKAGE));
        self::assertSame(1, $code);
        self::assertSame(\sprintf('this key does not sign for this hub: %s vs %s (hub.yaml.json\'s licensing.publicKey).', $other->fingerprint(), $key->fingerprint()), trim($err));
        [$code, , $err] = $this->bin(\sprintf('licence sign %s - --hub hub.yaml.json --registry https://other.github.io/hub --licensee Ada', escapeshellarg($file)), '{"package": "com.example.tool", "registry": "https://third.github.io/hub"}');
        self::assertSame(1, $code);
        self::assertSame([
            '--registry: https://other.github.io/hub is not the registry of hub.yaml.json, https://example.github.io/hub.',
            'registry: the payload names https://other.github.io/hub, and hub.yaml.json\'s registry is https://example.github.io/hub.',
        ], explode("\n", trim($err)));

        file_put_contents($this->dir.'/bare.json', json_encode(['name' => 'Bare', 'url' => self::HUB_REGISTRY]));
        [$code, , $err] = $this->bin(\sprintf('licence sign %s --hub bare.json --package %s --licensee Ada', escapeshellarg($file), self::HUB_PACKAGE));
        self::assertSame(1, $code);
        self::assertStringContainsString('declares no licensing key', $err);
    }

    public function testAGamesLicenceAsForgeSealChecksIt(): void
    {
        [$file, $key] = $this->key('seller.key');
        [$code, $out, $err] = $this->bin(\sprintf('licence sign %s --registry %s --software probe-game --licensee Ada --machine %s', escapeshellarg($file), self::HUB_REGISTRY, self::BOUND_MACHINE));
        self::assertSame(0, $code, $err);
        $token = trim($out);
        $payload = self::payloadOf($token);
        self::assertSame('probe-game', $payload['software'] ?? null);
        self::assertArrayNotHasKey('package', $payload, 'a game is not a package');
        $keys = new Licensing($key->publicKey());
        self::assertTrue(LicenceCheck::verify($token, $keys, self::HUB_REGISTRY, '', OfflineLicence::machineHash(self::BOUND_MACHINE), null, 'probe-game')->opens(), 'LicenseToken.Check(token, key, "probe-game", machine, registry, null)');
        self::assertSame('the licence is for probe-game, not other-game (wrong_software).', LicenceCheck::verify($token, $keys, self::HUB_REGISTRY, '', null, null, 'other-game')->refusal);
        self::assertSame('the licence is for a game or an application, not a package, not com.example.tool.', LicenceCheck::verify($token, $keys, self::HUB_REGISTRY, self::HUB_PACKAGE)->refusal, 'Forge Store never opens a package with it');
    }

    public function testVerifySaysWhatForgeStoreWouldSay(): void
    {
        [$code, $out] = $this->bin(\sprintf('licence verify %s %s --registry %s --package %s --machine %s --at 2026-11-20', self::HUB_KEY, self::BOUND, self::HUB_REGISTRY, self::HUB_PACKAGE, self::BOUND_MACHINE));
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Licence (format 2) for package com.example.tool, from https://example.github.io/hub, licensed to ada@example.org.', $out);
        self::assertStringContainsString('validity: until 2026-11-25', $out);
        self::assertStringContainsString('updates:  until 2027-10-26', $out);
        self::assertStringContainsString('machine:  one machine (317f06f77bf7...)', $out);
        self::assertStringEndsWith("Forge Store opens it.\n", $out);

        [$code, $out] = $this->bin(\sprintf('licence verify %s %s --registry %s --package %s --machine machine-0002 --at 2026-11-20', self::HUB_KEY, self::BOUND, self::HUB_REGISTRY, self::HUB_PACKAGE));
        self::assertSame(1, $code);
        self::assertStringEndsWith("Forge Store refuses it: the licence is another machine's.\n", $out);
        [$code, $out] = $this->bin(\sprintf('licence verify %s %s --at 2026-11-25', self::HUB_KEY, self::BOUND));
        self::assertSame(1, $code);
        self::assertStringContainsString('ran out on 2026-11-25', $out);

        // Without the registry, the package or the machine: opens, and says what was not compared.
        [$code, $out] = $this->bin(\sprintf('licence verify %s - --at 2026-11-20', self::HUB_KEY), self::BOUND);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('note:     registry: not compared', $out);
        self::assertStringContainsString('note:     package: not compared', $out);
        self::assertStringContainsString('note:     machine: bound to one machine, not compared', $out);

        // The hub's configuration, or its published manifest: their key and their registry.
        $manifest = ['version' => 1, 'name' => 'Example', 'sources' => [['name' => 'Example', 'type' => 'upm', 'url' => self::HUB_REGISTRY, 'scopes' => ['com.example']]], 'licensing' => ['publicKey' => self::HUB_KEY, 'registry' => self::HUB_REGISTRY]];
        file_put_contents($this->dir.'/published.json', json_encode($manifest));
        [$code, $out] = $this->bin('licence verify published.json '.self::ANY.' --package '.self::HUB_PACKAGE);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('registry https://example.github.io/hub', $out);
        $manifest['licensing']['registry'] = 'https://other.github.io/hub';
        file_put_contents($this->dir.'/other.json', json_encode($manifest));
        [$code, $out] = $this->bin('licence verify '.self::ANY.' --hub other.json');
        self::assertSame(1, $code);
        self::assertStringContainsString('the licence is another store\'s', $out);
        file_put_contents($this->dir.'/hub.json', json_encode(['name' => 'Example', 'url' => self::HUB_REGISTRY, 'licensing' => ['publicKey' => self::HUB_KEY]]));
        [$code, $out] = $this->bin('licence verify hub.json '.self::ANY.' --package '.self::HUB_PACKAGE);
        self::assertSame(0, $code, $out);
        [$code, $out] = $this->bin('licence verify '.self::ANY.' --key '.self::HUB_KEY.' --package com.example.other');
        self::assertSame(1, $code);

        // Never a private key, even given by mistake.
        [$file] = $this->key('seller.key');
        $private = trim((string) file_get_contents($file));
        foreach ([escapeshellarg($file), escapeshellarg($private)] as $given) {
            [$code, $out, $err] = $this->bin('licence verify '.$given.' '.self::ANY);
            self::assertSame(2, $code);
            self::assertStringContainsString('private key: verify takes the public key', $err);
            self::assertStringNotContainsString($private, $out.$err);
        }
    }

    public function testAfterARotationTheOldKeysLicencesAndTheirReSigning(): void
    {
        [$oldFile, $old] = $this->key('old.key');
        [$newFile, $new] = $this->key('new.key');
        $log = $this->dir.'/sales.jsonl';
        $sign = fn (string $key, string $args) => $this->bin(\sprintf('licence sign %s --registry %s --log %s %s', escapeshellarg($key), self::HUB_REGISTRY, escapeshellarg($log), $args));

        [$code, $first, $err] = $sign($oldFile, '--package com.example.tool --licensee Ada');
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('logged to '.$log, $err);
        [, $second] = $sign($oldFile, '--package com.example.other --licensee '.escapeshellarg('Grace <grace@example.org>').' --log-token');
        [, $expired] = $sign($oldFile, '--package com.example.tool --licensee Old --issued-at 2020-01-01 --valid-until 2020-02-01');

        // The log: append-only, its owner's only, the payload and the key's fingerprint - the token only when asked, never the private key.
        self::assertSame('0600', substr(\sprintf('%o', fileperms($log)), -4));
        $lines = file($log, \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(3, $lines);
        $entry = json_decode($lines[0], true);
        self::assertIsArray($entry);
        self::assertSame(['logged_at', 'key', 'payload'], array_keys($entry));
        self::assertSame($old->fingerprint(), $entry['key']);
        self::assertSame(self::payloadOf(trim($first)), $entry['payload']);
        self::assertStringNotContainsString(trim($first), $lines[0]);
        self::assertStringContainsString(trim($second), $lines[1], '--log-token');
        self::assertStringNotContainsString(trim((string) file_get_contents($oldFile)), (string) file_get_contents($log));
        self::assertCount(3, (new LicenceLog($log))->entries());

        // The rotation: the previous key opens its licences until previousKeyValidUntil, never without it.
        $until = self::T0 + 30 * self::DAY;
        $rotated = $old->rotateTo($new->publicKey(), $until);
        self::assertSame($until, $rotated->previousKeyValidUntil);
        $opened = LicenceCheck::verify(trim($first), $rotated, self::HUB_REGISTRY, self::HUB_PACKAGE, null, $until - 1);
        self::assertTrue($opened->opens(), (string) $opened->refusal);
        self::assertTrue($opened->byPreviousKey);
        self::assertStringContainsString('signed with the hub\'s previous key', implode("\n", $opened->notes));
        self::assertSame('the licence was signed with the store\'s previous key, which no longer opens licences since 2026-11-25: ask the store for one signed with its current key.', LicenceCheck::verify(trim($first), $rotated, self::HUB_REGISTRY, self::HUB_PACKAGE, null, $until)->refusal);
        self::assertStringContainsString('keeps only when the hub announces until when', (string) LicenceCheck::verify(trim($first), $old->rotateTo($new->publicKey()), self::HUB_REGISTRY, self::HUB_PACKAGE, null, self::T0)->refusal);

        // Signed again with the new key: the same payloads, the ran-out one left, one line per buyer.
        [$code, $out, $err] = $this->bin(\sprintf('licence resign %s %s --registry %s --log %s', escapeshellarg($newFile), escapeshellarg($log), self::HUB_REGISTRY, escapeshellarg($log)));
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('left - it ran out on 2020-02-01', $err);
        self::assertStringContainsString('2 licences signed again with '.$new->fingerprint(), $err);
        $rows = array_map(fn (string $row) => explode("\t", $row), explode("\n", trim($out)));
        self::assertSame([['Ada', 'com.example.tool'], ['Grace <grace@example.org>', 'com.example.other']], array_map(fn (array $row) => \array_slice($row, 0, 2), $rows));
        self::assertSame(self::payloadOf(trim($first)), self::payloadOf($rows[0][2] ?? ''), 'the payload as sold');
        self::assertTrue(LicenceCheck::verify($rows[0][2] ?? '', $rotated, self::HUB_REGISTRY, self::HUB_PACKAGE, null, $until + self::DAY)->opens(), 'with the new key, past the previous key\'s end');
        self::assertFalse(LicenceCheck::verify($rows[0][2] ?? '', $rotated)->byPreviousKey);
        self::assertNotSame('', trim($expired));

        // Run again: nothing left to sign.
        [$code, $out, $err] = $this->bin(\sprintf('licence resign %s %s --registry %s', escapeshellarg($newFile), escapeshellarg($log), self::HUB_REGISTRY));
        self::assertSame(0, $code, $err);
        self::assertSame('', $out);
        self::assertStringContainsString('0 licences signed again', $err);
    }

    public function testTheManifestCarriesTheRegistryThePreviousKeysEndAndWhatIsSold(): void
    {
        $old = SigningKey::generate();
        $new = SigningKey::generate();
        $section = $old->rotateTo($new->publicKey(), 1795564800)->toArray();
        $settings = ['name' => 'Example', 'licensing' => $section, 'licensed' => ['com.example.tool', ['package' => 'com.example.paint', 'get' => 'https://shop.example.org/paint']]];
        $hub = HubConfig::fromArray($settings, self::HUB_REGISTRY.'/')->hub();
        $hub->add([new Package('com.example.paint', [new Version('1.0.0', ['name' => 'com.example.paint', 'version' => '1.0.0'], 'https://example.org/paint-1.0.0.tgz', sha1('paint'))])]);
        $manifest = json_decode($hub->files()['/-/hub.json'][1], true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['licensing']);
        self::assertSame(['algorithm', 'publicKey', 'registry', 'previousKey', 'rotation', 'previousKeyValidUntil'], array_keys($manifest['licensing']), 'omnibase/forge\'s names and order');
        self::assertSame(self::HUB_REGISTRY, $manifest['licensing']['registry']);
        self::assertSame(1795564800, $manifest['licensing']['previousKeyValidUntil'], 'a JSON integer');
        self::assertSame([['package' => 'com.example.tool'], ['package' => 'com.example.paint', 'get' => 'https://shop.example.org/paint']], $manifest['licensed']);
        self::assertSame(self::HUB_REGISTRY, $hub->registry());
        self::assertStringContainsString('<a href="https://shop.example.org/paint">Licensed: get a licence</a>', $hub->files()['/'][1] ?? '', 'once the package is published');

        // A day for previousKeyValidUntil (YAML reads an unquoted one as UNIX seconds), and the registry given: it must be the hub's.
        self::assertSame(1795564800, HubConfig::fromArray(['licensing' => ['previousKeyValidUntil' => '2026-11-25'] + $section], self::HUB_REGISTRY)->licensing?->previousKeyValidUntil);
        self::assertSame(self::HUB_REGISTRY, HubConfig::fromArray(['licensing' => ['registry' => self::HUB_REGISTRY.'/'] + $section], self::HUB_REGISTRY)->licensing?->registry);

        $errors = function (array $settings): array {
            try {
                HubConfig::fromArray($settings + ['name' => 'Example'], self::HUB_REGISTRY);
            } catch (InvalidHubConfig $e) {
                return $e->errors;
            }

            return [];
        };
        self::assertSame(['licensing.registry: the hub\'s own registry, https://example.github.io/hub (sources[0].url, which the build writes there) - not "https://other.github.io/hub"; leave it out.'], $errors(['licensing' => ['registry' => 'https://other.github.io/hub'] + $section]));
        self::assertSame(['licensing.previousKeyValidUntil: goes with a previousKey and its rotation (omnihub key rotate).'], $errors(['licensing' => ['publicKey' => $new->publicKey(), 'previousKeyValidUntil' => 1795564800]]));
        self::assertSame(['licensing.previousKeyValidUntil: UNIX seconds, or a day (YYYY-MM-DD, UTC): until when licences the previous key signed may open (omnihub key rotate --previous-until).'], $errors(['licensing' => ['previousKeyValidUntil' => 'soon'] + $section]));
        self::assertSame(['licensed: packages sold with a licence need the key their licences are signed with (licensing.publicKey; omnihub key generate).'], $errors(['licensed' => ['com.example.tool']]));
        self::assertSame([
            'licensed[0].package: a UPM name ("com.example.tool"), once, not "Tool".',
            'licensed[2].package: a UPM name ("com.example.tool"), once, not "com.example.tool".',
            'licensed[3].get: the https:// address where a licence is bought (a shop, a payment link), not "http://shop.example.org".',
            'licensed[4]: a package sold with a licence, {package: com.example.tool, get: https://<your shop>}.',
        ], $errors(['licensing' => $section, 'licensed' => ['Tool', 'com.example.tool', 'com.example.tool', ['package' => 'com.example.x', 'get' => 'http://shop.example.org'], ['price' => 5]]]));
        $plain = json_decode(HubConfig::fromArray(['licensing' => $section], self::HUB_REGISTRY)->hub()->files()['/-/hub.json'][1], true);
        self::assertIsArray($plain);
        self::assertArrayNotHasKey('licensed', $plain, 'nothing sold, nothing written');
    }
}
