<?php

namespace Omnihub;

/**
 * What a hub declares for its licences, data only - the manifest's "licensing", as Forge Store
 * reads and pins it (io.glitchr.store, KeyPins): the Ed25519 public key that signs the hub's
 * licences, and, after the hub changed its key, the previous key with its signature of the new
 * one.
 *
 *   {"algorithm": "Ed25519", "publicKey": "<32 bytes, base64>", "registry": "https://owner.github.io/hub"}
 *   {"algorithm": "Ed25519", "publicKey": "<new>", "registry": "...", "previousKey": "<old>",
 *    "rotation": "<64 bytes, base64>", "previousKeyValidUntil": 1795564800}
 *
 * algorithm is always Ed25519, written first as a forge writes it; hub.yaml may leave it out.
 *
 * The rotation is the previous key's Ed25519 signature of the UTF-8 text
 * "forge-key-rotation:v1:" followed by the new key in base64. A key is standard base64 with its
 * padding (what .NET's Convert.FromBase64String reads); it is written as such.
 *
 * registry: the address its licences name (an offline licence's "registry", a Forge's
 * "https://<site>/upm") - for a static hub, its own registry, the manifest's sources[0].url;
 * StaticHub writes it. previousKeyValidUntil (UNIX seconds, after a rotation only): until when
 * licences the previous key signed may still open - Forge Store keeps the previous key until
 * min(that, the rotation seen + 30 days), and never without it.
 *
 * Only public keys are read here: a private key never belongs in a hub's configuration.
 */
final class Licensing
{
    public const ROTATION_PREFIX = 'forge-key-rotation:v1:';
    public const ALGORITHM = 'Ed25519';
    public const KEYS = ['algorithm', 'publicKey', 'registry', 'previousKey', 'rotation', 'previousKeyValidUntil'];

    private const KEY_BYTES = 32;
    private const SECRET_BYTES = 64;
    private const SIGNATURE_BYTES = 64;

    /** Names that would hold a private key: refused with a message that does not repeat their value. */
    private const SECRET_NAMES = '/private|secret|seed/i';

    /** @throws \InvalidArgumentException the first problem; problems() gives them all */
    public function __construct(
        public readonly string $publicKey,
        public readonly ?string $previousKey = null,
        public readonly ?string $rotation = null,
        public readonly ?string $registry = null,
        public readonly ?int $previousKeyValidUntil = null,
    ) {
        if ($problems = self::problems(['publicKey' => $publicKey, 'registry' => $registry, 'previousKey' => $previousKey, 'rotation' => $rotation, 'previousKeyValidUntil' => $previousKeyValidUntil])) {
            throw new \InvalidArgumentException($problems[0]);
        }
    }

    /** hub.yaml's "licensing", checked as a whole. @throws \InvalidArgumentException every problem, one per line */
    public static function fromArray(mixed $section): self
    {
        if ($problems = self::problems($section)) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        /** @var array{publicKey: string, registry?: ?string, previousKey?: ?string, rotation?: ?string, previousKeyValidUntil?: int|string|null} $section */
        return new self(
            self::canonical($section['publicKey']),
            isset($section['previousKey']) ? self::canonical($section['previousKey']) : null,
            isset($section['rotation']) ? self::canonical($section['rotation']) : null,
            isset($section['registry']) ? OfflineLicence::normalizeRegistry($section['registry']) : null,
            isset($section['previousKeyValidUntil']) ? self::until($section['previousKeyValidUntil']) : null,
        );
    }

    /** The same section, naming $registry as the address its licences name. */
    public function withRegistry(?string $registry): self
    {
        return new self($this->publicKey, $this->previousKey, $this->rotation, null === $registry ? null : OfflineLicence::normalizeRegistry($registry), $this->previousKeyValidUntil);
    }

    /**
     * Every problem of a "licensing" section, each naming its key ("licensing.publicKey: ...").
     * A value that may be a secret is never repeated in a message.
     *
     * @return list<string>
     */
    public static function problems(mixed $section): array
    {
        if (!\is_array($section) || ([] !== $section && array_is_list($section))) {
            return ['licensing: a mapping of publicKey (and, after a change of key, previousKey, rotation and previousKeyValidUntil).'];
        }
        $problems = [];
        foreach (array_diff(array_keys($section), self::KEYS) as $key) {
            $problems[] = preg_match(self::SECRET_NAMES, (string) $key)
                ? \sprintf('licensing.%s: a private key never belongs in the hub\'s configuration - remove it, keep it out of the repository (and make a new one if it was ever committed: omnihub key generate).', $key)
                : \sprintf('licensing.%s: not a key of licensing (%s).', $key, implode(', ', self::KEYS));
        }

        $bytes = [];
        foreach (['publicKey' => self::KEY_BYTES, 'previousKey' => self::KEY_BYTES, 'rotation' => self::SIGNATURE_BYTES] as $key => $length) {
            $value = $section[$key] ?? null;
            if (null === $value) {
                if ('publicKey' === $key) {
                    $problems[] = 'licensing.publicKey: the Ed25519 public key that signs the hub\'s licences, 32 bytes in base64 (omnihub key generate prints it).';
                }
                continue;
            }
            if (!\is_string($value)) {
                $problems[] = \sprintf('licensing.%s: a text in base64, not %s.', $key, get_debug_type($value));
                continue;
            }
            $decoded = base64_decode(trim($value), true);
            if (false === $decoded || '' === trim($value)) {
                $problems[] = \sprintf('licensing.%s: not base64 (standard alphabet, with its "=" padding).', $key);
            } elseif ('rotation' !== $key && self::SECRET_BYTES === \strlen($decoded)) {
                $problems[] = \sprintf('licensing.%s: 64 bytes is a private key (sodium\'s secret key), not a public one - remove it from the configuration and from the repository\'s history, and make a new pair (omnihub key generate).', $key);
            } elseif ($length !== \strlen($decoded)) {
                $problems[] = \sprintf('licensing.%s: %s is %d bytes, not %d.', $key, 'rotation' === $key ? 'an Ed25519 signature' : 'an Ed25519 public key', \strlen($decoded), $length);
            } elseif (base64_encode($decoded) !== trim($value)) {
                $problems[] = \sprintf('licensing.%s: not standard base64 (its "=" padding, "+" and "/", no line break): Forge Store would not read it.', $key);
            } else {
                $bytes[$key] = $decoded;
            }
        }

        $algorithm = $section['algorithm'] ?? null;
        if (null !== $algorithm && self::ALGORITHM !== $algorithm) {
            $problems[] = \sprintf('licensing.algorithm: %s, the only one the hub clients check (or leave it out).', self::ALGORITHM);
        }
        $registry = $section['registry'] ?? null;
        if (null !== $registry) {
            if (!\is_string($registry)) {
                $problems[] = \sprintf('licensing.registry: a text, not %s.', get_debug_type($registry));
            } elseif (null !== ($problem = OfflineLicence::registryProblem(OfflineLicence::normalizeRegistry($registry)))) {
                $problems[] = 'licensing.registry: '.$problem;
            }
        }
        $until = $section['previousKeyValidUntil'] ?? null;
        if (null !== $until) {
            if (null === self::until($until)) {
                $problems[] = 'licensing.previousKeyValidUntil: UNIX seconds, or a day (YYYY-MM-DD, UTC): until when licences the previous key signed may open (omnihub key rotate --previous-until).';
            } elseif (!isset($section['previousKey'])) {
                $problems[] = 'licensing.previousKeyValidUntil: goes with a previousKey and its rotation (omnihub key rotate).';
            }
        }

        $previous = isset($section['previousKey']);
        $rotation = isset($section['rotation']);
        if ($previous !== $rotation) {
            $problems[] = $previous
                ? 'licensing.rotation: a previousKey goes with its signature of the new key (omnihub key rotate).'
                : 'licensing.previousKey: a rotation goes with the key that signed it (omnihub key rotate).';
        } elseif (isset($bytes['publicKey'], $bytes['previousKey'], $bytes['rotation'])) {
            if ($bytes['publicKey'] === $bytes['previousKey']) {
                $problems[] = 'licensing.previousKey: the same key as publicKey - a rotation hands over to another key; without one, leave previousKey and rotation out.';
            } elseif (!\function_exists('sodium_crypto_sign_verify_detached')) {
                $problems[] = 'licensing.rotation: checking it needs PHP\'s sodium extension.';
            } elseif (!sodium_crypto_sign_verify_detached($bytes['rotation'], self::rotationMessage(base64_encode($bytes['publicKey'])), $bytes['previousKey'])) {
                $problems[] = 'licensing.rotation: does not verify with previousKey - it is not previousKey\'s signature of "'.self::ROTATION_PREFIX.'" + publicKey (omnihub key rotate <previous private key> <new public key> signs it).';
            }
        }

        return $problems;
    }

    /** The text a previous key signs to hand over to $newKey (base64, as written in publicKey). */
    public static function rotationMessage(string $newKey): string
    {
        return self::ROTATION_PREFIX.$newKey;
    }

    /** A short mark of the public key, to compare by eye - Forge Store's: the first 8 bytes of its SHA-256, in hex pairs. */
    public function fingerprint(): string
    {
        return implode(':', str_split(substr(hash('sha256', (string) base64_decode($this->publicKey, true)), 0, 16), 2));
    }

    /** @return array<string, string|int> as the manifest writes it, in Forge's order */
    public function toArray(): array
    {
        return array_filter([
            'algorithm' => self::ALGORITHM,
            'publicKey' => $this->publicKey,
            'registry' => $this->registry,
            'previousKey' => $this->previousKey,
            'rotation' => $this->rotation,
            'previousKeyValidUntil' => $this->previousKeyValidUntil,
        ], fn ($value) => null !== $value);
    }

    /** previousKeyValidUntil as written: UNIX seconds (YAML reads an unquoted day as such), or a day as text; null when it is neither. */
    private static function until(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!\is_string($value)) {
            return null;
        }
        try {
            return OfflineLicence::time($value, 'licensing.previousKeyValidUntil');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function canonical(string $value): string
    {
        return base64_encode((string) base64_decode(trim($value), true));
    }
}
