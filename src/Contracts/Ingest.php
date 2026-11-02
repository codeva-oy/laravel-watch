<?php

namespace Codeva\Watch\Contracts;

use Deprecated;

/**
 * @internal
 */
interface Ingest
{
    /**
     * @param  array<mixed>  $record
     */
    public function write(array $record): void;

    /**
     * @param  array<mixed>  $record
     */
    public function writeNow(array $record): void;

    public function ping(): void;

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void;

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void;

    public function digest(): void;

    public function flush(): void;

    /**
     * What happened to the last batch; watch:test prints it.
     *
     * @return array{delivered: bool, via: string, error: string|null}
     */
    public function lastDelivery(): array;
}
