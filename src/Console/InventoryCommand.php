<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use const JSON_PRETTY_PRINT;

use Codeva\Watch\Support\Inventory;
use Illuminate\Console\Command;
use Throwable;

use function config;
use function count;

/** @internal */
final class InventoryCommand extends Command
{
    protected $signature = 'watch:inventory
        {--show : Print the inventory as JSON instead of sending it}
        {--dry-run : Same as --show}
        {--strict : Exit with an error when sending fails, instead of only warning}';

    protected $description = 'Send the PHP, Laravel, OS and package versions to the Watch server';

    public function handle(Inventory $inventory): int
    {
        try {
            $payload = $inventory->collect();
        } catch (Throwable $e) {
            return $this->failed('Could not read the inventory: '.$e->getMessage());
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

        $this->components->info("Sent inventory: PHP {$payload['php']['version']}, Laravel {$payload['framework']['version']}, ".count($payload['composer']).' Composer and '.count($payload['npm']).' npm packages.');

        return self::SUCCESS;
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
