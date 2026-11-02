<?php

declare(strict_types=1);

namespace Codeva\Watch\Support;

use Codeva\Watch\Transport\ServerClient;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

use function array_filter;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * Tells the server what the app's schedule is. The answer holds the ping token
 * of each task, which the heartbeat URLs are built from.
 *
 * @internal
 */
final class ScheduleSync
{
    public const string TOKENS_KEY = 'watch:heartbeat:tokens';

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ServerClient $server,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {
        //
    }

    /**
     * @return list<array{name: string, cron: string, timezone: string, grace_minutes: int}>
     */
    public function tasks(): array
    {
        $tasks = [];
        $grace = (int) $this->config->get('watch.schedule.grace_minutes', 5);

        foreach ($this->schedule->events() as $event) {
            $timezone = $event->timezone ?? $this->config->get('app.timezone', 'UTC');

            $tasks[] = [
                'name' => ScheduledTask::name($event),
                'cron' => $event->expression,
                'timezone' => $timezone instanceof DateTimeZone ? $timezone->getName() : (string) $timezone,
                'grace_minutes' => ScheduledTask::grace($event, $grace),
            ];
        }

        return $tasks;
    }

    /**
     * @return array{status: int, tasks: int, tokens: array<string, string>}
     */
    public function sync(): array
    {
        $tasks = $this->tasks();

        $response = $this->server->post('/api/schedule-sync', (string) json_encode(['tasks' => $tasks]), [
            'Authorization: Bearer '.$this->config->get('watch.token'),
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $tokens = [];

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $decoded = json_decode($response['body'], true);
            $heartbeats = is_array($decoded) && is_array($decoded['heartbeats'] ?? null) ? $decoded['heartbeats'] : [];

            /** @var array<string, string> $tokens */
            $tokens = array_filter($heartbeats, is_string(...));

            $this->cache->forever(self::TOKENS_KEY, $tokens);
        }

        return ['status' => $response['status'], 'tasks' => count($tasks), 'tokens' => $tokens];
    }

    /**
     * The task's token, syncing once if it is unknown (a flushed cache, or a
     * deploy that added a task).
     */
    public function pingToken(string $task): ?string
    {
        $tokens = $this->cache->get(self::TOKENS_KEY);

        if (! is_array($tokens) || ! is_string($tokens[$task] ?? null)) {
            $tokens = $this->sync()['tokens'];
        }

        return is_string($tokens[$task] ?? null) ? $tokens[$task] : null;
    }
}
