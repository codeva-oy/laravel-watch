<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\CommandState;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * @internal
 */
final class CommandBootedHandler
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->watch->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
