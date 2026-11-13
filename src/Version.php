<?php

namespace Omnihub;

/**
 * One version of a package: its manifest (package.json, as the tarball has it), where its
 * tarball is, and that tarball's sha1 and sha512 - npm's dist.shasum and dist.integrity.
 */
final class Version
{
    /**
     * @param array<string, mixed> $manifest its package.json
     * @param string               $sha1     hex
     * @param string|null          $sha512   hex
     */
    public function __construct(
        public readonly string $version,
        public readonly array $manifest,
        public readonly string $tarball,
        public readonly string $sha1,
        public readonly ?string $sha512 = null,
        public readonly ?\DateTimeImmutable $publishedAt = null,
        public readonly ?string $changelog = null,
    ) {
    }

    /** Subresource integrity, as npm writes it: sha512-<base64>. */
    public function integrity(): ?string
    {
        return $this->sha512 ? 'sha512-'.base64_encode((string) hex2bin($this->sha512)) : null;
    }

    /**
     * A version of a tarball's bytes: its hashes taken here.
     *
     * @param array<string, mixed> $manifest its package.json
     */
    public static function ofTarball(string $version, array $manifest, string $tarballUrl, string $bytes, ?\DateTimeImmutable $publishedAt = null, ?string $changelog = null): self
    {
        return new self($version, $manifest, $tarballUrl, sha1($bytes), hash('sha512', $bytes), $publishedAt, $changelog);
    }
}
