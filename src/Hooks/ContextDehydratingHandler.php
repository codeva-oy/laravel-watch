<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Log\Context\Repository;
use Throwable;

/**
 * @internal
 */
final class ContextDehydratingHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(Repository $context): void
    {
        try {
            if (($context->getHidden('watch_user_id') ?? '') === '') {
                $context->addHidden('watch_user_id', $this->watch->executionState->user->resolvedUserId());
            }
        } catch (Throwable $e) {
            $this->watch->report($e);
        }
    }
}
