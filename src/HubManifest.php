<?php

namespace Omnihub;

/**
 * The hub manifest the hub clients read first - Forge Store, the Unity window (io.glitchr.store),
 * Forge Hub, the desktop app: the hub's name, logo and colour, its theme, its sources, where its
 * games are, the public key its licences are signed with (licensing), and, only for a hub that
 * sells through a server, its checkout (a device flow). A static hub never declares a checkout; it
 * may declare a licensing key, its author signing the licences offline.
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
        public readonly ?Theme $theme = null,
        public readonly ?Licensing $licensing = null,
    ) {
        if (null !== $color && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new \InvalidArgumentException(\sprintf('color: a colour is #rrggbb, not "%s".', $color));
        }
        foreach (['logo' => $theme?->logo, 'banner' => $theme?->banner] as $key => $picture) {
            if (null !== $picture && !preg_match('~^https?://~i', $picture)) {
                throw new \InvalidArgumentException(\sprintf('theme.%s: a manifest gives an address (https://, which Forge Store requires), not "%s".', $key, $picture));
            }
        }
    }

    public function toArray(): array
    {
        $theme = $this->theme && !$this->theme->isEmpty() ? $this->theme : null;

        // The older "color" and "logo" keys too, for the clients that read no theme.
        return array_filter([
            'version' => self::VERSION,
            'name' => $this->name,
            'logo' => $this->logo ?? $theme?->logo,
            'color' => $this->color ?? $theme?->color(),
            'theme' => $theme?->toArray(),
            'licensing' => $this->licensing?->toArray(),
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
