<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\RequestState;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

/**
 * @internal
 */
final class RequestHandledListener
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(RequestHandled $event): void
    {
        try {
            $this->watch->stage(ExecutionStage::Sending);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
