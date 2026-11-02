<?php

declare(strict_types=1);

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Laravel\Octane\Events\RequestTerminated;

/**
 * The kernel's terminate step normally flushes first and this finds an empty
 * buffer; it is the backstop for a worker that skipped that step.
 *
 * @internal
 */
final class OctaneRequestTerminatedListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(private Core $watch)
    {
        //
    }

    public function __invoke(RequestTerminated $event): void
    {
        $this->watch->finishExecution();
    }
}
