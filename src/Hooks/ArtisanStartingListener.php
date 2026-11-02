<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Illuminate\Console\Events\ArtisanStarting;
use Throwable;

/**
 * @internal
 */
final class ArtisanStartingListener
{
    /**
     * @param  Core<CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(ArtisanStarting $event): void
    {
        try {
            $this->watch->captureArtisan($event->artisan);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
