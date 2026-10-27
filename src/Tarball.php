<?php

namespace Omnihub;

/**
 * The tarball npm and Unity's Package Manager install: a gzip'd ustar archive whose files are
 * under package/. Written from a tree (the same tree, the same bytes - the same shasum), and
 * read back: a file of it, its package.json.
 */
final class Tarball
{
    /**
     * @param array<string, string|array{0: string, 1: int}> $files path in the package => content, or [content, mode]
     */
    public static function create(array $files, int $mtime = 0): string
    {
        $entries = [];
        foreach ($files as $path => $file) {
            [$content, $mode] = \is_array($file) ? $file : [$file, 0644];
            $entries['package/'.ltrim($path, '/')] = [$content, $mode];
        }
        ksort($entries, \SORT_STRING);

        return (string) gzencode(self::ustar($entries, $mtime), 9);
    }

    /**
     * A ustar archive of those files (path => [content, mode]), every entry dated $mtime.
     *
     * @param array<string, array{0: string, 1: int}> $files
     */
    public static function ustar(array $files, int $mtime = 0): string
    {
        $tar = '';
        foreach ($files as $path => [$content, $mode]) {
            [$prefix, $name] = ['', $path];
            if (\strlen($path) > 100) {
                $cut = strrpos(substr($path, 0, 156), '/');
                if (false === $cut || \strlen(substr($path, $cut + 1)) > 100) {
                    throw new \RuntimeException(\sprintf('"%s" is too long a path for a ustar archive.', $path));
                }
                [$prefix, $name] = [substr($path, 0, $cut), substr($path, $cut + 1)];
            }
            $header = pack('a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
                $name, \sprintf('%07o', $mode & 0777), \sprintf('%07o', 0), \sprintf('%07o', 0),
                \sprintf('%011o', \strlen($content)), \sprintf('%011o', $mtime), str_repeat(' ', 8), '0', '',
                "ustar\0", '00', '', '', '', '', $prefix, '');
            $checksum = array_sum(array_map('ord', str_split($header)));
            $header = substr_replace($header, \sprintf('%06o', $checksum)."\0 ", 148, 8);
            $tar .= $header.$content.str_repeat("\0", (512 - \strlen($content) % 512) % 512);
        }

        return $tar.str_repeat("\0", 1024);
    }

    /**
     * The files of a .tgz (or a plain tar), by path - npm pack's and GitHub's alike: ustar, GNU
     * long names, pax headers.
     *
     * @return array<string, string>
     */
    public static function read(string $bytes): array
    {
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $bytes = (string) gzdecode($bytes);
        }
        $files = [];
        $offset = 0;
        $longName = null;
        $length = \strlen($bytes);
        while ($offset + 512 <= $length) {
            $header = substr($bytes, $offset, 512);
            if ('' === trim($header, "\0")) {
                break;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $size = octdec(trim(substr($header, 124, 12), "\0 ")) ?: 0;
            $type = $header[156];
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $content = substr($bytes, $offset + 512, (int) $size);
            $offset += 512 + (int) (ceil($size / 512) * 512);

            if ('L' === $type) {
                $longName = rtrim($content, "\0");
                continue;
            }
            if ('x' === $type || 'g' === $type) {
                if (preg_match('/\d+ path=([^\n]+)\n/', $content, $m)) {
                    $longName = $m[1];
                }
                continue;
            }
            $path = $longName ?? (('' !== $prefix && str_starts_with(substr($header, 257, 5), 'ustar')) ? $prefix.'/'.$name : $name);
            $longName = null;
            if ('0' === $type || "\0" === $type) {
                $files[$path] = $content;
            }
        }

        return $files;
    }

    /** Its package.json, wherever the tarball keeps it (package/package.json for npm pack). */
    public static function manifest(string $bytes): ?array
    {
        $files = self::read($bytes);
        foreach (['package/package.json', 'package.json'] as $path) {
            if (isset($files[$path])) {
                return json_decode($files[$path], true) ?: null;
            }
        }
        foreach ($files as $path => $content) {
            if (preg_match('~^[^/]+/package\.json$~', $path)) {
                return json_decode($content, true) ?: null;
            }
        }

        return null;
    }
}
