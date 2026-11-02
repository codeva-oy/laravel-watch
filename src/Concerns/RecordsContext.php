<?php

namespace Codeva\Watch\Concerns;

use Codeva\Watch\Compatibility;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\Types\Str;
use Illuminate\Support\Facades\Context;
use Throwable;

use function json_encode;

/**
 * @internal
 */
trait RecordsContext
{
    private function serializedContext(): string
    {
        if (! Compatibility::$contextExists) {
            return '';
        }

        try {
            return Str::text(json_encode((object) Context::all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } catch (Throwable $e) {
            Watch::unrecoverableExceptionOccurred($e);

            return '{"_watch_error":"Failed to serialize context"}';
        }
    }
}
