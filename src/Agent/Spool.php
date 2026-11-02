<?php

declare(strict_types=1);

namespace Codeva\Watch\Agent;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function glob;
use function hrtime;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rename;
use function sort;
use function sprintf;
use function strlen;
use function unlink;

/**
 * Gzipped batches waiting for the server. Filenames sort by arrival, and the
 * oldest batches are deleted first once the cap is reached.
 *
 * @internal
 */
final class Spool
{
    private int $bytes = 0;

    public function __construct(private readonly string $path, private readonly int $capBytes)
    {
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }

        foreach ($this->files() as $file) {
            $this->bytes += (int) filesize($file);
        }
    }

    public function put(string $gzipped): void
    {
        $this->makeRoomFor(strlen($gzipped));

        $name = sprintf('%s/%020d-%s', $this->path, hrtime(true), bin2hex(random_bytes(4)));

        file_put_contents("{$name}.tmp", $gzipped);
        rename("{$name}.tmp", "{$name}.gz");

        $this->bytes += strlen($gzipped);
    }

    /**
     * @return array{0: string, 1: string}|null [file, gzipped body]
     */
    public function oldest(): ?array
    {
        $file = $this->files()[0] ?? null;

        if ($file === null) {
            return null;
        }

        $body = file_get_contents($file);

        return $body === false ? null : [$file, $body];
    }

    public function delete(string $file): void
    {
        $size = (int) @filesize($file);

        if (@unlink($file)) {
            $this->bytes -= $size;
        }
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function isEmpty(): bool
    {
        return $this->files() === [];
    }

    private function makeRoomFor(int $bytes): void
    {
        foreach ($this->files() as $file) {
            if ($this->bytes + $bytes <= $this->capBytes) {
                return;
            }

            $this->delete($file);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob("{$this->path}/*.gz") ?: [];

        sort($files);

        return $files;
    }
}
