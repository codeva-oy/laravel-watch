<?php

declare(strict_types=1);

namespace Codeva\Watch\Jobs;

use Codeva\Watch\Transport\HttpSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

use function base64_decode;

/**
 * Carries one gzipped batch to the ingest API from a queue worker. Monitoring
 * data is not worth a retry: a failure opens the breaker and drops the batch.
 *
 * @internal
 */
final class SendBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $payload)
    {
        //
    }

    public function handle(HttpSender $sender): void
    {
        $sender->post((string) base64_decode($this->payload, true));
    }
}
