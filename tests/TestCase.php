<?php

namespace Codeva\Watch\Tests;

use Codeva\Watch\WatchServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

use function putenv;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [WatchServiceProvider::class];
    }

    protected function setUp(): void
    {
        // The provider reads its config while registering, so env it must be.
        putenv('WATCH_TRANSPORT=null');
        putenv('WATCH_TOKEN=secret');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('WATCH_TRANSPORT');
        putenv('WATCH_TOKEN');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }
}
