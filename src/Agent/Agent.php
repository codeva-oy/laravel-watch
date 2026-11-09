<?php

declare(strict_types=1);

namespace Codeva\Watch\Agent;

use Codeva\Watch\Transport\AgentIngest;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use RuntimeException;
use Throwable;

use function array_is_list;
use function array_slice;
use function count;
use function date;
use function deflate_add;
use function deflate_init;
use function function_exists;
use function gzdecode;
use function gzencode;
use function hash;
use function in_array;
use function intdiv;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function min;
use function strlen;
use function substr;

/**
 * Long-running process: accepts frames, batches them, gzips and forwards them
 * to the server. Anything the server cannot take right now goes to the spool.
 *
 * @internal
 */
final class Agent
{
    private const float BACKOFF_MIN = 5.0;

    private const float BACKOFF_MAX = 300.0;

    private const int COMPRESS_CHUNK_BYTES = 65_536;

    /** Statuses for which resending the same batch can never succeed. */
    private const array PERMANENT_STATUSES = [400, 413, 422];

    private string $buffer = '';

    /** @var array<int, string> batches being compressed, by id */
    private array $compressing = [];

    private int $nextCompressionId = 0;

    private ?TimerInterface $digestTimer = null;

    private ?TimerInterface $retryTimer = null;

    private ?SocketServer $socket = null;

    /** @var array<int, string> in-flight gzipped bodies */
    private array $inFlight = [];

    private int $nextRequestId = 0;

    private float $backoff = self::BACKOFF_MIN;

    private float $pausedUntil = 0.0;

    private ?float $lastSuccessAt = null;

    private bool $stopping = false;

    private readonly string $tokenHash;

    private readonly bool $requireHmac;

    private readonly HttpPoster $http;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly AgentConfig $config,
        private readonly Spool $spool,
    ) {
        $this->tokenHash = substr(hash('xxh128', $config->token), 0, 7);
        $this->requireHmac = ! AgentIngest::isLoopback($config->listen);
        $this->http = new HttpPoster($loop, $config->requestTimeout);
    }

    public function start(): void
    {
        if ($this->requireHmac) {
            $this->log('WARN', "Listening on non-loopback address {$this->config->listen}: every frame must carry the full token HMAC");
        }

        $this->socket = new SocketServer($this->config->listen, [], $this->loop);
        $this->socket->on('connection', $this->accept(...));
        $this->socket->on('error', fn (Throwable $e) => $this->log('ERROR', 'Server error: '.$e->getMessage()));

        if (function_exists('pcntl_signal')) {
            $this->loop->addSignal(SIGTERM, $this->shutdown(...));
            $this->loop->addSignal(SIGINT, $this->shutdown(...));
        }

        $this->scheduleRetry(0.0);

        $this->log('INFO', "Agent listening on {$this->config->listen}, protocol ".Frame::VERSION);
    }

    private function accept(ConnectionInterface $connection): void
    {
        $frame = new Frame;

        $connection->on('data', function (string $chunk) use ($connection, $frame): void {
            if ($frame->complete) {
                return;
            }

            $frame->append($chunk);

            if ($frame->tooLarge) {
                $this->log('ERROR', 'Frame over the size limit rejected');
                $connection->close();
            } elseif ($frame->complete) {
                $this->handle($frame, $connection);
            }
        });

        $connection->on('error', fn (Throwable $e) => $this->log('DEBUG', 'Connection error: '.$e->getMessage()));
    }

    private function handle(Frame $frame, ConnectionInterface $connection): void
    {
        if (! $frame->versionIsValid()) {
            $this->log('ERROR', "Rejected frame with protocol [{$frame->version}]; this agent speaks ".Frame::VERSION.'. Upgrade the agent.');
            $connection->close();

            return;
        }

        if (! $frame->isAuthorised($this->tokenHash, $this->config->token, $this->requireHmac)) {
            $this->log('ERROR', 'Rejected frame with a bad token. Check WATCH_TOKEN on the app and the agent.');
            $connection->close();

            return;
        }

        if ($frame->body === 'STATUS') {
            $status = (string) json_encode($this->status());
            $connection->end(strlen($status).':'.$status);

            return;
        }

        if ($frame->body !== 'PING') {
            $this->write($frame->body);
        }

        $connection->end('2:OK');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'protocol' => Frame::VERSION,
            'buffer_bytes' => strlen($this->buffer),
            'spool_bytes' => $this->spool->bytes(),
            'in_flight' => count($this->inFlight),
            'last_success_at' => $this->lastSuccessAt,
        ];
    }

    private function write(string $jsonArray): void
    {
        $records = substr($jsonArray, 1, -1);

        if ($records === '') {
            return;
        }

        if ($this->buffer !== '' && strlen($this->buffer) + strlen($records) > $this->config->maxBufferBytes) {
            $this->digest();
        }

        $this->buffer .= ($this->buffer === '' ? '' : ',').$records;

        if (strlen($this->buffer) >= $this->config->maxBufferBytes) {
            $this->digest();
        } else {
            $this->digestTimer ??= $this->loop->addTimer($this->config->maxBufferSeconds, function (): void {
                $this->digestTimer = null;
                $this->digest();
            });
        }
    }

    private function digest(): void
    {
        if ($this->digestTimer !== null) {
            $this->loop->cancelTimer($this->digestTimer);
            $this->digestTimer = null;
        }

        if ($this->buffer === '') {
            return;
        }

        $this->compress("[{$this->buffer}]");
        $this->buffer = '';
    }

    /**
     * Compresses a piece per loop tick. This process holds every app worker's
     * connection, and one gzencode() of a full buffer stalls them all for the
     * length of it, which is what the benchmark's p99 showed.
     */
    private function compress(string $json): void
    {
        $id = $this->nextCompressionId++;
        $this->compressing[$id] = $json;

        $context = deflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new RuntimeException('zlib could not start a gzip stream');
        }

        $offset = 0;
        $gzipped = '';

        $step = function () use (&$step, $id, $json, $context, &$offset, &$gzipped): void {
            if ($this->stopping) {
                return;
            }

            $finished = $offset + self::COMPRESS_CHUNK_BYTES >= strlen($json);

            $gzipped .= (string) deflate_add($context, substr($json, $offset, self::COMPRESS_CHUNK_BYTES), $finished ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            $offset += self::COMPRESS_CHUNK_BYTES;

            if ($finished) {
                unset($this->compressing[$id]);
                $this->deliver($gzipped);
            } else {
                $this->loop->futureTick($step);
            }
        };

        $this->loop->futureTick($step);
    }

    private function deliver(string $gzipped): void
    {
        if (microtime(true) < $this->pausedUntil || count($this->inFlight) >= $this->config->concurrentRequests) {
            $this->spool->put($gzipped);
            $this->scheduleRetry($this->backoff);

            return;
        }

        $this->post($gzipped, onSuccess: null, onFailure: function () use ($gzipped): void {
            $this->spool->put($gzipped);
        }, onRejected: function (int $status) use ($gzipped): void {
            $this->reject($gzipped, $status);
        });
    }

    /**
     * Handles a batch the server will never accept as is. A batch that is too
     * large is split in half and sent again; anything else is dropped, so it
     * cannot block every later batch in the spool.
     */
    private function reject(string $gzipped, int $status): void
    {
        $records = $status === 413 ? json_decode((string) gzdecode($gzipped), true) : null;

        if (is_array($records) && array_is_list($records) && count($records) > 1) {
            $this->log('WARNING', 'Ingest rejected a batch of '.count($records).' records as too large; splitting it.');

            $half = intdiv(count($records), 2);

            $this->deliver((string) gzencode((string) json_encode(array_slice($records, 0, $half))));
            $this->deliver((string) gzencode((string) json_encode(array_slice($records, $half))));

            return;
        }

        $this->log('ERROR', "Ingest rejected a batch with HTTP {$status}; dropping it.");
    }

    /**
     * @param  (callable(): void)|null  $onSuccess
     * @param  callable(): void  $onFailure
     * @param  callable(int): void  $onRejected
     */
    private function post(string $gzipped, ?callable $onSuccess, callable $onFailure, callable $onRejected): void
    {
        $id = $this->nextRequestId++;
        $this->inFlight[$id] = $gzipped;

        $this->http->post($this->config->serverUrl.'/api/ingest', [
            'Authorization' => "Bearer {$this->config->token}",
            'Content-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
            'X-Watch-Server' => $this->config->serverName,
            'X-Watch-Agent-Version' => $this->config->version,
        ], $gzipped)->then(function (int $status) use ($id, $onSuccess): void {
            unset($this->inFlight[$id]);

            $this->lastSuccessAt = microtime(true);
            $this->backoff = self::BACKOFF_MIN;
            $this->log('DEBUG', 'Ingest accepted: '.$status);

            if ($onSuccess !== null) {
                $onSuccess();
            }
        }, function (Throwable $e) use ($id, $onFailure, $onRejected): void {
            unset($this->inFlight[$id]);

            if ($e instanceof HttpStatusException && in_array($e->status, self::PERMANENT_STATUSES, true)) {
                $onRejected($e->status);

                return;
            }

            $this->noteFailure($e);
            $onFailure();
            $this->scheduleRetry($this->backoff);
            $this->backoff = min($this->backoff * 2, self::BACKOFF_MAX);
        });
    }

    private function noteFailure(Throwable $e): void
    {
        if ($e instanceof HttpStatusException && $e->status === 429) {
            $retryAfter = max(1, (int) $e->retryAfter);

            $this->pausedUntil = microtime(true) + $retryAfter;
            $this->backoff = max($this->backoff, (float) $retryAfter);
        }

        $this->log('ERROR', 'Ingest failed, spooling: '.$e->getMessage());
    }

    private function scheduleRetry(float $delay): void
    {
        if ($this->retryTimer !== null || $this->stopping) {
            return;
        }

        $this->retryTimer = $this->loop->addTimer($delay, function (): void {
            $this->retryTimer = null;
            $this->retryOldest();
        });
    }

    private function retryOldest(): void
    {
        $oldest = $this->spool->oldest();

        if ($oldest === null || $this->stopping) {
            return;
        }

        [$file, $gzipped] = $oldest;

        $wait = $this->pausedUntil - microtime(true);

        if ($wait > 0 || count($this->inFlight) >= $this->config->concurrentRequests) {
            $this->scheduleRetry(max($wait, 1.0));

            return;
        }

        $this->post($gzipped, onSuccess: function () use ($file): void {
            $this->spool->delete($file);
            $this->scheduleRetry(0.0);
        }, onFailure: static function (): void {
            // The file is still on disk; the shared failure path reschedules.
        }, onRejected: function (int $status) use ($file, $gzipped): void {
            $this->spool->delete($file);
            $this->reject($gzipped, $status);
            $this->scheduleRetry(0.0);
        });
    }

    /** Flushes what it holds to the spool and stops, as on SIGTERM. */
    public function stop(): void
    {
        $this->shutdown();
    }

    private function shutdown(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        $this->socket?->close();

        foreach ([$this->digestTimer, $this->retryTimer] as $timer) {
            if ($timer !== null) {
                $this->loop->cancelTimer($timer);
            }
        }

        if ($this->buffer !== '') {
            $this->spool->put((string) gzencode("[{$this->buffer}]"));
            $this->buffer = '';
        }

        foreach ($this->compressing as $json) {
            $this->spool->put((string) gzencode($json));
        }

        $this->compressing = [];

        $this->log('INFO', 'Buffer spooled, shutting down');

        // In-flight requests get their normal timeout to finish and spool on failure.
        $check = function () use (&$check): void {
            if ($this->inFlight === []) {
                $this->loop->stop();

                return;
            }

            $this->loop->addTimer(0.05, $check);
        };

        $check();
    }

    private function log(string $level, string $message): void
    {
        if ($level === 'DEBUG' && ! $this->config->verbose) {
            return;
        }

        echo date('Y-m-d H:i:s')." [{$level}] {$message}".PHP_EOL;
    }
}
