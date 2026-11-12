<?php

namespace Omnihub;

/**
 * A source a hub manifest names: a UPM registry with its scopes (and a catalogue), a Git package,
 * or a GitHub owner whose repositories' names match a pattern - written as the hub clients read
 * them (Forge Store's docs/sources.md).
 */
final class Source
{
    public const UPM = 'upm';
    public const GIT = 'git';
    public const GITHUB = 'github';

    public const TYPES = [self::UPM, self::GIT, self::GITHUB];

    /** A GitHub login: an organisation's or a user's. */
    private const OWNER = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/';

    /** Globs of repository names: letters, digits, ".", "_", "-", "*", "?", separated by commas or spaces. */
    private const PATTERN = '/^[A-Za-z0-9._*?-]+(?:[ ,;]+[A-Za-z0-9._*?-]+)*$/';

    private const KEYS = ['type', 'name', 'url', 'scopes', 'catalog', 'owner', 'pattern', 'install', 'package'];

    /**
     * @param list<string> $scopes  a registry's scopes (upm)
     * @param string|null  $owner   a GitHub organisation or user (github)
     * @param string|null  $pattern the names of its repositories taken: "unity-*", "unity-*, tool-?" (github)
     * @param bool         $install a package every project of the team gets (git, github)
     * @param string|null  $package the package a Git URL installs, "com.mystudio.tool" (git)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $url = '',
        public readonly ?string $name = null,
        public readonly array $scopes = [],
        public readonly ?string $catalog = null,
        public readonly ?string $owner = null,
        public readonly ?string $pattern = null,
        public readonly bool $install = false,
        public readonly ?string $package = null,
    ) {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('type: a source is "upm", "git" or "github", not "%s".', $type));
        }
        if (self::GITHUB === $type) {
            if (null === $owner || !preg_match(self::OWNER, $owner)) {
                throw new \InvalidArgumentException(\sprintf('owner: a "github" source names an organisation or a user (letters, digits and "-", 39 at most), not "%s".', $owner ?? ''));
            }
            if (null === $pattern || !preg_match(self::PATTERN, trim($pattern))) {
                throw new \InvalidArgumentException(\sprintf('pattern: a "github" source names its repositories with globs ("unity-*"; "*" any run, "?" one character, several separated by commas), not "%s".', $pattern ?? ''));
            }

            return;
        }
        if (!preg_match('~^https?://[^\s/]+~', $url)) {
            throw new \InvalidArgumentException(\sprintf('url: a "%s" source has an https:// address, not "%s".', $type, $url));
        }
        if ($install && self::UPM === $type) {
            throw new \InvalidArgumentException('install: only a "git" or a "github" source puts its packages in every project.');
        }
    }

    public static function github(string $owner, string $pattern, ?string $name = null, bool $install = false): self
    {
        return new self(self::GITHUB, name: $name, owner: $owner, pattern: $pattern, install: $install);
    }

    /**
     * A source from a configuration's entry - hub.yaml's "sources", or a hub.json's. Every error
     * names the key it is about.
     */
    public static function fromArray(array $source): self
    {
        $type = $source['type'] ?? self::UPM;
        if (!\is_string($type)) {
            throw new \InvalidArgumentException('type: a source is "upm", "git" or "github".');
        }
        if ($unknown = array_diff(array_keys($source), self::KEYS)) {
            throw new \InvalidArgumentException(\sprintf('%s: not a key of a source (%s).', implode(', ', $unknown), implode(', ', self::KEYS)));
        }
        foreach (['name', 'url', 'catalog', 'owner', 'pattern', 'package'] as $key) {
            if (isset($source[$key]) && !\is_string($source[$key])) {
                throw new \InvalidArgumentException(\sprintf('%s: a text, not %s.', $key, get_debug_type($source[$key])));
            }
        }
        if (isset($source['install']) && !\is_bool($source['install'])) {
            throw new \InvalidArgumentException('install: true or false.');
        }
        $scopes = $source['scopes'] ?? [];
        if (!\is_array($scopes) || array_filter($scopes, fn ($scope) => !\is_string($scope) || '' === $scope)) {
            throw new \InvalidArgumentException('scopes: a list of scopes, ["com.mystudio"].');
        }

        return new self($type, $source['url'] ?? '', $source['name'] ?? null, array_values($scopes), $source['catalog'] ?? null, $source['owner'] ?? null, $source['pattern'] ?? null, $source['install'] ?? false, $source['package'] ?? null);
    }

    /**
     * Whether a repository's name matches a "github" source's pattern, as Forge Store matches it:
     * globs, several separated by commas, spaces or semicolons, case ignored.
     */
    public function matches(string $repository): bool
    {
        if ('' === $repository) {
            return false;
        }
        $globs = preg_split('/[ ,;]+/', trim((string) $this->pattern), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$globs) {
            return true;
        }
        foreach ($globs as $glob) {
            if (preg_match('/^'.strtr(preg_quote($glob, '/'), ['\*' => '.*', '\?' => '.']).'$/i', $repository)) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        if (self::GITHUB === $this->type) {
            return array_filter(['name' => $this->name, 'type' => $this->type, 'owner' => $this->owner, 'pattern' => trim((string) $this->pattern), 'install' => $this->install ?: null], fn ($value) => null !== $value);
        }

        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'url' => self::GIT === $this->type ? $this->url : rtrim($this->url, '/'),
            'scopes' => $this->scopes ?: null,
            'catalog' => $this->catalog,
            'package' => $this->package,
            'install' => $this->install ?: null,
        ], fn ($value) => null !== $value);
    }
}
