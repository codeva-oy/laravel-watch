<?php

declare(strict_types=1);

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\Jobs\SendHeartbeat;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\Support\ScheduledTask;
use DateTimeZone;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

use function round;

/**
 * Queues a ping for every task transition. It never talks to the server itself,
 * so a slow Watch server cannot delay the scheduler.
 *
 * @internal
 */
final class ScheduledTaskHeartbeatListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
        private Dispatcher $bus,
        private string $environment,
    ) {
        //
    }

    public function __invoke(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        try {
            // Both events fire for a failed console task; only Failed counts.
            if (ScheduledTask::isInternal($event->task) || $this->isFinishedEventForFailedTask($event)) {
                return;
            }

            $body = $this->body($event);

            $this->watch->ignore(fn () => $this->bus->dispatch(new SendHeartbeat($body)));
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);
        }
    }

    private function isFinishedEventForFailedTask(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed $event): bool
    {
        return Compatibility::$firesFinishedAndFailedEventsForScheduledConsoleCommands
            && $event instanceof ScheduledTaskFinished
            && $event->task->command !== null
            && $event->task->exitCode !== 0
            && ! $event->task->runInBackground;
    }

    /**
     * @return array{event: string, task: string, cron: string, timezone: string, at: float, duration_ms: int|null, exit_code: int|null, background: bool, server: string, deploy: string, environment: string}
     */
    private function body(ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed $event): array
    {
        $task = $event->task;

        return [
            'event' => match (true) {
                $event instanceof ScheduledTaskStarting => 'started',
                $event instanceof ScheduledTaskFailed => 'failed',
                $task->exitCode !== null && $task->exitCode !== 0 => 'failed',
                default => 'finished',
            },
            'task' => ScheduledTask::name($task),
            'cron' => $task->expression,
            'timezone' => self::timezone($task),
            'at' => $this->watch->clock->microtime(),
            'duration_ms' => $event instanceof ScheduledTaskFinished ? (int) round($event->runtime * 1000) : null,
            'exit_code' => $event instanceof ScheduledTaskStarting ? null : $task->exitCode,
            'background' => $task->runInBackground,
            'server' => $this->watch->executionState->server,
            'deploy' => $this->watch->executionState->deploy,
            'environment' => $this->environment,
        ];
    }

    private static function timezone(Event $task): string
    {
        return $task->timezone instanceof DateTimeZone ? $task->timezone->getName() : (string) ($task->timezone ?? 'UTC');
    }
}
