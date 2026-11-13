<?php

namespace Omnihub;

/**
 * The payload of a licence a static hub's author signs offline (omnihub licence sign): format 2,
 * as Forge Store (io.glitchr.store, LicenceCheck), Forge Seal (io.glitchr.licensing,
 * LicenseToken / PackageLicence) and Forge Hub check it - the store's registry, the package (or,
 * for a game, the software), who it was sold to, and optionally an end, an end of updates and
 * one machine.
 *
 *   {"v": 2, "registry": "https://owner.github.io/hub", "package": "com.example.tool",
 *    "licensee": "Ada <ada@example.org>", "issued_at": 1792972800}
 *
 * The fields come in omnibase/forge's order (LicenseToken::issue(): v, software, registry,
 * package, ..., machine, issued_at, valid_until, updates_until), the seat's e-mail replaced by the
 * licensee. Absent means: no machine - any machine; no valid_until - no end; no updates_until -
 * every release. A field is never written empty or null: it is left out. Times are JSON
 * integers (Forge Seal's JsonUtility reads them into long; a string would read as 0, "no end").
 */
final class OfflineLicence
{
    public const VERSION = 2;

    /** Every field an offline licence may carry, in the order it is written. */
    public const FIELDS = ['v', 'software', 'registry', 'package', 'licensee', 'email', 'machine', 'issued_at', 'valid_until', 'updates_until'];

    public const LICENSEE_LENGTH = 200;

    /** A UPM name: lowercase, reverse-domain, two segments at least, 214 characters at most (npm's limit). */
    private const PACKAGE = '/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/';

    /** A game's or an application's slug, as the game passes it to LicenseToken.Check. */
    private const SOFTWARE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';

    private const MACHINE = '/^[0-9a-f]{64}$/';

    /** What .NET's String.Trim() and Rust's str::trim() take away: Unicode's White_Space. */
    private const WHITE_SPACE = '[\x{9}-\x{D}\x{85}\p{Z}]';

    /**
     * The payload to sign: $fields (a payload file's, then the flags') in order, v forced to 2,
     * issued_at now when not given, nulls and empty texts left out - checked.
     *
     * @param array<array-key, mixed> $fields
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException every problem, one per line
     */
    public static function payload(array $fields, ?int $now = null): array
    {
        $fields['v'] = self::VERSION;
        if (!isset($fields['issued_at'])) {
            $fields['issued_at'] = $now ?? time();
        }
        if (\is_string($fields['registry'] ?? null)) {
            $fields['registry'] = self::normalizeRegistry($fields['registry']);
        }
        if (\is_string($fields['licensee'] ?? null)) {
            $fields['licensee'] = self::trim($fields['licensee']);
        }
        $payload = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            if (null !== $value && '' !== $value) {
                $payload[$field] = $value;
            }
        }
        foreach (array_diff(array_map('strval', array_keys($fields)), self::FIELDS) as $unknown) {
            $payload[$unknown] = $fields[$unknown];
        }
        if ($problems = self::problems($payload)) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        return $payload;
    }

    /**
     * Every problem of a payload as the clients would meet it, one sentence each, naming its field.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    public static function problems(array $payload): array
    {
        $problems = [];
        foreach (array_diff(array_map('strval', array_keys($payload)), self::FIELDS) as $unknown) {
            $problems[] = \sprintf('%s: not a field of an offline licence (%s).', $unknown, implode(', ', self::FIELDS));
        }
        if (self::VERSION !== ($payload['v'] ?? null)) {
            $problems[] = 'v: 2, the format bound to its store (a format 1 token is refused by Forge Store).';
        }

        $registry = $payload['registry'] ?? null;
        if (!\is_string($registry)) {
            $problems[] = 'registry: the hub\'s registry address, as its manifest lists it (sources[0].url): --hub <hub.yaml> or --registry <https://...>.';
        } elseif (null !== ($problem = self::registryProblem($registry))) {
            $problems[] = 'registry: '.$problem;
        }

        $package = $payload['package'] ?? null;
        $software = $payload['software'] ?? null;
        if ((null === $package) === (null === $software)) {
            $problems[] = null === $package
                ? 'package: the UPM name the licence opens (--package com.example.tool), or, for a game or an application, its slug (--software).'
                : 'package, software: one or the other - a package\'s licence (Forge Store, PackageLicence) or a game\'s (LicenseToken.Check with its slug).';
        }
        if (null !== $package && (!\is_string($package) || !self::isPackageName($package))) {
            $problems[] = \sprintf('package: a UPM name, lowercase and reverse-domain ("com.example.tool"), not %s.', self::show($package));
        }
        if (null !== $software && (!\is_string($software) || !preg_match(self::SOFTWARE, $software))) {
            $problems[] = \sprintf('software: a slug (letters, digits, ".", "_", "-"; 100 at most), not %s.', self::show($software));
        }

        $licensee = $payload['licensee'] ?? null;
        if (!\is_string($licensee) || '' === self::trim($licensee)) {
            $problems[] = 'licensee: who it was sold to, a name and/or an e-mail (--licensee "Ada <ada@example.org>"); Forge Store shows it.';
        } elseif (1 !== preg_match('//u', $licensee) || preg_match('/\p{Cc}/u', $licensee)) {
            $problems[] = 'licensee: UTF-8 text without control characters (no line break, no tab).';
        } elseif (($length = (int) preg_match_all('/./su', $licensee)) > self::LICENSEE_LENGTH) {
            $problems[] = \sprintf('licensee: %d characters at most, not %d.', self::LICENSEE_LENGTH, $length);
        }
        if (isset($payload['email']) && (!\is_string($payload['email']) || preg_match('/[\x00-\x1F\x7F]/', $payload['email']))) {
            $problems[] = 'email: a text without control characters.';
        }

        $machine = $payload['machine'] ?? null;
        if (null !== $machine && (!\is_string($machine) || !preg_match(self::MACHINE, $machine))) {
            $problems[] = 'machine: SHA-256 of the machine\'s identifier, 64 lowercase hex digits (--machine <identifier> hashes it, --machine-hash <hex> takes it as given); leave it out for any machine.';
        }

        foreach (['issued_at', 'valid_until', 'updates_until'] as $time) {
            if (\array_key_exists($time, $payload) && (!\is_int($payload[$time]) || $payload[$time] <= 0)) {
                $problems[] = \sprintf('%s: UNIX seconds, a JSON integer (Forge Seal reads anything else as 0), not %s.', $time, self::show($payload[$time]));
            }
        }
        if (!\array_key_exists('issued_at', $payload)) {
            $problems[] = 'issued_at: UNIX seconds.';
        }
        if (\is_int($payload['issued_at'] ?? null) && \is_int($payload['valid_until'] ?? null) && $payload['valid_until'] <= $payload['issued_at']) {
            $problems[] = \sprintf('valid_until: after issued_at (%s), not %s.', self::day($payload['issued_at']), self::day($payload['valid_until']));
        }

        return $problems;
    }

    /** Whether that is a UPM name: lowercase, reverse-domain ("com.example.tool"), 214 characters at most. */
    public static function isPackageName(string $name): bool
    {
        return \strlen($name) <= 214 && 1 === preg_match(self::PACKAGE, $name);
    }

    /** The machine as a licence names it: SHA-256 (lowercase hex) of its identifier, trimmed as the clients trim it, in UTF-8. */
    public static function machineHash(string $identifier): string
    {
        return hash('sha256', self::trim($identifier));
    }

    /** A registry address as a licence names it: trimmed, without its final slash. */
    public static function normalizeRegistry(string $registry): string
    {
        return rtrim(trim($registry), '/');
    }

    /** Why that cannot be a hub's registry address, or null: an absolute https address (http on this machine only), no query, no fragment. */
    public static function registryProblem(string $registry): ?string
    {
        $parts = parse_url($registry);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (false === $parts || '' === $host || !\in_array($scheme, ['https', 'http'], true) || preg_match('/\s/', $registry)) {
            return \sprintf('an absolute address, https://<owner>.github.io/<repository>, not "%s".', $registry);
        }
        if ('http' === $scheme && !\in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return \sprintf('https (http only for localhost): Forge Store reads hubs over https, not "%s".', $registry);
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return \sprintf('a registry address has no query, fragment or user, not "%s".', $registry);
        }

        return null;
    }

    /**
     * Two registry addresses of the same store, as the clients compare them (Forge Store's
     * LicenceCheck.SameRegistry, Forge Seal's LicenseToken.SameRegistry, Forge Hub's
     * same_registry): a final slash, the scheme's and the host's case and a default port aside;
     * the path keeps its case.
     */
    public static function sameRegistry(?string $a, ?string $b): bool
    {
        $a = null === $a ? '' : trim($a);
        $b = null === $b ? '' : trim($b);
        if ('' === $a || '' === $b) {
            return false;
        }
        $x = parse_url($a);
        $y = parse_url($b);
        if (\is_array($x) && \is_array($y) && isset($x['scheme'], $x['host'], $y['scheme'], $y['host'])) {
            $port = fn (string $scheme, ?int $port): ?int => $port ?? match (strtolower($scheme)) {
                'https' => 443,
                'http' => 80,
                default => null,
            };

            return strtolower($x['scheme']) === strtolower($y['scheme'])
                && strtolower($x['host']) === strtolower($y['host'])
                && $port($x['scheme'], $x['port'] ?? null) === $port($y['scheme'], $y['port'] ?? null)
                && rtrim($x['path'] ?? '', '/') === rtrim($y['path'] ?? '', '/')
                && ($x['query'] ?? null) === ($y['query'] ?? null);
        }

        return rtrim($a, '/') === rtrim($b, '/');
    }

    /**
     * A time given on the command line: a day (YYYY-MM-DD: its first second, UTC - the day Forge
     * Store shows as "until") or UNIX seconds.
     *
     * @throws \InvalidArgumentException
     */
    public static function time(string $value, string $name): int
    {
        $value = trim($value);
        if (preg_match('/^\d{1,12}$/', $value)) {
            return (int) $value;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(\sprintf('%s: a day, YYYY-MM-DD (UTC), or UNIX seconds, not "%s".', $name, $value));
        }

        return $day->getTimestamp();
    }

    /** A UNIX time as the clients show it: its day, UTC. */
    public static function day(int $unix): string
    {
        return gmdate('Y-m-d', $unix);
    }

    /**
     * The JSON that is signed: Forge Seal's form, slashes and Unicode unescaped.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload): string
    {
        return json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private static function trim(string $text): string
    {
        return 1 === preg_match('//u', $text) ? (string) preg_replace('/^'.self::WHITE_SPACE.'+|'.self::WHITE_SPACE.'+$/u', '', $text) : trim($text);
    }

    private static function show(mixed $value): string
    {
        return \is_string($value) ? '"'.$value.'"' : get_debug_type($value);
    }
}
