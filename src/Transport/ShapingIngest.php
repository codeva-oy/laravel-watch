<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Codeva\Watch\Contracts\Ingest;
use Deprecated;

/**
 * Wraps every transport so that no record reaches one unshaped.
 *
 * @internal
 */
final class ShapingIngest implements Ingest
{
    public function __construct(private readonly Ingest $inner, private readonly RecordShaper $shaper)
    {
        //
    }

    public function write(array $record): void
    {
        $shaped = $this->shaper->shape($record);

        if ($shaped !== null) {
            $this->inner->write($shaped);
        }
    }

    public function writeNow(array $record): void
    {
        $shaped = $this->shaper->shape($record);

        if ($shaped !== null) {
            $this->inner->writeNow($shaped);
        }
    }

    public function ping(): void
    {
        $this->inner->ping();
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->inner->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->inner->shouldDigestWhenBufferIsFull($bool);
    }

    public function digest(): void
    {
        $this->inner->digest();
    }

    public function flush(): void
    {
        $this->inner->flush();
    }

    public function lastDelivery(): array
    {
        return $this->inner->lastDelivery();
    }
}
