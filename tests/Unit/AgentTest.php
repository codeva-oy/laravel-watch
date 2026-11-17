<?php

use Codeva\Watch\Agent\Agent;
use Codeva\Watch\Agent\AgentConfig;
use Codeva\Watch\Agent\Spool;
use React\EventLoop\StreamSelectLoop;

it('frees each batch once it is compressed, so a long-running agent stays within its memory', function () {
    $dir = sys_get_temp_dir().'/watch-agent-'.uniqid();
    $loop = new StreamSelectLoop;
    $agent = new Agent($loop, new AgentConfig('secret', 'http://127.0.0.1:1', maxBufferBytes: 1_000_000), new Spool($dir, 1_000_000_000));
    $inside = fn (Closure $callback): mixed => Closure::bind($callback, $agent, Agent::class)();

    // Paused, so every compressed batch goes to the spool instead of the network.
    $inside(function (): void {
        $this->pausedUntil = INF;
    });

    $batch = function () use ($inside, $loop): void {
        $records = '['.str_repeat('{"t":"log","m":"'.bin2hex(random_bytes(8)).'"},', 40_000).'{}]';
        $inside(fn () => $this->write($records));

        $loop->addPeriodicTimer(0.001, function ($timer) use ($inside, $loop): void {
            if ($inside(fn () => $this->compressing) === []) {
                $loop->cancelTimer($timer);
                $loop->stop();
            }
        });
        $loop->run();
    };

    gc_disable();

    try {
        $batch();
        $before = memory_get_usage();

        foreach (range(1, 20) as $i) {
            $batch();
        }

        expect(memory_get_usage() - $before)->toBeLessThan(5_000_000);
    } finally {
        gc_enable();
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
    }
});
