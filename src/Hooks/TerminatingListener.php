<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Foundation\Events\Terminating;
use Throwable;

/**
 * @internal
 */
final class TerminatingListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Terminating $event): void
    {
        if (! Compatibility::$terminatingEventExists) {
            return;
        }

        try {
            $this->watch->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
