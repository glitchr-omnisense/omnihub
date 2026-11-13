<?php

namespace Omnihub;

/**
 * A hub author's Ed25519 private key, on the author's machine only (bin/omnihub key ...): it signs
 * the hub's licences offline, and, when the hub changes its key, the rotation to the new one. The
 * file holds sodium's 64-byte secret key in base64 - the form omnibase/forge keeps in
 * FORGE_LICENSE_SIGNING_KEY, so a static hub's key can move to a forge.
 *
 * Nothing here prints, exports or serializes the private key; HubConfig refuses to publish a file
 * that holds one.
 */
final class SigningKey
{
    /** @var non-empty-string sodium's 64-byte secret key */
    private string $secret;

    /** @param non-empty-string $secret */
    private function __construct(#[\SensitiveParameter] string $secret)
    {
        self::sodium();
        $this->secret = $secret;
    }

    public static function generate(): self
    {
        self::sodium();

        return new self(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    }

    /** @throws \RuntimeException a file that cannot be read or holds no key (its content never shown) */
    public static function fromFile(string $path): self
    {
        self::sodium();
        $text = @file_get_contents($path);
        if (false === $text) {
            throw new \RuntimeException(\sprintf('%s: cannot be read.', $path));
        }
        // Sodium's secret key only: its second half proves it whole. A bare 32-byte seed is not
        // read - it could not be told from a public key given by mistake.
        $bytes = base64_decode(trim($text), true);
        if (!\is_string($bytes) || '' === $bytes || !self::isSecretKey($bytes)) {
            throw new \RuntimeException(\sprintf('%s: not an Ed25519 private key (sodium\'s 64-byte secret key in base64, as omnihub key generate writes it).', $path));
        }

        return new self($bytes);
    }

    /**
     * Whether those bytes hold a private key as this class writes it (sodium's secret key, raw or
     * in base64): its last 32 bytes are the public key its first 32 make. A public key or any other
     * file is never taken for one.
     */
    public static function looksLikePrivateKey(string $bytes): bool
    {
        if (\strlen($bytes) > 256 || !\function_exists('sodium_crypto_sign_seed_keypair')) {
            return false;
        }
        $decoded = base64_decode(trim($bytes), true);

        return self::isSecretKey($bytes) || (\is_string($decoded) && self::isSecretKey($decoded));
    }

    /** The public key, base64: hub.yaml's licensing.publicKey. */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secret));
    }

    /**
     * The licensing section that hands the hub over from this (previous) key to $newKey: the new
     * key, this one, and this one's signature of "forge-key-rotation:v1:" + the new key.
     */
    public function rotateTo(string $newKey): Licensing
    {
        $new = trim($newKey);
        $bytes = base64_decode($new, true);
        if (false === $bytes || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($bytes)) {
            throw new \InvalidArgumentException('The new key: an Ed25519 public key, 32 bytes in base64.');
        }
        $new = base64_encode($bytes);

        return new Licensing($new, $this->publicKey(), base64_encode(sodium_crypto_sign_detached(Licensing::rotationMessage($new), $this->secret)));
    }

    /**
     * A licence token signed offline, in Forge Seal's form (io.glitchr.licensing, LicenseToken):
     * base64url(payload JSON) "." base64url(Ed25519 signature of the payload JSON), unpadded.
     */
    public function signToken(array $payload): string
    {
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return self::base64url($json).'.'.self::base64url(sodium_crypto_sign_detached($json, $this->secret));
    }

    /** A token's payload when its signature verifies with $publicKey (base64); else null. */
    public static function verifyToken(string $token, string $publicKey): ?array
    {
        self::sodium();
        $parts = explode('.', trim($token));
        $key = base64_decode(trim($publicKey), true);
        if (2 !== \count($parts) || false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($key)) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $signature = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (false === $json || false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature) || !sodium_crypto_sign_verify_detached($signature, $json, $key)) {
            return null;
        }
        $payload = json_decode($json, true);

        return \is_array($payload) ? $payload : null;
    }

    /**
     * Writes the private key to $path, readable by its owner only (0600). An existing file is never
     * replaced.
     *
     * @throws \RuntimeException
     */
    public function writeTo(string $path): void
    {
        if (file_exists($path)) {
            throw new \RuntimeException(\sprintf('%s: already exists - a private key is never written over (move it away first, or give another path).', $path));
        }
        if (!is_dir(\dirname($path))) {
            throw new \RuntimeException(\sprintf('%s: no such folder.', \dirname($path)));
        }
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
            if (false === $handle) {
                throw new \RuntimeException(\sprintf('%s: cannot be written.', $path));
            }
            fwrite($handle, base64_encode($this->secret)."\n");
            fclose($handle);
            @chmod($path, 0600);
        } finally {
            umask($mask);
        }
    }

    /** The root of the git work tree that holds $path (a file or a folder, existing or not), or null. */
    public static function gitWorkTree(string $path): ?string
    {
        $directory = is_dir($path) ? $path : \dirname($path);
        $directory = realpath($directory) ?: $directory;
        while (true) {
            if (file_exists($directory.'/.git')) {
                return $directory;
            }
            $parent = \dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    public function __serialize(): array
    {
        throw new \LogicException('A private key is never serialized.');
    }

    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey()];
    }

    private static function isSecretKey(string $bytes): bool
    {
        return SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === \strlen($bytes)
            && hash_equals(substr($bytes, 32), sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(substr($bytes, 0, 32))));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function sodium(): void
    {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('Signing keys need PHP\'s sodium extension (bundled with PHP since 7.2; enable ext-sodium).');
        }
    }
}
