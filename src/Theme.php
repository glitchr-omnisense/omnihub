<?php

namespace Omnihub;

/**
 * A hub's look, data only: its accent colours for Unity's light and dark skins, its logo and its
 * banner - the manifest's "theme", as Forge Store reads it ({"accent", "accentDark", "logo",
 * "banner"}). The colours are #rgb or #rrggbb; the pictures are https:// addresses (a static hub
 * makes its own files' addresses absolute, StaticHub::manifest()).
 */
final class Theme
{
    private const COLOUR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
    private const KEYS = ['accent', 'accentDark', 'logo', 'banner'];

    public function __construct(
        public readonly ?string $accent = null,
        public readonly ?string $accentDark = null,
        public readonly ?string $logo = null,
        public readonly ?string $banner = null,
    ) {
        foreach (['accent' => $accent, 'accentDark' => $accentDark] as $key => $colour) {
            if (null !== $colour && !preg_match(self::COLOUR, $colour)) {
                throw new \InvalidArgumentException(\sprintf('theme.%s: a colour is #rgb or #rrggbb, not "%s".', $key, $colour));
            }
        }
        foreach (['logo' => $logo, 'banner' => $banner] as $key => $picture) {
            if (null !== $picture && ('' === trim($picture) || preg_match('~^[a-z][a-z0-9+.-]*:~i', $picture) && !preg_match('~^https?://[^\s/]+~i', $picture))) {
                throw new \InvalidArgumentException(\sprintf('theme.%s: an https:// address or a file of the hub, not "%s".', $key, $picture));
            }
        }
    }

    /** A theme from hub.yaml's "theme": its keys are the manifest's. */
    public static function fromArray(array $theme): self
    {
        if ($unknown = array_diff(array_keys($theme), self::KEYS)) {
            throw new \InvalidArgumentException(\sprintf('theme.%s: not a key of a theme (%s).', implode(', theme.', $unknown), implode(', ', self::KEYS)));
        }
        foreach ($theme as $key => $value) {
            if (null !== $value && !\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('theme.%s: a text, not %s (quote a colour in YAML: "#336699").', $key, get_debug_type($value)));
            }
        }

        return new self($theme['accent'] ?? null, $theme['accentDark'] ?? null, $theme['logo'] ?? null, $theme['banner'] ?? null);
    }

    /** The same theme, its pictures given by $address (a relative file made absolute). */
    public function withPictures(callable $address): self
    {
        return new self($this->accent, $this->accentDark, null === $this->logo ? null : $address($this->logo), null === $this->banner ? null : $address($this->banner));
    }

    /** The accent as #rrggbb: what the manifest's older "color" key takes. */
    public function color(): ?string
    {
        if (null === $this->accent) {
            return null;
        }

        return 4 === \strlen($this->accent) ? '#'.$this->accent[1].$this->accent[1].$this->accent[2].$this->accent[2].$this->accent[3].$this->accent[3] : $this->accent;
    }

    public function isEmpty(): bool
    {
        return null === $this->accent && null === $this->accentDark && null === $this->logo && null === $this->banner;
    }

    public function toArray(): array
    {
        return array_filter(['accent' => $this->accent, 'accentDark' => $this->accentDark, 'logo' => $this->logo, 'banner' => $this->banner], fn ($value) => null !== $value);
    }
}
