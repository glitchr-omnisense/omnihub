<?php

namespace Omnihub;

/**
 * The hub manifest the hub clients read first - the Unity window (com.glitchr.hub), the
 * desktop hub: the hub's name, logo and colour, its sources, where its games are, and, only for
 * a hub that sells, its checkout (a device flow). A static hub never declares one.
 */
final class HubManifest
{
    public const VERSION = 1;

    /**
     * @param list<Source>                             $sources
     * @param array{device: string, token: string}|null $checkout
     */
    public function __construct(
        public readonly string $name,
        public readonly array $sources,
        public readonly ?string $logo = null,
        public readonly ?string $color = null,
        public readonly ?string $games = null,
        public readonly ?array $checkout = null,
    ) {
        if (null !== $color && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new \InvalidArgumentException(\sprintf('A colour is #rrggbb, not "%s".', $color));
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'version' => self::VERSION,
            'name' => $this->name,
            'logo' => $this->logo,
            'color' => $this->color,
            'sources' => array_map(fn (Source $source) => $source->toArray(), $this->sources),
            'games' => $this->games,
            'checkout' => $this->checkout,
        ], fn ($value) => null !== $value);
    }

    public function json(): string
    {
        return json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }
}
