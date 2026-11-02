<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Throwable;

/**
 * @internal
 */
final class ScheduledTaskStartingListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(ScheduledTaskStarting $event): void
    {
        try {
            $this->watch->prepareForScheduledTask($event->task);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
