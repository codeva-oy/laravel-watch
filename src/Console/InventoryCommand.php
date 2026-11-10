<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use const JSON_PRETTY_PRINT;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\Support\Inventory;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

use function config;
use function count;
use function hash;
use function in_array;

/** @internal */
final class InventoryCommand extends Command
{
    protected $signature = 'watch:inventory
        {--show : Print the inventory as JSON instead of sending it}
        {--dry-run : Same as --show}
        {--strict : Exit with an error when sending fails, instead of only warning}
        {--if-changed : Only send when the inventory differs from the last one sent, as after a deploy}';

    protected $description = 'Send the PHP, Laravel, OS and package versions to the Watch server';

    /** Cache stores that forget between runs: with them every check would look like a change. */
    private const array FORGETFUL_STORES = ['array', 'null'];

    /**
     * @param  Core<CommandState>  $core
     */
    public function handle(Inventory $inventory, Cache $cache, Core $core): int
    {
        if ($this->option('if-changed') && in_array(config('cache.default'), self::FORGETFUL_STORES, true)) {
            $core->dontSample();

            return self::SUCCESS;
        }

        try {
            $payload = $inventory->collect();
        } catch (Throwable $e) {
            return $this->failed('Could not read the inventory: '.$e->getMessage());
        }

        $key = 'watch:inventory:'.hash('xxh128', $payload['server']);
        $fingerprint = hash('xxh128', Inventory::encode($payload));

        // Checked every few minutes: unchanged is the normal case, and not worth a record.
        if ($this->option('if-changed') && $this->remembered($cache, $key) === $fingerprint) {
            $core->dontSample();

            return self::SUCCESS;
        }

        if ($this->option('show') || $this->option('dry-run')) {
            $this->line(Inventory::encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        // Runs in deploy hooks and the scheduler: monitoring must never fail either unless asked to.
        if (! config('watch.server_url') || ! config('watch.token')) {
            return $this->failed('WATCH_SERVER_URL and WATCH_TOKEN must be set; no inventory was sent.');
        }

        $status = $inventory->send($payload);

        if ($status < 200 || $status >= 300) {
            return $this->failed(($status === 0 ? 'Watch server unreachable.' : "Watch server answered {$status}.").' The inventory is sent again tomorrow and on the next deploy.');
        }

        $this->remember($cache, $key, $fingerprint);

        $this->components->info("Sent inventory: PHP {$payload['php']['version']}, Laravel {$payload['framework']['version']}, ".count($payload['composer']).' Composer and '.count($payload['npm']).' npm packages.');

        return self::SUCCESS;
    }

    private function remembered(Cache $cache, string $key): mixed
    {
        try {
            return $cache->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function remember(Cache $cache, string $key, string $fingerprint): void
    {
        try {
            $cache->forever($key, $fingerprint);
        } catch (Throwable) {
            // Without the cache the next check sends again, which is harmless.
        }
    }

    private function failed(string $message): int
    {
        if ($this->option('strict')) {
            $this->components->error($message);

            return self::FAILURE;
        }

        $this->components->warn($message);

        return self::SUCCESS;
    }
}
