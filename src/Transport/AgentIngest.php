<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Codeva\Watch\Contracts\Ingest;
use Codeva\Watch\Payload;
use Codeva\Watch\RecordsBuffer;
use Deprecated;
use RuntimeException;
use Throwable;

use function fclose;
use function fread;
use function fwrite;
use function hash;
use function hash_hmac;
use function in_array;
use function parse_url;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;
use function str_starts_with;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;

/**
 * Writes frames to the agent over TCP or a Unix socket. It never throws into
 * the host application: a failed send drops the batch and opens the breaker.
 *
 * @internal
 */
final class AgentIngest implements Ingest
{
    private bool $shouldDigestWhenBufferIsFull = true;

    private readonly string $tokenField;

    private readonly bool $signsFrames;

    /** @var array{delivered: bool, via: string, error: string|null} */
    private array $lastDelivery = ['delivered' => false, 'via' => 'none', 'error' => null];

    public function __construct(
        private readonly string $address,
        private readonly string $token,
        public readonly RecordsBuffer $buffer,
        private readonly CircuitBreaker $breaker,
        private readonly BatchLimiter $limiter = new BatchLimiter,
        private readonly float $connectTimeout = 0.5,
        private readonly float $readTimeout = 0.5,
        private readonly ?HttpSender $fallback = null,
    ) {
        $this->tokenField = substr(hash('xxh128', $token), 0, 7);
        $this->signsFrames = ! self::isLoopback($address);
    }

    public function write(array $record): void
    {
        $this->buffer->write($record);

        if ($this->shouldDigestWhenBufferIsFull && $this->buffer->full) {
            $this->digest();
        }
    }

    public function writeNow(array $record): void
    {
        $this->send([$record]);
    }

    public function digest(): void
    {
        if ($this->buffer->count() === 0) {
            return;
        }

        $this->send($this->buffer->pullRaw());
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }

    public function ping(): void
    {
        $this->transmit('PING');
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function lastDelivery(): array
    {
        return $this->lastDelivery;
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function send(array $records): void
    {
        if ($this->breaker->isOpen()) {
            $this->drop($records, "Agent at {$this->address} is unreachable (breaker open)");

            return;
        }

        try {
            $this->transmit($this->limiter->encode($records));

            $this->lastDelivery = ['delivered' => true, 'via' => 'agent', 'error' => null];
        } catch (Throwable $e) {
            $this->breaker->open('Agent send failed: '.$e->getMessage());

            $this->drop($records, 'Agent send failed: '.$e->getMessage());
        }
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function drop(array $records, string $reason): void
    {
        if ($this->fallback === null) {
            $this->lastDelivery = ['delivered' => false, 'via' => 'none', 'error' => $reason];

            return;
        }

        $error = $this->fallback->send($records);

        $this->lastDelivery = ['delivered' => $error === null, 'via' => $error === null ? 'http' : 'none', 'error' => $error === null ? $reason : "{$reason}; {$error}"];
    }

    private function transmit(string $body): void
    {
        // Silences the warning for a refused connection, an expected failure.
        set_error_handler(static fn (): bool => true);

        try {
            $stream = stream_socket_client($this->uri(), $code, $message, $this->connectTimeout);
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw new RuntimeException("Unable to reach agent at {$this->address}: {$message}");
        }

        try {
            $seconds = (int) $this->readTimeout;

            stream_set_timeout($stream, $seconds, (int) (($this->readTimeout - $seconds) * 1_000_000));

            $frame = $this->frame($body);
            $written = 0;
            $length = strlen($frame);

            while ($written < $length) {
                $bytes = @fwrite($stream, substr($frame, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Agent closed the connection during write');
                }

                $written += $bytes;
            }

            $reply = '';

            while (strlen($reply) < 4) {
                $chunk = @fread($stream, 4 - strlen($reply));

                if ($chunk === false || $chunk === '' || stream_get_meta_data($stream)['timed_out']) {
                    break;
                }

                $reply .= $chunk;
            }

            if ($reply !== '2:OK') {
                throw new RuntimeException("Unexpected agent reply [{$reply}]");
            }
        } finally {
            fclose($stream);
        }
    }

    private function frame(string $body): string
    {
        $auth = $this->signsFrames ? hash_hmac('sha256', $body, $this->token) : $this->tokenField;

        return Payload::text($body, $auth)->pull();
    }

    private function uri(): string
    {
        if (str_starts_with($this->address, 'unix://')) {
            return $this->address;
        }

        return str_starts_with($this->address, '/') ? "unix://{$this->address}" : "tcp://{$this->address}";
    }

    public static function isLoopback(string $address): bool
    {
        if (str_starts_with($address, '/') || str_starts_with($address, 'unix://')) {
            return true;
        }

        $host = parse_url(str_contains($address, '://') ? $address : "tcp://{$address}", PHP_URL_HOST);

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }
}
