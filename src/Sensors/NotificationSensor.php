<?php

namespace Codeva\Watch\Sensors;

use Codeva\Watch\Clock;
use Codeva\Watch\Records\Notification;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Codeva\Watch\Types\Str;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use RuntimeException;

use function hash;
use function round;
use function str_contains;

/**
 * @internal
 */
final class NotificationSensor
{
    private ?float $startTime = null;

    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
    ) {
        //
    }

    /**
     * @return ?array{0: Notification, 1: callable(): array<mixed>}
     */
    public function __invoke(NotificationSending|NotificationSent $event): ?array
    {
        $now = $this->clock->microtime();

        if ($event instanceof NotificationSending) {
            $this->startTime = $now;

            return null;
        }

        if ($this->startTime === null) {
            throw new RuntimeException('No start time found for ['.$event->notifiable::class.'].');
        }

        if (str_contains($event->notification::class, "@anonymous\0")) {
            $class = Str::before($event->notification::class, "\0");
        } else {
            $class = $event->notification::class;
        }

        return [
            $record = new Notification(
                channel: $event->channel,
                class: $class,
                duration: (int) round(($now - $this->startTime) * 1_000_000),
            ),
            function () use ($now, $record) {
                $this->executionState->notifications++;

                return [
                    'v' => 1,
                    't' => 'notification',
                    'timestamp' => $now,
                    'deploy' => $this->executionState->deploy,
                    'server' => $this->executionState->server,
                    '_group' => hash('xxh128', $record->class),
                    'trace_id' => $this->executionState->trace,
                    'execution_source' => $this->executionState->source,
                    'execution_id' => $this->executionState->id(),
                    'execution_preview' => $this->executionState->executionPreview(),
                    'execution_stage' => $this->executionState->stage,
                    'user' => $this->executionState->user->id(),
                    // --- //
                    'channel' => Str::tinyText($record->channel),
                    'class' => Str::tinyText($record->class),
                    'duration' => $record->duration,
                    'failed' => false, // TODO: The framework doesn't dispatch the `NotificationFailed` event.
                ];
            },
        ];
    }
}
