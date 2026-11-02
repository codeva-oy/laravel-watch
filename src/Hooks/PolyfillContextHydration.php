<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * @internal
 */
final class PolyfillContextHydration
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(JobProcessing $event): void
    {
        try {
            $watch = $event->job->payload()['watch'] ?? [];

            Compatibility::$context = [
                'watch_trace_id' => $watch['watch_trace_id'] ?? null,
                'watch_should_sample' => $watch['watch_should_sample'] ?? null,
                'watch_user_id' => $watch['watch_user_id'] ?? '',
            ];
        } catch (Throwable $e) {
            $this->watch->report($e);

            Compatibility::$context = [];
        }
    }
}
