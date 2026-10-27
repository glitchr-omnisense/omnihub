<?php

namespace Omnihub;

/**
 * A package of a hub - com.glitchr.licensing - and the versions it may be installed at. Who
 * may see which version (a licence, a key) is the caller's business: it hands over what it shows.
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
    ) {
        $list = [];
        foreach ($versions as $version) {
            $list[$version->version] = $version;
        }
        uksort($list, 'version_compare');
        $this->versions = array_values($list);
    }

    /** The newest: the newest stable version, else the newest pre-release. */
    public function latest(): ?Version
    {
        $stable = array_values(array_filter($this->versions, fn (Version $v) => !preg_match('/[a-z]/i', $v->version)));
        $pool = $stable ?: $this->versions;

        return $pool ? $pool[\count($pool) - 1] : null;
    }

    /** What its newest version's manifest says, or the default. */
    public function field(string $key, mixed $default = null): mixed
    {
        return $this->latest()?->manifest[$key] ?? $default;
    }
}
