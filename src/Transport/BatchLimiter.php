<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use JsonException;

use function array_filter;
use function array_values;
use function in_array;
use function json_encode;
use function strlen;

/**
 * Enforces the uncompressed batch cap: cache events go first, then queries,
 * and exceptions are never dropped.
 *
 * @internal
 */
final class BatchLimiter
{
    private const array DROP_ORDER = [['cache-event', 'cache_event'], ['query']];

    public function __construct(private readonly int $maxBytes = 1_000_000)
    {
        //
    }

    /**
     * The batch as JSON, within the cap. Encoding is the expensive part, so
     * the common case that fits is only encoded once.
     *
     * @param  list<array<mixed>>  $records
     *
     * @throws JsonException
     */
    public function encode(array $records): string
    {
        $json = self::json($records);

        foreach (self::DROP_ORDER as $types) {
            if (strlen($json) <= $this->maxBytes) {
                break;
            }

            $records = array_values(array_filter(
                $records,
                static fn (array $record): bool => ! in_array($record['t'] ?? null, $types, true),
            ));

            $json = self::json($records);
        }

        return $json;
    }

    /**
     * @param  list<array<mixed>>  $records
     *
     * @throws JsonException
     */
    private static function json(array $records): string
    {
        return json_encode($records, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    }
}
