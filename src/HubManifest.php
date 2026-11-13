<?php

namespace Omnihub;

/**
 * The hub manifest the hub clients read first - Forge Store, the Unity window (io.glitchr.store),
 * Forge Hub, the desktop app: the hub's name, logo and colour, its theme, its sources, where its
 * games are, the public key its licences are signed with and the registry they name (licensing),
 * the packages a static hub sells with a licence its author signs (licensed), and, only for a hub that
 * sells through a server, its checkout (a device flow, and a one-click purchase: quote, purchases,
 * methods) and its licence API (licenses: list, activate, release). A static hub never declares a
 * checkout; it may declare a licensing key, its author signing the licences offline.
 *
 * The keys are written in the order a forge (omnibase/forge) serves them: version, name, logo,
 * color, theme, sources, games, checkout, licenses, licensing, licensed.
 */
final class HubManifest
{
    public const VERSION = 1;

    /**
     * @param list<Source>                                    $sources
     * @param array<string, string>|null                       $checkout device and token (the device flow), then a forge's other addresses
     *                                                                   in its order - quote, purchases, methods (a one-click purchase)
     * @param list<array{package: string, get?: string}>        $licensed packages sold with a licence signed offline (a static hub), and where to get one
     * @param array<string, string>|null                       $licenses a forge's licence API: list, activate, release
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
        public readonly array $licensed = [],
        public readonly ?array $licenses = null,
    ) {
        self::addresses('checkout', $checkout, ['device', 'token']);
        self::addresses('licenses', $licenses, ['list', 'activate', 'release']);
        if (null !== $color && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new \InvalidArgumentException(\sprintf('color: a colour is #rrggbb, not "%s".', $color));
        }
        foreach (['logo' => $theme?->logo, 'banner' => $theme?->banner] as $key => $picture) {
            if (null !== $picture && !preg_match('~^https?://~i', $picture)) {
                throw new \InvalidArgumentException(\sprintf('theme.%s: a manifest gives an address (https://, which Forge Store requires), not "%s".', $key, $picture));
            }
        }
    }

    /** @return array<string, mixed> */
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
            'sources' => array_map(fn (Source $source) => $source->toArray(), $this->sources),
            'games' => $this->games,
            'checkout' => $this->checkout,
            'licenses' => $this->licenses,
            'licensing' => $this->licensing?->toArray(),
            'licensed' => $this->licensed ?: null,
        ], fn ($value) => null !== $value);
    }

    /**
     * The scopes a hub's packages fall under when it names none: the first two segments of each
     * name (com.mystudio.tool: com.mystudio), each once, in the packages' order.
     *
     * @param iterable<string> $packageNames
     *
     * @return list<string>
     */
    public static function scopesOf(iterable $packageNames): array
    {
        $scopes = [];
        foreach ($packageNames as $name) {
            $scopes[] = implode('.', \array_slice(explode('.', $name), 0, 2));
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @param array<mixed>|null $addresses
     * @param list<string>      $required
     */
    private static function addresses(string $key, ?array $addresses, array $required): void
    {
        if (null === $addresses) {
            return;
        }
        foreach ($required as $name) {
            if (!isset($addresses[$name])) {
                throw new \InvalidArgumentException(\sprintf('%s.%s: missing (%s needs %s).', $key, $name, $key, implode(', ', $required)));
            }
        }
        foreach ($addresses as $name => $address) {
            if (!\is_string($name) || !\is_string($address) || !preg_match('~^https?://~i', $address)) {
                throw new \InvalidArgumentException(\sprintf('%s.%s: an address (https://), not %s.', $key, $name, \is_string($address) ? '"'.$address.'"' : get_debug_type($address)));
            }
        }
    }

    public function json(): string
    {
        return json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }
}
