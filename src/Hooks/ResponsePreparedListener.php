<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\RequestState;
use Illuminate\Routing\Events\ResponsePrepared;
use Throwable;

/**
 * @internal
 */
final class ResponsePreparedListener
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(ResponsePrepared $event): void
    {
        try {
            if ($this->watch->executionStageIs(ExecutionStage::Render)) {
                $this->watch->stage(ExecutionStage::AfterMiddleware);
            }
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
