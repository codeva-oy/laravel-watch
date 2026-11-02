<?php

namespace Codeva\Watch\Hooks;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PolyfillContextDehydration
{
    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function __construct(
        private Core $watch,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(mixed $connection, mixed $queue, array $payload): array
    {
        $context = Compatibility::$context;

        try {
            if (($context['watch_user_id'] ?? '') === '') {
                $context['watch_user_id'] = $this->watch->executionState->user->resolvedUserId();
            }

            return [
                ...$payload,
                'watch' => [
                    ...($payload['watch'] ?? []),
                    'watch_trace_id' => $context['watch_trace_id'] ?? null,
                    'watch_should_sample' => $context['watch_should_sample'] ?? null,
                    'watch_user_id' => $context['watch_user_id'],
                ],
            ];
        } catch (Throwable $e) {
            $this->watch->report($e);

            return $payload;
        }
    }
}
