<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Codeva\Watch\Contracts\Ingest;

/**
 * Keeps records in memory only; used in tests and when monitoring is disabled.
 *
 * @internal
 */
final class NullIngest implements Ingest
{
    /** @var list<array<mixed>> */
    public array $records = [];

    public function write(array $record): void
    {
        $this->records[] = $record;
    }

    public function writeNow(array $record): void
    {
        $this->records[] = $record;
    }

    public function ping(): void
    {
        //
    }

    public function shouldDigest(bool $bool = true): void
    {
        //
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        //
    }

    public function digest(): void
    {
        //
    }

    public function flush(): void
    {
        $this->records = [];
    }

    public function lastDelivery(): array
    {
        return ['delivered' => true, 'via' => 'null', 'error' => null];
    }
}
