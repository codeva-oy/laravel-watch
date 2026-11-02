<?php

declare(strict_types=1);

namespace Codeva\Watch\Agent;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use RuntimeException;
use Throwable;

use function explode;
use function in_array;
use function parse_url;
use function preg_match;
use function str_contains;
use function strlen;
use function strtolower;

/**
 * A minimal non-blocking HTTP/1.1 POST over react/socket: one request per
 * connection, enough for the agent's ingest calls.
 *
 * It replaces react/http, which pins psr/http-message 1.x and so cannot be
 * installed next to a current Laravel app.
 */
final class HttpPoster
{
    /** Responses are only read for the status line and headers; bodies are small. */
    private const int MAX_RESPONSE_BYTES = 65_536;

    private readonly Connector $connector;

    public function __construct(private readonly LoopInterface $loop, private readonly float $timeout)
    {
        $this->connector = new Connector(['timeout' => $timeout], $loop);
    }

    /**
     * Resolves with the status code for 2xx responses; rejects with HttpStatusException otherwise.
     *
     * @param  array<string, string>  $headers
     * @return PromiseInterface<int>
     */
    public function post(string $url, array $headers, string $body): PromiseInterface
    {
        /** @var Deferred<int> $deferred */
        $deferred = new Deferred;

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            $deferred->reject(new RuntimeException("Invalid server URL: {$url}"));

            return $deferred->promise();
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $hostHeader = $host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        $request = "POST {$path} HTTP/1.1\r\nHost: {$hostHeader}\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        $request .= "\r\n".$body;

        /** @var ConnectionInterface|null $connection Set once connected, so a timeout can close it. */
        $connection = null;
        $timer = $this->loop->addTimer($this->timeout, function () use ($deferred, &$connection): void {
            $connection?->close();
            $deferred->reject(new RuntimeException("Request timed out after {$this->timeout}s"));
        });
        $settle = function (?Throwable $error, int $status = 0) use ($deferred, $timer): void {
            $this->loop->cancelTimer($timer);
            $error === null ? $deferred->resolve($status) : $deferred->reject($error);
        };

        $target = ($scheme === 'https' ? 'tls' : 'tcp')."://{$host}:{$port}";

        $this->connector->connect($target)->then(function (ConnectionInterface $conn) use ($request, $settle, &$connection): void {
            $connection = $conn;
            $response = '';

            $conn->on('data', function (string $chunk) use (&$response, $conn): void {
                $response .= $chunk;

                // The head is all we need; stop reading once it is complete.
                if (str_contains($response, "\r\n\r\n") || strlen($response) > self::MAX_RESPONSE_BYTES) {
                    $conn->close();
                }
            });
            $conn->on('close', function () use (&$response, $settle): void {
                try {
                    $settle(...self::parse($response));
                } catch (Throwable $e) {
                    $settle($e);
                }
            });
            $conn->on('error', fn (Throwable $e) => $settle($e));

            $conn->write($request);
        }, fn (Throwable $e) => $settle($e));

        return $deferred->promise();
    }

    /**
     * @return array{0: ?Throwable, 1: int}
     */
    private static function parse(string $response): array
    {
        if (! preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $response, $match)) {
            return [new RuntimeException('No HTTP response from server'), 0];
        }

        $status = (int) $match[1];

        if ($status >= 200 && $status < 300) {
            return [null, $status];
        }

        $head = explode("\r\n\r\n", $response, 2)[0];
        $retryAfter = preg_match('/^Retry-After:\s*(\d+)/mi', $head, $retry) ? (int) $retry[1] : null;

        return [new HttpStatusException($status, $retryAfter), $status];
    }
}
