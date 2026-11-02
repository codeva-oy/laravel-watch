<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Throwable;

/**
 * @internal
 */
final class WorkerLifecycleListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Looping|JobPopping|JobProcessing|WorkerStopping|CommandFinished $event): void
    {
        try {
            match ($event::class) {
                Looping::class, WorkerStopping::class => $this->watch->finishExecution()->waitForExecution(),
                CommandFinished::class => $event->command === 'queue:work' && $this->watch->finishExecution()->waitForExecution(),
                JobPopping::class => $this->watch->prepareForNextJob(),
                JobProcessing::class => $this->watch->prepareForJob($event->job),
            };
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
