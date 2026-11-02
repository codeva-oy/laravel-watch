<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Codeva\Watch\Payload;

use function explode;
use function fclose;
use function fwrite;
use function hash;
use function hash_hmac;
use function is_array;
use function json_decode;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_client;
use function substr;

/**
 * Asks an agent for its state over the same framing as a batch.
 *
 * @internal
 */
final class AgentStatus
{
    public function __construct(private readonly string $address, private readonly string $token)
    {
        //
    }

    /**
     * @return array<string, mixed>|null null when the agent rejected the request
     *
     * @throws AgentUnreachable
     */
    public function fetch(): ?array
    {
        $uri = str_starts_with($this->address, '/') ? "unix://{$this->address}" : (str_contains($this->address, '://') ? $this->address : "tcp://{$this->address}");

        set_error_handler(static fn (): bool => true);

        try {
            $stream = stream_socket_client($uri, $code, $message, 1.0);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new AgentUnreachable((string) $message);
        }

        $auth = AgentIngest::isLoopback($this->address) ? substr(hash('xxh128', $this->token), 0, 7) : hash_hmac('sha256', 'STATUS', $this->token);

        fwrite($stream, Payload::text('STATUS', $auth)->pull());
        stream_set_timeout($stream, 1);

        $reply = (string) stream_get_contents($stream);
        fclose($stream);

        $status = json_decode(explode(':', $reply, 2)[1] ?? '', true);

        return is_array($status) ? $status : null;
    }
}
