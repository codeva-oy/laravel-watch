<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Closure;
use Throwable;

use function array_column;
use function array_splice;
use function base64_encode;
use function count;
use function gzencode;
use function in_array;
use function strlen;

/**
 * Posts batches straight to the ingest API, for hosts with no reachable agent.
 * Normally it runs after the response has been sent; on Vapor, or with
 * WATCH_HTTP_QUEUE, the batch goes through a queued job instead.
 *
 * @internal
 */
final class HttpSender
{
    /** A queued batch has to fit a queue message; SQS allows 256 KB. */
    private const int MAX_QUEUED_BYTES = 200_000;

    /**
     * @param  (Closure(string): void)  $dispatch  queues a base64 encoded gzip batch
     */
    public function __construct(
        private readonly ServerClient $server,
        private readonly string $token,
        private readonly string $serverName,
        private readonly CircuitBreaker $breaker,
        private readonly bool $queue,
        private readonly Closure $dispatch,
        private readonly BatchLimiter $limiter = new BatchLimiter,
        private readonly bool $runningInConsole = PHP_SAPI === 'cli',
    ) {
        //
    }

    /**
     * @param  list<array<mixed>>  $records
     * @return string|null why the batch was dropped, or null when it was not
     */
    public function send(array $records): ?string
    {
        try {
            if ($this->breaker->isOpen()) {
                return 'Watch server is unreachable (breaker open)';
            }

            $body = (string) gzencode($this->limiter->encode($records));

            if (! $this->queue || ($this->runningInConsole && self::hasException($records))) {
                return $this->post($body);
            }

            $this->enqueue($records, $body);

            return null;
        } catch (Throwable $e) {
            $this->breaker->open('Watch http send failed: '.$e->getMessage());

            return 'Watch http send failed: '.$e->getMessage();
        }
    }

    /**
     * @return string|null the failure, or null on a 2xx
     */
    public function post(string $gzippedBody): ?string
    {
        if (! $this->server->configured()) {
            return 'WATCH_SERVER_URL is not set';
        }

        $status = $this->server->post('/api/ingest', $gzippedBody, [
            "Authorization: Bearer {$this->token}",
            'Content-Type: application/json',
            'Content-Encoding: gzip',
            "X-Watch-Server: {$this->serverName}",
            'X-Watch-Agent-Version: http',
        ])['status'];

        if ($status >= 200 && $status < 300) {
            return null;
        }

        $error = $status === 0 ? 'Watch server unreachable' : "Watch server answered {$status}";

        $this->breaker->open($error);

        return $error;
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private static function hasException(array $records): bool
    {
        return in_array('exception', array_column($records, 't'), true);
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function enqueue(array $records, string $body): void
    {
        $payload = base64_encode($body);

        if (strlen($payload) <= self::MAX_QUEUED_BYTES || count($records) < 2) {
            ($this->dispatch)($payload);

            return;
        }

        $half = array_splice($records, 0, (int) (count($records) / 2));

        foreach ([$half, $records] as $part) {
            $this->enqueue($part, (string) gzencode($this->limiter->encode($part)));
        }
    }
}
