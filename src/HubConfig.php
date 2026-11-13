<?php

namespace Omnihub;

/**
 * A static hub's configuration - hub.yaml, or the same as JSON - read and checked: every error
 * names the key it is about ("sources[2].owner: ...") and they come all at once.
 *
 *   name, url, scopes, logo, color   the hub
 *   theme                            {accent, accentDark, logo, banner}: its look in the hub clients
 *   licensing                        {publicKey, previousKey, rotation}: the public key its licences are signed with
 *   packages                         its own packages: {github: owner/name}, {file: path}, {tarball: address}
 *   sources                          what else its manifest lists: upm, git, github (owner + pattern)
 *   copy                             files of the hub's repository published with it
 */
final class HubConfig
{
    public const KEYS = ['name', 'url', 'scopes', 'logo', 'color', 'theme', 'licensing', 'packages', 'sources', 'copy'];

    /** The biggest picture Forge Store draws: 1 MB. */
    public const PICTURE_BYTES = 1048576;

    private const PACKAGE_KINDS = ['github', 'file', 'tarball'];
    private const PACKAGE_KEYS = ['github', 'file', 'tarball', 'asset', 'changelog', 'date'];

    /**
     * @param list<string>               $scopes
     * @param list<Source>               $sources
     * @param list<array<string, mixed>> $packages
     * @param list<string>               $copy     files beside the configuration to publish: the copies asked, the kept tarballs, the pictures
     */
    private function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly array $scopes,
        public readonly ?string $logo,
        public readonly ?string $color,
        public readonly ?Theme $theme,
        public readonly array $sources,
        public readonly array $packages,
        public readonly array $copy,
        public readonly ?Licensing $licensing = null,
    ) {
    }

    /** hub.yaml (with symfony/yaml) or hub.json; its files - pictures, kept tarballs - checked beside it. */
    public static function fromFile(string $path, ?string $url = null): self
    {
        $text = @file_get_contents($path);
        if (false === $text) {
            throw new InvalidHubConfig($path, ['cannot be read']);
        }
        if (preg_match('/\.ya?ml$/i', $path)) {
            if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                throw new InvalidHubConfig($path, ['hub.yaml needs symfony/yaml (composer require symfony/yaml), or write hub.json']);
            }
            try {
                $settings = \Symfony\Component\Yaml\Yaml::parse($text);
            } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
                throw new InvalidHubConfig($path, [$e->getMessage()]);
            }
        } else {
            try {
                $settings = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidHubConfig($path, ['not JSON: '.$e->getMessage()]);
            }
        }
        if (!\is_array($settings)) {
            throw new InvalidHubConfig($path, ['a mapping of keys ('.implode(', ', self::KEYS).')']);
        }

        return self::fromArray($settings, $url, \dirname($path), $path);
    }

    /**
     * @param array<mixed> $settings  hub.yaml or hub.json, decoded
     * @param string|null  $url       the hub's address, when the configuration does not give it (--url)
     * @param string|null  $directory where its files are: given, they must be there
     */
    public static function fromArray(array $settings, ?string $url = null, ?string $directory = null, string $label = 'hub.yaml'): self
    {
        $errors = [];
        $text = function (string $key, mixed $value) use (&$errors): ?string {
            if (null === $value || \is_string($value)) {
                return $value;
            }
            $errors[] = \sprintf('%s: a text, not %s.', $key, get_debug_type($value));

            return null;
        };
        $list = function (string $key, mixed $value) use (&$errors): array {
            if (null === $value) {
                return [];
            }
            if (!\is_array($value) || !array_is_list($value)) {
                $errors[] = \sprintf('%s: a list.', $key);

                return [];
            }

            return $value;
        };

        if ($unknown = array_diff(array_keys($settings), self::KEYS)) {
            $errors[] = \sprintf('%s: not a key of a hub (%s).', implode(', ', $unknown), implode(', ', self::KEYS));
        }
        $name = $text('name', $settings['name'] ?? null) ?? 'Hub';
        $address = $url ?? $text('url', $settings['url'] ?? null);
        if (null === $address || !preg_match('~^https?://[^\s/]+~', $address)) {
            $errors[] = \sprintf('url: the hub\'s address, https://<owner>.github.io/<repository> - in the configuration or --url%s.', null === $address ? '' : ', not "'.$address.'"');
            $address = 'https://invalid';
        }

        $scopes = [];
        foreach ($list('scopes', $settings['scopes'] ?? null) as $i => $scope) {
            if (!\is_string($scope) || !preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $scope)) {
                $errors[] = \sprintf('scopes[%d]: a scope is reverse-domain, "com.mystudio", not %s.', $i, json_encode($scope));
            } else {
                $scopes[] = $scope;
            }
        }

        $logo = $text('logo', $settings['logo'] ?? null);
        $color = $text('color', $settings['color'] ?? null);
        if (null !== $color && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = \sprintf('color: a colour is #rrggbb (quoted in YAML: "#336699"), not "%s".', $color);
            $color = null;
        }

        $theme = null;
        if (isset($settings['theme'])) {
            if (!\is_array($settings['theme']) || array_is_list($settings['theme'])) {
                $errors[] = 'theme: a mapping of accent, accentDark, logo, banner.';
            } else {
                try {
                    $theme = Theme::fromArray($settings['theme']);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        $licensing = null;
        if (\array_key_exists('licensing', $settings) && null !== $settings['licensing']) {
            if ($problems = Licensing::problems($settings['licensing'])) {
                array_push($errors, ...$problems);
            } else {
                $licensing = Licensing::fromArray($settings['licensing']);
            }
        }

        $sources = [];
        foreach ($list('sources', $settings['sources'] ?? null) as $i => $entry) {
            if (!\is_array($entry)) {
                $errors[] = \sprintf('sources[%d]: a mapping (type, name, url or owner + pattern...).', $i);
                continue;
            }
            try {
                $sources[] = Source::fromArray($entry);
            } catch (\InvalidArgumentException $e) {
                $errors[] = \sprintf('sources[%d].%s', $i, $e->getMessage());
            }
        }

        $packages = [];
        $copy = [];
        foreach ($list('packages', $settings['packages'] ?? null) as $i => $entry) {
            $kinds = \is_array($entry) ? array_values(array_intersect(array_keys($entry), self::PACKAGE_KINDS)) : [];
            if (1 !== \count($kinds)) {
                $errors[] = \sprintf('packages[%d]: one of github: owner/name, file: <path>, tarball: <address>.', $i);
                continue;
            }
            if ($unknown = array_diff(array_keys($entry), self::PACKAGE_KEYS)) {
                $errors[] = \sprintf('packages[%d].%s: not a key of a package (%s).', $i, implode(', ', $unknown), implode(', ', self::PACKAGE_KEYS));
                continue;
            }
            $value = $entry[$kinds[0]];
            $problem = match (true) {
                !\is_string($value) => 'a text',
                'github' === $kinds[0] && !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $value) => 'owner/name',
                'tarball' === $kinds[0] && !preg_match('~^https?://~', $value) => 'an https:// address',
                'file' === $kinds[0] && (str_contains($value, '..') || '' === trim($value, '/')) => 'a path in the hub\'s repository',
                default => null,
            };
            if (null !== $problem) {
                $errors[] = \sprintf('packages[%d].%s: %s, not %s.', $i, $kinds[0], $problem, json_encode($value));
                continue;
            }
            if (isset($entry['asset']) && (!\is_string($entry['asset']) || false === @preg_match($entry['asset'], ''))) {
                $errors[] = \sprintf('packages[%d].asset: a regular expression on the assets\' names, \'/\.tgz$/\'.', $i);
                continue;
            }
            if ('file' === $kinds[0]) {
                $copy[] = ltrim($value, '/');
            }
            $keyed = [];
            foreach ($entry as $key => $setting) {
                $keyed[(string) $key] = $setting;
            }
            $packages[] = $keyed;
        }

        foreach ($list('copy', $settings['copy'] ?? null) as $i => $file) {
            if (!\is_string($file) || str_contains($file, '..')) {
                $errors[] = \sprintf('copy[%d]: a path in the hub\'s repository.', $i);
            } else {
                $copy[] = ltrim($file, '/');
            }
        }

        // The pictures kept beside the configuration are published with the hub.
        foreach (['logo' => $logo, 'theme.logo' => $theme?->logo, 'theme.banner' => $theme?->banner] as $key => $picture) {
            if (null === $picture || preg_match('~^https?://~', $picture)) {
                continue;
            }
            if (str_contains($picture, '..')) {
                $errors[] = \sprintf('%s: a file of the hub\'s repository, not "%s".', $key, $picture);
                continue;
            }
            $copy[] = ltrim($picture, '/');
            if (null !== $directory) {
                $path = rtrim($directory, '/').'/'.ltrim($picture, '/');
                if (!is_file($path)) {
                    $errors[] = \sprintf('%s: "%s" is not beside the configuration.', $key, $picture);
                } elseif (str_starts_with($key, 'theme.') && filesize($path) > self::PICTURE_BYTES) {
                    $errors[] = \sprintf('%s: "%s" is %d bytes; Forge Store draws a picture of 1 MB at most.', $key, $picture, filesize($path));
                }
            }
        }
        if (null !== $directory) {
            // A private key is never published, even when asked: the files copied with the hub are read first.
            foreach (array_unique($copy) as $file) {
                $path = rtrim($directory, '/').'/'.$file;
                if (is_file($path) && filesize($path) <= 256 && SigningKey::looksLikePrivateKey((string) file_get_contents($path))) {
                    $errors[] = \sprintf('copy: "%s" holds a private signing key - it is never published with the hub; move it out of the repository (and make a new pair if it was ever committed).', $file);
                }
            }
            foreach ($packages as $entry) {
                if (\is_string($entry['file'] ?? null) && !is_file(rtrim($directory, '/').'/'.ltrim($entry['file'], '/'))) {
                    $errors[] = \sprintf('packages: "%s" is not beside the configuration.', $entry['file']);
                }
            }
        }

        if ($errors) {
            throw new InvalidHubConfig($label, $errors);
        }

        return new self($name, $address, $scopes, $logo, $color, $theme, $sources, $packages, array_values(array_unique($copy)), $licensing);
    }

    /**
     * The "github" sources: what omnihub/github may resolve at build time (bin/omnihub --resolve-github).
     *
     * @return list<Source>
     */
    public function githubSources(): array
    {
        return array_values(array_filter($this->sources, fn (Source $source) => Source::GITHUB === $source->type));
    }

    /** @param list<Source>|null $sources the sources as resolved, else as configured */
    public function hub(?array $sources = null): StaticHub
    {
        return new StaticHub($this->url, $this->name, $this->scopes, $sources ?? $this->sources, $this->logo, $this->color, $this->theme, $this->licensing);
    }
}
