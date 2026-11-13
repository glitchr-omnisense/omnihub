<?php

namespace Omnihub;

/**
 * What a seller sold, kept on their machine (omnihub licence sign --log <file.jsonl>): one JSON
 * line per licence signed, appended, never rewritten - the payload as signed and the fingerprint
 * of the key that signed it, so that after a change of key every licence can be signed again with
 * the new one (omnihub licence resign). Never the private key; the token only when the seller
 * asks (--log-token): the payload and the key are enough to sign it again.
 *
 *   {"logged_at": "2026-11-13T09:00:00Z", "key": "ab:cd:...", "payload": {"v": 2, ...}}
 *
 * The file names buyers: it is created readable by its owner only (0600), and belongs outside the
 * hub's repository, as the key does.
 */
final class LicenceLog
{
    public function __construct(public readonly string $path)
    {
    }

    /**
     * One licence appended (the file made, 0600, when it is not there).
     *
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException
     */
    public function append(array $payload, string $fingerprint, ?string $token = null, ?int $now = null): void
    {
        $line = json_encode(array_filter([
            'logged_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
            'key' => $fingerprint,
            'payload' => $payload,
            'token' => $token,
        ], fn ($value) => null !== $value), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
        if (!is_dir(\dirname($this->path))) {
            throw new \RuntimeException(\sprintf('%s: no such folder.', \dirname($this->path)));
        }
        $mask = umask(0077);
        try {
            $handle = @fopen($this->path, 'a');
            if (false === $handle) {
                throw new \RuntimeException(\sprintf('%s: cannot be written.', $this->path));
            }
            // Two signings at once never interleave their lines.
            flock($handle, \LOCK_EX);
            $written = fwrite($handle, $line);
            fflush($handle);
            flock($handle, \LOCK_UN);
            fclose($handle);
            if (\strlen($line) !== $written) {
                throw new \RuntimeException(\sprintf('%s: the licence could not be written whole.', $this->path));
            }
        } finally {
            umask($mask);
        }
    }

    /**
     * Every licence logged, oldest first: [line number, entry].
     *
     * @return list<array{0: int, 1: array{logged_at?: string, key?: string, payload: array<array-key, mixed>, token?: string}}>
     *
     * @throws \RuntimeException a file that cannot be read, or a line that is not a logged licence
     */
    public function entries(): array
    {
        $lines = @file($this->path, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            throw new \RuntimeException(\sprintf('%s: cannot be read.', $this->path));
        }
        $entries = [];
        foreach ($lines as $i => $line) {
            if ('' === trim($line)) {
                continue;
            }
            $entry = json_decode($line, true);
            if (!\is_array($entry) || !\is_array($entry['payload'] ?? null)) {
                throw new \RuntimeException(\sprintf('%s:%d: not a logged licence (a JSON object with its "payload").', $this->path, $i + 1));
            }
            /** @var array{logged_at?: string, key?: string, payload: array<array-key, mixed>, token?: string} $entry */
            $entries[] = [$i + 1, $entry];
        }

        return $entries;
    }
}
