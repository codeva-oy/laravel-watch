<?php

declare(strict_types=1);

namespace Codeva\Watch\Health;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

use function base_path;
use function bin2hex;
use function count;
use function disk_free_space;
use function disk_total_space;
use function is_numeric;
use function max;
use function microtime;
use function random_bytes;
use function round;

/**
 * The checks used when spatie/laravel-health is not installed.
 *
 * @internal
 *
 * @phpstan-type Outcome array{status: string, notificationMessage: string, shortSummary: string, meta: array<string, mixed>}
 */
final class BuiltInChecks
{
    public const string SCHEDULER_KEY = 'watch:scheduler:last_run';

    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
        private readonly ConnectionResolverInterface $db,
        private readonly CacheFactory $cache,
        private readonly QueueFactory $queue,
    ) {
        //
    }

    /**
     * @return array<string, array{0: string, 1: Closure(): Outcome}>
     */
    public function all(): array
    {
        return [
            'Database' => ['Database', $this->database(...)],
            'Cache' => ['Cache', $this->cache(...)],
            'QueueSize' => ['Queue size', $this->queueSize(...)],
            'FailedJobs' => ['Failed jobs', $this->failedJobs(...)],
            'UsedDiskSpace' => ['Used disk space', $this->diskSpace(...)],
            'DebugMode' => ['Debug mode', $this->debugMode(...)],
            'Schedule' => ['Scheduler heartbeat', $this->scheduler(...)],
        ];
    }

    /**
     * @return Outcome
     */
    private function database(): array
    {
        $start = microtime(true);

        $this->db->connection()->select('select 1');

        $ms = round((microtime(true) - $start) * 1000, 1);

        return self::outcome('ok', '', "{$ms} ms", ['connection' => $this->db->getDefaultConnection(), 'latency_ms' => $ms]);
    }

    /**
     * @return Outcome
     */
    private function cache(): array
    {
        $key = 'watch:health:'.bin2hex(random_bytes(8));
        $store = $this->cache->store();

        $store->put($key, 'ok', 30);
        $read = $store->get($key);
        $store->forget($key);

        return $read === 'ok'
            ? self::outcome('ok', '', 'Reads and writes')
            : self::outcome('failed', 'The cache did not return the value it was given.', 'Read failed');
    }

    /**
     * @return Outcome
     */
    private function queueSize(): array
    {
        $connection = (string) $this->config->get('queue.default', 'sync');

        if ($connection === 'sync') {
            return self::outcome('skipped', '', 'Sync queue');
        }

        /** @var list<string> $queues */
        $queues = (array) $this->config->get('watch.health.queues', ['default']);
        $sizes = [];

        foreach ($queues as $queue) {
            $sizes[$queue] = $this->queue->connection($connection)->size($queue);
        }

        $size = max($sizes ?: [0]);

        return self::threshold($size, 'watch.health.queue_size', $this, "{$size} waiting", "The largest queue has {$size} jobs waiting.", ['queues' => $sizes]);
    }

    /**
     * @return Outcome
     */
    private function failedJobs(): array
    {
        if (! $this->app->bound('queue.failer')) {
            return self::outcome('skipped', '', 'No failed job store');
        }

        /** @var FailedJobProviderInterface $failer */
        $failer = $this->app->make('queue.failer');
        $failed = $failer instanceof CountableFailedJobProvider ? $failer->count() : count($failer->all());

        return self::threshold($failed, 'watch.health.failed_jobs', $this, "{$failed} failed", "{$failed} jobs have failed.", ['count' => $failed]);
    }

    /**
     * @return Outcome
     */
    private function diskSpace(): array
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return self::outcome('skipped', '', 'Disk size unknown');
        }

        $used = (int) round(($total - $free) / $total * 100);

        return self::threshold($used, 'watch.health.disk_percent', $this, "{$used}%", "The disk is {$used}% full.", ['disk_space_used_percentage' => $used]);
    }

    /**
     * @return Outcome
     */
    private function debugMode(): array
    {
        if (! $this->config->get('app.debug')) {
            return self::outcome('ok', '', 'Off');
        }

        return $this->config->get('app.env') === 'production'
            ? self::outcome('failed', 'APP_DEBUG is on in production.', 'On')
            : self::outcome('warning', 'APP_DEBUG is on.', 'On');
    }

    /**
     * @return Outcome
     */
    private function scheduler(): array
    {
        $last = $this->cache->store()->get(self::SCHEDULER_KEY);

        if (! is_numeric($last)) {
            return self::outcome('warning', 'schedule:run has not been seen yet.', 'Never ran');
        }

        $minutes = (int) ((microtime(true) - (float) $last) / 60);
        $limit = (int) $this->config->get('watch.health.scheduler_minutes', 2);

        return $minutes <= $limit
            ? self::outcome('ok', '', "{$minutes} min ago", ['last_run_at' => (float) $last])
            : self::outcome('failed', "schedule:run last ran {$minutes} minutes ago.", "{$minutes} min ago", ['last_run_at' => (float) $last]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return Outcome
     */
    private static function threshold(int $value, string $key, self $checks, string $summary, string $message, array $meta): array
    {
        $failed = (int) $checks->config->get("{$key}_failed", PHP_INT_MAX);
        $warning = (int) $checks->config->get("{$key}_warning", PHP_INT_MAX);

        return match (true) {
            $value >= $failed => self::outcome('failed', $message, $summary, $meta),
            $value >= $warning => self::outcome('warning', $message, $summary, $meta),
            default => self::outcome('ok', '', $summary, $meta),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return Outcome
     */
    private static function outcome(string $status, string $message, string $summary, array $meta = []): array
    {
        return ['status' => $status, 'notificationMessage' => $message, 'shortSummary' => $summary, 'meta' => $meta];
    }
}
