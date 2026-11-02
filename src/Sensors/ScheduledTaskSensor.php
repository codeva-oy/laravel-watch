<?php

namespace Codeva\Watch\Sensors;

use Codeva\Watch\Clock;
use Codeva\Watch\Compatibility;
use Codeva\Watch\Concerns\RecordsContext;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\Support\ScheduledTask;
use Codeva\Watch\Types\Str;
use DateTimeZone;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;

use function hash;
use function round;

/**
 * @internal
 */
final class ScheduledTaskSensor
{
    use RecordsContext;

    public function __construct(
        private CommandState $commandState,
        private Clock $clock,
    ) {
        //
    }

    /**
     * @return array<mixed>
     */
    public function __invoke(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): array
    {
        $now = $this->clock->microtime();
        $name = ScheduledTask::name($event->task);
        $timezone = $event->task->timezone instanceof DateTimeZone ? $event->task->timezone->getName() : $event->task->timezone;
        $repeatSeconds = Compatibility::$subMinuteScheduledTasksSupported && $event->task->repeatSeconds !== null ? $event->task->repeatSeconds : 0;

        return [
            'v' => 1,
            't' => 'scheduled-task',
            'timestamp' => $this->commandState->timestamp,
            'deploy' => $this->commandState->deploy,
            'server' => $this->commandState->server,
            '_group' => $repeatSeconds > 0
                ? hash('xxh128', "{$name},{$event->task->expression},{$timezone},{$repeatSeconds}")
                : hash('xxh128', "{$name},{$event->task->expression},{$timezone}"),
            'trace_id' => $this->commandState->trace,
            // --- //
            'name' => $name,
            'cron' => $event->task->expression,
            'timezone' => $timezone,
            'repeat_seconds' => $repeatSeconds,
            'without_overlapping' => $event->task->withoutOverlapping,
            'on_one_server' => $event->task->onOneServer,
            'run_in_background' => $event->task->runInBackground,
            'even_in_maintenance_mode' => $event->task->evenInMaintenanceMode,
            'status' => match (true) {
                $event instanceof ScheduledTaskFailed => 'failed',
                $event instanceof ScheduledTaskSkipped => 'skipped',
                default => 'processed',
            },
            'duration' => $event instanceof ScheduledTaskSkipped ? 0 : (int) round(($now - $this->commandState->timestamp) * 1_000_000),
            // --- //
            'exceptions' => $this->commandState->exceptions,
            'logs' => $this->commandState->logs,
            'queries' => $this->commandState->queries,
            'lazy_loads' => $this->commandState->lazyLoads,
            'jobs_queued' => $this->commandState->jobsQueued,
            'mail' => $this->commandState->mail,
            'notifications' => $this->commandState->notifications,
            'outgoing_requests' => $this->commandState->outgoingRequests,
            'files_read' => $this->commandState->filesRead,
            'files_written' => $this->commandState->filesWritten,
            'cache_events' => $this->commandState->cacheEvents,
            'hydrated_models' => $this->commandState->hydratedModels,
            'peak_memory_usage' => $this->commandState->peakMemory(),
            'exception_preview' => Str::tinyText($this->commandState->exceptionPreview),
            'context' => $this->serializedContext(),
        ];
    }
}
