<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Codeva\Watch\Contracts\Ingest;
use Codeva\Watch\RecordsBuffer;
use Deprecated;

/**
 * The transport for hosts with no reachable agent: buffers like the agent
 * transport does and hands each batch to the HttpSender.
 *
 * @internal
 */
final class HttpIngest implements Ingest
{
    private bool $shouldDigestWhenBufferIsFull = true;

    /** @var array{delivered: bool, via: string, error: string|null} */
    private array $lastDelivery = ['delivered' => false, 'via' => 'none', 'error' => null];

    public function __construct(
        public readonly RecordsBuffer $buffer,
        private readonly HttpSender $sender,
    ) {
        //
    }

    public function write(array $record): void
    {
        $this->buffer->write($record);

        if ($this->shouldDigestWhenBufferIsFull && $this->buffer->full) {
            $this->digest();
        }
    }

    public function writeNow(array $record): void
    {
        $this->send([$record]);
    }

    public function ping(): void
    {
        //
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        if ($this->buffer->count() > 0) {
            $this->send($this->buffer->pullRaw());
        }
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }

    public function lastDelivery(): array
    {
        return $this->lastDelivery;
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function send(array $records): void
    {
        $error = $this->sender->send($records);

        $this->lastDelivery = ['delivered' => $error === null, 'via' => $error === null ? 'http' : 'none', 'error' => $error];
    }
}
