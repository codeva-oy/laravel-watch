<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\Health\BuiltInChecks;
use Codeva\Watch\Records\Command;
use Codeva\Watch\Records\JobAttempt;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Throwable;

use function microtime;

/**
 * @internal
 */
final class CommandStartingListener
{
    private bool $hasRun = false;

    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Dispatcher $events,
        private Core $watch,
        private ConsoleKernelContract $kernel,
        private Cache $cache,
        private ?ScheduledTaskHeartbeatListener $heartbeats = null,
    ) {
        //
    }

    public function __invoke(CommandStarting $event): void
    {
        if ($this->hasRun) {
            return;
        }

        $this->hasRun = true;

        try {
            match ($event->command) {
                'queue:work', 'queue:listen', 'horizon:work', 'vapor:work' => $this->registerJobHooks($event),
                'schedule:run', 'schedule:work' => $this->registerScheduledTaskHooks(),
                'help', 'inspire', 'schedule:finish' => null,
                default => $this->registerCommandHooks($event),
            };
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);
        }
    }

    private function registerJobHooks(CommandStarting $event): void
    {
        $this->watch->configureForJobs();

        /**
         * @see Core::finishExecution()
         * @see CommandState::flush()
         * @see CommandState::$timestamp
         * @see CommandState::$id
         */
        $this->events->listen([
            Looping::class,
            JobPopping::class,
            JobProcessing::class,
            WorkerStopping::class,
            CommandFinished::class,
        ], (new WorkerLifecycleListener($this->watch))(...));

        /**
         * @see JobAttempt
         * @see Core::finishExecution()
         */
        $this->events->listen([
            JobProcessed::class,
            JobReleasedAfterException::class,
            JobFailed::class,
        ], (new JobAttemptListener($this->watch))(...));

        if ($event->command === 'vapor:work') {
            $this->events->listen(CommandFinished::class, (new VaporWorkCommandFinishedListener($this->watch))(...));
        }
    }

    private function registerScheduledTaskHooks(): void
    {
        $this->watch->configureForScheduledTasks();

        // schedule:run runs every minute even with nothing due, which makes
        // it the scheduler's own heartbeat for the health check.
        $this->watch->ignore(fn () => $this->cache->forever(BuiltInChecks::SCHEDULER_KEY, microtime(true)));

        if ($this->heartbeats !== null) {
            $this->events->listen([
                ScheduledTaskStarting::class,
                ScheduledTaskFinished::class,
                ScheduledTaskFailed::class,
            ], ($this->heartbeats)(...));
        }

        $this->events->listen(ScheduledTaskStarting::class, (new ScheduledTaskStartingListener($this->watch))(...));

        /**
         * @see Core::finishExecution()
         */
        $this->events->listen([
            ScheduledTaskFinished::class,
            ScheduledTaskSkipped::class,
            ScheduledTaskFailed::class,
        ], (new ScheduledTaskListener($this->watch))(...));
    }

    private function registerCommandHooks(CommandStarting $event): void
    {
        if (! $this->kernel instanceof ConsoleKernel) {
            return;
        }

        $this->watch->configureCommandSampling($event->command);

        $this->watch->prepareForCommand($event->command);

        /**
         * @see ExecutionStage::Terminating
         */
        $this->events->listen(CommandFinished::class, (new CommandFinishedListener($this->watch))(...));

        /**
         * @see ExecutionStage::End
         * @see Command
         * @see Core::finishExecution()
         */
        $this->kernel->whenCommandLifecycleIsLongerThan(-1, new CommandLifecycleIsLongerThanHandler($this->watch));
    }
}
