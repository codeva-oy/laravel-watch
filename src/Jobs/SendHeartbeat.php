<?php

declare(strict_types=1);

namespace Codeva\Watch\Jobs;

use Codeva\Watch\Support\ScheduleSync;
use Codeva\Watch\Transport\ServerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

use function is_string;
use function json_encode;
use function rawurlencode;

/**
 * A lost ping raises a false "missed run" alert, so unlike batches this one is
 * retried; it is released rather than failed to stay out of failed_jobs.
 *
 * @internal
 */
final class SendHeartbeat implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array{event: string, task: string, cron: string, timezone: string, at: float, duration_ms: int|null, exit_code: int|null, background: bool, server: string, deploy: string, environment: string}  $body
     */
    public function __construct(public readonly array $body)
    {
        //
    }

    public function handle(ScheduleSync $sync, ServerClient $server): void
    {
        $token = $sync->pingToken($this->body['task']);

        if ($token === null) {
            return;
        }

        $status = $this->ping($server, $token);

        // A heartbeat recreated on the server leaves a stale token here: fetch it again once.
        if ($status === 404) {
            $fresh = $sync->sync()['tokens'][$this->body['task']] ?? null;
            $status = is_string($fresh) ? $this->ping($server, $fresh) : $status;
        }

        if (($status < 200 || $status >= 300) && $this->attempts() < $this->tries) {
            $this->release(10);
        }
    }

    private function ping(ServerClient $server, string $token): int
    {
        return $server->post('/api/heartbeat/'.rawurlencode($token), (string) json_encode($this->body), [
            'Content-Type: application/json',
            'Accept: application/json',
        ])['status'];
    }
}
