<?php

namespace Omnihub;

/**
 * Where a hub's packages come from - a provider: omnihub/github reads a repository's releases;
 * a forge reads its database. Each package with the versions it offers, tarball hashes taken.
 */
interface PackageSource
{
    /** @return iterable<Package> */
    public function packages(): iterable;
}
