<?php

use Codeva\Watch\Health\BuiltInChecks;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;
use Spatie\Health\HealthServiceProvider;

function health(object $t): array
{
    return $t->getJson('/watch/health', ['X-Watch-Health-Secret' => 'hs'])->assertOk()->json();
}

function result(array $health, string $name): array
{
    return collect($health['checkResults'])->firstWhere('name', $name);
}

beforeEach(function () {
    config(['watch.health_secret' => 'hs', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:'], 'cache.default' => 'array']);
});

it('answers 404 without a configured secret, even when the header is empty', function () {
    config(['watch.health_secret' => null]);

    $this->getJson('/watch/health')->assertNotFound();
    $this->getJson('/watch/health', ['X-Watch-Health-Secret' => ''])->assertNotFound();
});

it('answers 404 for a wrong secret', function () {
    $this->getJson('/watch/health', ['X-Watch-Health-Secret' => 'no'])->assertNotFound();
});

it('reports every built-in check in the Oh Dear format', function () {
    $health = health($this);

    expect($health['finishedAt'])->toBeInt()
        ->and($health['checkResults'])->toHaveCount(7)
        ->and(result($health, 'Database')['status'])->toBe('ok')
        ->and(result($health, 'Cache')['status'])->toBe('ok');
});

it('fails debug mode in production, warns elsewhere', function () {
    config(['app.debug' => true, 'app.env' => 'production']);
    expect(result(health($this), 'DebugMode')['status'])->toBe('failed');

    config(['app.env' => 'staging']);
    expect(result(health($this), 'DebugMode')['status'])->toBe('warning');

    config(['app.debug' => false]);
    expect(result(health($this), 'DebugMode')['status'])->toBe('ok');
});

it('reports a crash without leaking the message', function () {
    config(['database.default' => 'mysql', 'database.connections.mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'db', 'username' => 'secret-user', 'password' => '']]);

    $database = result(health($this), 'Database');

    expect($database['status'])->toBe('crashed')
        ->and($database['notificationMessage'])->not->toContain('secret-user')->not->toContain('127.0.0.1');
});

it('warns and fails on thresholds', function () {
    config(['watch.health.disk_percent_warning' => 0, 'watch.health.disk_percent_failed' => 101]);
    expect(result(health($this), 'UsedDiskSpace')['status'])->toBe('warning');

    config(['watch.health.disk_percent_failed' => 0]);
    expect(result(health($this), 'UsedDiskSpace')['status'])->toBe('failed');
});

it('skips the queue size for the sync queue and measures a real one', function () {
    config(['queue.default' => 'sync']);
    expect(result(health($this), 'QueueSize')['status'])->toBe('skipped');
});

it('follows the scheduler heartbeat', function () {
    expect(result(health($this), 'Schedule')['status'])->toBe('warning');

    cache()->forever(BuiltInChecks::SCHEDULER_KEY, microtime(true));
    expect(result(health($this), 'Schedule')['status'])->toBe('ok');

    cache()->forever(BuiltInChecks::SCHEDULER_KEY, microtime(true) - 600);
    expect(result(health($this), 'Schedule')['status'])->toBe('failed');
});

it('reuses spatie checks when the app registered some', function () {
    app()->register(HealthServiceProvider::class);

    Health::checks([
        new class extends Check
        {
            public function run(): Result
            {
                return Result::make()->failed('Boom')->shortSummary('Bad')->meta(['n' => 1]);
            }
        },
        (new class extends Check
        {
            public function run(): Result
            {
                return Result::make()->ok();
            }
        })->name('Skipped')->if(false),
    ]);

    $health = health($this);

    expect($health['checkResults'])->toHaveCount(2)
        ->and($health['checkResults'][0])->toMatchArray(['status' => 'failed', 'notificationMessage' => 'Boom', 'shortSummary' => 'Bad', 'meta' => ['n' => 1]])
        ->and($health['checkResults'][1])->toMatchArray(['name' => 'Skipped', 'status' => 'skipped']);
});

it('falls back to the built-in checks when spatie is installed with none', function () {
    app()->register(HealthServiceProvider::class);
    Health::clearChecks();

    expect(health($this)['checkResults'])->toHaveCount(7);
});
