<?php

function freePort(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);

    return $port;
}

function portOpen(int $port): bool
{
    set_error_handler(static fn () => true);

    try {
        $socket = fsockopen('127.0.0.1', $port, timeout: 0.2);
    } finally {
        restore_error_handler();
    }

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}

function waitUntil(callable $check, float $seconds = 8.0): bool
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        if ($check()) {
            return true;
        }
        usleep(50_000);
    }

    return false;
}

function accepted(string $dir): array
{
    $records = [];
    foreach (glob("{$dir}/accepted-*") as $file) {
        array_push($records, ...json_decode(file_get_contents($file), true));
    }

    return $records;
}

function stubRequests(string $dir): array
{
    return is_file("{$dir}/requests")
        ? array_map(fn ($line) => json_decode($line, true), file("{$dir}/requests", FILE_IGNORE_NEW_LINES))
        : [];
}

function startStub(string $dir, int $port)
{
    $stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../stubs/server.php'], [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $p, null, ['STUB_DIR' => $dir]);
    expect(waitUntil(fn () => portOpen($port)))->toBeTrue();

    return $stub;
}
