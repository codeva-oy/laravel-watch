<?php

namespace Codeva\Watch\Hooks;

use Carbon\Carbon;
use Codeva\Watch\Core;
use Codeva\Watch\ExecutionStage;
use Codeva\Watch\State\RequestState;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class RequestLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<RequestState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, Request $request, Response $response): void
    {
        try {
            $this->watch->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        try {
            $this->watch->captureUser();
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        try {
            $this->watch->request($request, $response);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }

        $this->watch->finishExecution();
    }
}
