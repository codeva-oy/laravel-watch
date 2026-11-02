<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * @internal
 */
final class QueryExecutedListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(QueryExecuted $event): void
    {
        try {
            $this->watch->query($event);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
