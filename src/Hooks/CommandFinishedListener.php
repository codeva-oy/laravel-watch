<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\CommandFinished;
use Throwable;

/**
 * @internal
 */
final class CommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        try {
            if ($this->watch->capturingCommandNamed($event->command) && ! Compatibility::$terminatingEventExists) {
                $this->watch->stage(ExecutionStage::Terminating);
            }
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
