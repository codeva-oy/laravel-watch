<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Throwable;

/**
 * @internal
 */
final class ScheduledTaskListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): void
    {
        // We report the exception here because the scheduler handles it after the task has finished and the data is ingested.
        // This ensures that the exception is captured in the scheduled task record.
        if ($event instanceof ScheduledTaskFailed) {
            $this->watch->report($event->exception);
        }

        if ($this->isFinishedEventForFailedTask($event)) {
            return;
        }

        if ($event instanceof ScheduledTaskSkipped) {
            $this->watch->prepareForScheduledTask($event->task);
        }

        try {
            $this->watch->scheduledTask($event);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        $this->watch->finishExecution()->waitForExecution();
    }

    private function isFinishedEventForFailedTask(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): bool
    {
        return Compatibility::$firesFinishedAndFailedEventsForScheduledConsoleCommands &&
            $event instanceof ScheduledTaskFinished &&
            $event->task->command !== null &&
            $event->task->exitCode !== 0 &&
            ! $event->task->runInBackground;
    }
}
