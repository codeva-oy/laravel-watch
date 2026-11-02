<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\RequestState;
use Illuminate\Routing\Events\PreparingResponse;
use Throwable;

/**
 * @internal
 */
final class PreparingResponseListener
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(PreparingResponse $event): void
    {
        try {
            if ($this->watch->executionStageIs(ExecutionStage::Action)) {
                $this->watch->stage(ExecutionStage::Render);
            }
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
