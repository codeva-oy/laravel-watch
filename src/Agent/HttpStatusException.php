<?php

declare(strict_types=1);

namespace Codeva\Watch\Agent;

use RuntimeException;

/** The server answered, but not with 2xx. */
final class HttpStatusException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?int $retryAfter = null)
    {
        parent::__construct("Server responded with HTTP {$status}");
    }
}
