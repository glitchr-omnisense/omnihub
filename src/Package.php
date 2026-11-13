<?php

namespace Omnihub;

/**
 * A package of a hub - com.mystudio.tool - and the versions it may be installed at. Who
 * may see which version (a licence, a key) is the caller's business: it hands over what it shows.
 *
 * $displayName and $description are the caller's defaults for the newest manifest's own (a forge's
 * software name and tagline): the search and the listing write them when the package.json has none.
 */
final class Package
{
    /** @var list<Version> */
    public readonly array $versions;

    /**
     * @param iterable<Version> $versions
     */
    public function __construct(
        public readonly string $name,
        iterable $versions = [],
        public readonly ?string $homepage = null,
        public readonly ?string $repository = null,
        public readonly ?string $displayName = null,
        public readonly ?string $description = null,
    ) {
        $list = [];
        foreach ($versions as $version) {
            $list[$version->version] = $version;
        }
        uksort($list, fn ($a, $b) => version_compare((string) $a, (string) $b));
        $this->versions = array_values($list);
    }

    /** The newest: the newest stable version, else the newest pre-release. */
    public function latest(): ?Version
    {
        $stable = array_values(array_filter($this->versions, fn (Version $v) => !preg_match('/[a-z]/i', $v->version)));
        $pool = $stable ?: $this->versions;

        return $pool ? $pool[\count($pool) - 1] : null;
    }

    /**
     * What its newest version's manifest says; for displayName and description, when it says
     * nothing, the package's own default; then $default.
     */
    public function field(string $key, mixed $default = null): mixed
    {
        return $this->latest()?->manifest[$key] ?? match ($key) {
            'displayName' => $this->displayName,
            'description' => $this->description,
            default => null,
        } ?? $default;
    }
}
