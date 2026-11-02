<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

/**
 * @internal
 */
final class MailListener
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    public function __invoke(MessageSending|MessageSent $event): void
    {
        try {
            $this->watch->mail($event);
        } catch (Throwable $e) {
            $this->watch->report($e, handled: true);
        }
    }
}
