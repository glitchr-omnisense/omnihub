<?php

namespace Omnihub;

/** A source a hub manifest names: a UPM registry with its scopes (and a catalogue), or a Git package. */
final class Source
{
    public const UPM = 'upm';
    public const GIT = 'git';

    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $name = null,
        public readonly array $scopes = [],
        public readonly ?string $catalog = null,
    ) {
        if (!\in_array($type, [self::UPM, self::GIT], true)) {
            throw new \InvalidArgumentException(\sprintf('A source is "upm" or "git", not "%s".', $type));
        }
    }

    public static function fromArray(array $source): self
    {
        return new self((string) ($source['type'] ?? self::UPM), (string) ($source['url'] ?? ''), $source['name'] ?? null, array_values((array) ($source['scopes'] ?? [])), $source['catalog'] ?? null);
    }

    public function toArray(): array
    {
        return array_filter(['name' => $this->name, 'type' => $this->type, 'url' => rtrim($this->url, '/'), 'scopes' => $this->scopes ?: null, 'catalog' => $this->catalog], fn ($value) => null !== $value);
    }
}
