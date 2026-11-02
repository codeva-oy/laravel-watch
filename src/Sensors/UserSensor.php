<?php

namespace Codeva\Watch\Sensors;

use Codeva\Watch\Clock;
use Codeva\Watch\State\RequestState;
use Codeva\Watch\Types\Str;

/**
 * @internal
 */
final class UserSensor
{
    public function __construct(
        private RequestState $requestState,
        public Clock $clock,
    ) {
        //
    }

    /**
     * @return ?array<mixed>
     */
    public function __invoke(): ?array
    {
        $details = $this->requestState->user->details();

        if ($details === null) {
            return null;
        }

        return [
            'v' => 1,
            't' => 'user',
            'timestamp' => $this->clock->microtime(),
            'id' => Str::tinyText((string) $details['id']),
            'name' => Str::tinyText((string) ($details['name'] ?? '')),
            'username' => Str::tinyText((string) ($details['username'] ?? '')),
        ];
    }
}
