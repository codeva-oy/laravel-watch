<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use JsonSerializable;

/**
 * The rate the current execution was sampled at. Records hold a reference and
 * read it when serialised, because Sample::rate() and Watch::sample() can
 * change it after earlier records of the execution were written.
 *
 * @internal
 */
final class SampleRate implements JsonSerializable
{
    public function __construct(public float $value = 1.0)
    {
        //
    }

    public function jsonSerialize(): float
    {
        return $this->value;
    }
}
