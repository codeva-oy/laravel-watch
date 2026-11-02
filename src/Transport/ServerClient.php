<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use function fclose;
use function fopen;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function stream_context_create;
use function stream_get_contents;
use function stream_get_meta_data;

/**
 * Minimal POST to the Watch server over PHP's HTTP stream, so that nothing here
 * pulls an HTTP client library into the request path.
 *
 * @internal
 */
final class ServerClient
{
    public function __construct(private readonly string $serverUrl, private readonly float $timeout = 1.0)
    {
        //
    }

    public function configured(): bool
    {
        return $this->serverUrl !== '';
    }

    /**
     * @param  list<string>  $headers
     * @return array{status: int, body: string} status 0 when unreachable
     */
    public function post(string $path, string $body, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [...$headers, 'Connection: close']),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ]]);

        // Silences the stream wrapper's warnings for an unreachable server.
        set_error_handler(static fn (): bool => true);

        try {
            $stream = fopen(rtrim($this->serverUrl, '/').$path, 'r', false, $context);

            if ($stream === false) {
                return ['status' => 0, 'body' => ''];
            }

            $status = self::status(stream_get_meta_data($stream)['wrapper_data'] ?? null);
            $response = (string) stream_get_contents($stream);

            fclose($stream);

            return ['status' => $status, 'body' => $response];
        } finally {
            restore_error_handler();
        }
    }

    private static function status(mixed $headers): int
    {
        return is_array($headers) && is_string($headers[0] ?? null) && preg_match('#^HTTP/\S+ (\d{3})#', $headers[0], $match) === 1
            ? (int) $match[1]
            : 0;
    }
}
