<?php

namespace Omnihub;

/**
 * A licence token checked as the clients check it, for a seller to see what a buyer will see
 * before sending it (omnihub licence verify) - Forge Store's LicenceCheck.Verify (io.glitchr.store
 * 1.5.0), Forge Hub's licence::verify (1.3.1) and Forge Seal's LicenseToken.CheckPackage
 * (io.glitchr.licensing 1.1.0), in the same order and with the same words:
 *
 *   1. a token: two base64url parts separated by a dot, the first a JSON object, the second 64 bytes;
 *   2. its signature verifies with the hub's key - or with its previous key, while the hub
 *      announces it (previousKeyValidUntil);
 *   3. (a game's, when its slug is given) software is that slug;
 *   4. v is 2 or more;
 *   5. registry is the hub's (a final slash, the scheme's and the host's case, a default port aside);
 *   6. package is the package's;
 *   7. machine, when it names one, is that machine's;
 *   8. now is before valid_until, when it has one.
 *
 * What the seller does not give (the registry, the package, the buyer's machine) is not compared,
 * and said so in notes. A pin (which key a user's Forge Store trusts) is the user's: here the
 * hub's key stands for it.
 */
final class LicenceCheck
{
    /**
     * @param array<array-key, mixed>|null $payload the payload read, even when refused (null when it is no token)
     * @param string|null                  $refusal why a client refuses it, in Forge Store's words; null when it opens
     * @param list<string>                 $notes   what was not compared, and what is worth knowing
     */
    private function __construct(
        public readonly ?array $payload,
        public readonly ?string $refusal,
        public readonly bool $byPreviousKey = false,
        public readonly array $notes = [],
    ) {
    }

    public function opens(): bool
    {
        return null === $this->refusal;
    }

    /**
     * @param Licensing   $keys        the hub's licensing (publicKey; previousKey and previousKeyValidUntil after a rotation)
     * @param string|null $registry    the registry the package is installed from; null: not compared
     * @param string|null $package     the package's UPM name; null: not compared
     * @param string|null $machineHash SHA-256 hex of the buyer's machine identifier; null: not compared
     * @param string|null $software    a game's slug, checked as LicenseToken.Check does; null: not compared
     */
    public static function verify(string $token, Licensing $keys, ?string $registry = null, ?string $package = null, ?string $machineHash = null, ?int $now = null, ?string $software = null): self
    {
        $now ??= time();
        $read = self::read($token);
        if (null === $read) {
            return new self(null, 'this is not a licence token (two base64url parts separated by a dot, the first a JSON payload).');
        }
        [$payload, $json, $signature] = $read;
        $notes = [];
        $byPrevious = false;
        if (!self::signedBy($signature, $json, $keys->publicKey)) {
            if (null === $keys->previousKey || !self::signedBy($signature, $json, $keys->previousKey)) {
                return new self($payload, 'the licence\'s signature does not verify with the store\'s key: it is another store\'s, or it was changed.');
            }
            if (null === $keys->previousKeyValidUntil) {
                return new self($payload, 'the licence was signed with the store\'s previous key, which Forge Store keeps only when the hub announces until when (licensing.previousKeyValidUntil, omnihub key rotate --previous-until): sign it again with the current key.', true);
            }
            if ($now >= $keys->previousKeyValidUntil) {
                return new self($payload, 'the licence was signed with the store\'s previous key, which no longer opens licences since '.OfflineLicence::day($keys->previousKeyValidUntil).': ask the store for one signed with its current key.', true);
            }
            $byPrevious = true;
            $notes[] = \sprintf('signed with the hub\'s previous key: Forge Store opens it until %s at the latest, and no longer than 30 days after a user saw the rotation - sign it again with the current key.', OfflineLicence::day($keys->previousKeyValidUntil));
        }
        $text = fn (string $field): ?string => \is_string($payload[$field] ?? null) ? $payload[$field] : null;
        if (null !== $software && $text('software') !== $software) {
            return new self($payload, \sprintf('the licence is for %s, not %s (wrong_software).', $text('software') ?? 'no software', $software), $byPrevious, $notes);
        }
        if (self::number($payload, 'v') < 2) {
            return new self($payload, 'this licence is of format 1: it names no store and cannot be bound to this one. Activate it again online (the store then signs one bound to its registry), or ask the seller for a new one.', $byPrevious, $notes);
        }
        $named = $package ?? $text('package') ?? '';
        if (null === $registry) {
            $notes[] = 'registry: not compared (give the hub, or --registry).';
        } elseif (!OfflineLicence::sameRegistry($text('registry'), $registry)) {
            return new self($payload, \sprintf('the licence is another store\'s: it names %s, and %s comes from %s.', $text('registry') ?? 'no registry', $named, $registry), $byPrevious, $notes);
        }
        if (null === $package) {
            $notes[] = 'package: not compared (--package).';
        } elseif (($text('package') ?? '') !== $package) {
            return new self($payload, \sprintf('the licence is for %s, not %s.', '' === ($text('package') ?? '') ? 'a game or an application, not a package' : $text('package'), $package), $byPrevious, $notes);
        }
        $machine = $text('machine') ?? '';
        if ('' !== $machine) {
            if (null === $machineHash) {
                $notes[] = 'machine: bound to one machine, not compared (--machine or --machine-hash).';
            } elseif ($machine !== $machineHash) {
                return new self($payload, 'the licence is another machine\'s.', $byPrevious, $notes);
            }
        }
        $validUntil = self::number($payload, 'valid_until');
        if (0 !== $validUntil && $now >= $validUntil) {
            return new self($payload, 'the licence ran out on '.OfflineLicence::day($validUntil).': ask the store for a new one.', $byPrevious, $notes);
        }
        foreach (['issued_at', 'valid_until', 'updates_until'] as $time) {
            if (\array_key_exists($time, $payload) && null !== $payload[$time] && !\is_int($payload[$time])) {
                $notes[] = \sprintf('%s: not a JSON integer - the clients read it as 0%s.', $time, 'valid_until' === $time ? ', no end' : '');
            }
        }

        return new self($payload, null, $byPrevious, $notes);
    }

    /**
     * A token read, not checked: [payload, payload JSON, signature]; null when it is no token.
     *
     * @return array{0: array<array-key, mixed>, 1: string, 2: string}|null
     */
    public static function read(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }
        $json = self::base64url($parts[0]);
        $signature = self::base64url($parts[1]);
        if (null === $json || null === $signature || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature)) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!\is_array($payload) || ([] !== $payload && array_is_list($payload)) || !str_starts_with(ltrim($json), '{')) {
            return null;
        }

        return [$payload, $json, $signature];
    }

    /**
     * A time, or v, as the clients read it: a JSON number, else 0.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function number(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        return \is_int($value) || \is_float($value) ? (int) $value : 0;
    }

    private static function signedBy(string $signature, string $json, string $key): bool
    {
        $bytes = base64_decode($key, true);

        return '' !== $signature && \is_string($bytes) && '' !== $bytes && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($bytes) && sodium_crypto_sign_verify_detached($signature, $json, $bytes);
    }

    /** base64url, its padding optional (as .NET's reading after Forge Store pads it). */
    private static function base64url(string $text): ?string
    {
        $text = rtrim($text, '=');
        if (1 === \strlen($text) % 4 || !preg_match('/^[A-Za-z0-9_-]*$/', $text)) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return false === $bytes ? null : $bytes;
    }
}
