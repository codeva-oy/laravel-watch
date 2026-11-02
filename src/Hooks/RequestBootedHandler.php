<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\RequestState;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * @internal
 */
final class RequestBootedHandler
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->watch->stage(ExecutionStage::BeforeMiddleware);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
