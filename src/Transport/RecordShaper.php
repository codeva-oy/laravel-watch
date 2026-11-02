<?php

declare(strict_types=1);

namespace Codeva\Watch\Transport;

use Codeva\Watch\LazyValue;

use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function explode;
use function hash;
use function hash_hmac;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strcut;
use function parse_url;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * The single privacy gate. Every record passes through here before it enters
 * the buffer, so the wire format is the one in the build spec no matter what
 * the forked sensors produce: nothing identifying leaves the application.
 *
 * @internal
 */
final class RecordShaper
{
    private const int SQL_BYTES = 10_240;

    private const int MESSAGE_BYTES = 4_096;

    private const int URL_BYTES = 2_048;

    private const int MAX_TRACE_FRAMES = 50;

    /** Types the forked sensors emit that have a different name on the wire. */
    private const array TYPES = [
        'cache-event' => 'cache_event',
        'outgoing-request' => 'outgoing_request',
        'scheduled-task' => 'scheduled_task',
        'job-attempt' => 'job',
        'queued-job' => 'job',
    ];

    private const array SOURCES = ['request' => 'request', 'command' => 'command', 'job' => 'job', 'scheduled_task' => 'schedule'];

    /** Fields that may hold personal data and are removed from every record. */
    private const array NEVER_SEND = ['ip', 'headers', 'subject', 'to_addresses', 'extra', 'payload', 'cookies', 'session'];

    /** What survives on the execution record of an unsampled exception. */
    private const array MINIMAL_FIELDS = [
        'v' => 1, 't' => 1, 'timestamp' => 1, 'deploy' => 1, 'server' => 1, 'trace_id' => 1, 'execution_id' => 1,
        'execution_source' => 1, 'execution_preview' => 1, 'environment' => 1, 'user' => 1, 'sample_rate' => 1, 'group_hash' => 1,
        'method' => 1, 'url' => 1, 'route_name' => 1, 'route_path' => 1, 'status_code' => 1, 'duration' => 1,
        'name' => 1, 'exit_code' => 1, 'status' => 1, 'cron' => 1, 'attempt' => 1, 'queue' => 1, 'connection' => 1,
    ];

    /** Applied in order: the quoted forms first, numbers last so they cannot split the others. */
    private const array MESSAGE_PATTERNS = [
        '/(?<![\w\\\\])\'[^\']*\'|"[^"]*"|`[^`]*`/' => '{str}',
        '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/' => '{email}',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/\b0x[0-9a-f]+\b|\b(?=[a-f]*\d)[0-9a-f]{8,}\b/i' => '{hex}',
        '/\d+(?:\.\d+)*/' => '{num}',
    ];

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly string $token,
        private readonly SampleRate $sampleRate = new SampleRate,
        private readonly float $exceptionRate = 1.0,
        private readonly string $environment = '',
        private readonly bool $hashCacheKeys = true,
        private readonly bool $keepQueryStrings = false,
    ) {
        //
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>|null null when the record must not be sent
     */
    public function shape(array $record): ?array
    {
        $type = $record['t'] ?? null;

        // The user record carries names and emails; it never leaves the app.
        if (! is_string($type) || $type === 'user') {
            return null;
        }

        foreach (self::NEVER_SEND as $field) {
            unset($record[$field]);
        }

        $record['t'] = self::TYPES[$type] ?? $type;
        $record['v'] = 1;

        if (is_string($record['exception_preview'] ?? null)) {
            $record['exception_preview'] = $this->withoutBindings($record['exception_preview']);
        }

        if ($type === 'queued-job') {
            $record['status'] = 'queued';
        }

        if (isset($record['_group'])) {
            $record['group_hash'] = $record['_group'];
            unset($record['_group']);
        }

        // Top-level records describe the execution itself and omit its source.
        $record['execution_source'] ??= self::SOURCES[$record['t']] ?? null;

        $record['environment'] = $this->environment;
        $record['user'] = $this->hashUser($record['user'] ?? '');
        $record['sample_rate'] = $record['t'] === 'exception' ? $this->exceptionRate : $this->sampleRate;

        if (isset($record['_minimal'])) {
            return ['minimal' => true, ...array_intersect_key($this->withUrl($record), self::MINIMAL_FIELDS)];
        }

        return match ($record['t']) {
            'request' => $this->request($record),
            'query' => $this->capped($record, 'sql', self::SQL_BYTES),
            'exception' => $this->exception($record),
            'cache_event' => $this->cacheEvent($record),
            'outgoing_request' => $this->outgoing($record),
            'mail' => $this->mail($record),
            'log' => $this->log($record),
            'command' => $this->command($record),
            default => $record,
        };
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function request(array $record): array
    {
        return $this->withUrl($record);
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function outgoing(array $record): array
    {
        $url = is_string($record['url'] ?? null) ? $record['url'] : '';
        $method = is_string($record['method'] ?? null) ? $record['method'] : '';
        $host = is_string($record['host'] ?? null) ? $record['host'] : '';

        // The path is normalised on the wire too: ids in it are as sensitive
        // as a query string, and it is all the server needs. No url is sent.
        $path = mb_strcut($this->normalisePath((string) parse_url($url, PHP_URL_PATH)), 0, self::URL_BYTES);

        $record['path'] = $path;
        $record['group_hash'] = hash('xxh128', $method.$host.$path);
        $record['status'] = $record['status_code'] ?? null;

        unset($record['url'], $record['status_code']);

        return $record;
    }

    private function normalisePath(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => preg_match('/^\d+$/', $segment) === 1 || preg_match(self::UUID, $segment) === 1 ? '{id}' : $segment,
            explode('/', $path === '' ? '/' : $path),
        ));
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function withUrl(array $record): array
    {
        $url = is_string($record['url'] ?? null) ? $record['url'] : '';

        if (! $this->keepQueryStrings) {
            $query = strpos($url, '?');
            $url = $query === false ? $url : substr($url, 0, $query);
        }

        $record['url'] = mb_strcut($url, 0, self::URL_BYTES);

        return $record;
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function exception(array $record): array
    {
        $record['message'] = $this->withoutBindings(is_string($record['message'] ?? null) ? $record['message'] : '');

        $trace = is_string($record['trace'] ?? null) ? json_decode($record['trace'], true) : null;
        $frames = is_array($trace) ? array_values($trace) : [];

        // From the full trace: the application frame may be past the cap.
        $record['fingerprint'] = $record['_fingerprint'] ?? $this->fingerprint($record, $frames);

        unset($record['_fingerprint'], $record['group_hash']);

        if (is_array($trace)) {
            $record['trace'] = (string) json_encode(
                array_map($this->frame(...), array_slice($frames, 0, self::MAX_TRACE_FRAMES)),
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $this->capped($record, 'message', self::MESSAGE_BYTES);
    }

    /**
     * Laravel interpolates the bindings into a QueryException's message, and
     * that message reaches the request preview and the log channel as well.
     */
    private function withoutBindings(string $message): string
    {
        return (string) preg_replace('/, SQL: .*$/s', ', SQL: [hidden]', $message);
    }

    /**
     * @param  array<mixed>  $record
     * @param  list<mixed>  $frames
     */
    private function fingerprint(array $record, array $frames): string
    {
        $class = is_string($record['class'] ?? null) ? $record['class'] : '';

        return hash('xxh128', $class.$this->applicationFrame($record, $frames).self::normaliseMessage($record['message']));
    }

    /**
     * @param  array<mixed>  $record
     * @param  list<mixed>  $frames
     */
    private function applicationFrame(array $record, array $frames): string
    {
        foreach ($frames as $frame) {
            $file = is_array($frame) && is_string($frame['file'] ?? null) ? $frame['file'] : '';

            if ($file !== '' && ! self::isVendor($file) && ! str_starts_with($file, '[')) {
                return $file;
            }
        }

        // Fatal errors have no trace, only their own location.
        $file = is_string($record['file'] ?? null) ? $record['file'] : '';

        if ($frames === [] && $file !== '' && ! self::isVendor($file)) {
            return $file.':'.(is_int($record['line'] ?? null) ? $record['line'] : 0);
        }

        return '';
    }

    public static function normaliseMessage(string $message): string
    {
        return (string) preg_replace(array_keys(self::MESSAGE_PATTERNS), self::MESSAGE_PATTERNS, $message);
    }

    /**
     * @return array<mixed>
     */
    private function frame(mixed $frame): array
    {
        if (! is_array($frame)) {
            return [];
        }

        if (is_string($frame['source'] ?? null)) {
            $frame['source'] = (string) preg_replace('/\((?:[^()]|\(closed\))*\)$/', '()', $frame['source']);
        }

        if (is_string($frame['file'] ?? null) && self::isVendor($frame['file'])) {
            $frame['code'] = null;
        }

        return $frame;
    }

    private static function isVendor(string $file): bool
    {
        return str_starts_with($file, 'vendor/') || str_contains($file, '/vendor/');
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function cacheEvent(array $record): array
    {
        if ($this->hashCacheKeys && is_string($record['key'] ?? null)) {
            $record['key'] = hash('xxh128', $record['key']);
        }

        return $record;
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function mail(array $record): array
    {
        $record['recipients'] = (int) ($record['to'] ?? 0) + (int) ($record['cc'] ?? 0) + (int) ($record['bcc'] ?? 0);

        unset($record['to'], $record['cc'], $record['bcc']);

        return $record;
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function log(array $record): array
    {
        $context = is_string($record['context'] ?? null) ? json_decode($record['context'], true) : null;

        $record['context'] = is_array($context) ? array_keys($context) : [];
        $record['message'] = $this->withoutBindings(is_string($record['message'] ?? null) ? $record['message'] : '');

        return $this->capped($record, 'message', self::MESSAGE_BYTES);
    }

    /**
     * The full command line can hold secrets passed as arguments.
     *
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function command(array $record): array
    {
        unset($record['command']);

        return $record;
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function capped(array $record, string $field, int $bytes): array
    {
        if (is_string($record[$field] ?? null) && strlen($record[$field]) > $bytes) {
            $record[$field] = mb_strcut($record[$field], 0, $bytes);
        }

        return $record;
    }

    /**
     * The id may be lazy because auth is often unresolved when a record is
     * written; it has to stay lazy so it hashes the id known at flush time.
     *
     * @return string|LazyValue<string>
     */
    private function hashUser(mixed $user): string|LazyValue
    {
        if ($user instanceof LazyValue) {
            return new LazyValue(fn (): string => $this->hashId($user->jsonSerialize()));
        }

        return $this->hashId($user);
    }

    private function hashId(mixed $id): string
    {
        if ((! is_string($id) && ! is_int($id)) || (string) $id === '') {
            return '';
        }

        return substr(hash_hmac('sha256', (string) $id, $this->token), 0, 32);
    }
}
