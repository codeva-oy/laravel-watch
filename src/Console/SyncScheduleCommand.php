<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use Codeva\Watch\Support\ScheduleSync;
use Illuminate\Console\Command;

use function config;
use function count;

/** @internal */
final class SyncScheduleCommand extends Command
{
    protected $signature = 'watch:sync-schedule {--strict : Exit with an error when the sync fails, instead of only warning}';

    protected $description = 'Send the application schedule to the Watch server';

    public function handle(ScheduleSync $sync): int
    {
        // Runs in deploy hooks: monitoring must never fail a deploy unless asked to.
        if (! config('watch.server_url') || ! config('watch.token')) {
            return $this->failed('WATCH_SERVER_URL and WATCH_TOKEN must be set; no heartbeats were registered.');
        }

        $result = $sync->sync();

        if ($result['status'] < 200 || $result['status'] >= 300) {
            return $this->failed(($result['status'] === 0 ? 'Watch server unreachable.' : "Watch server answered {$result['status']}.").' Heartbeats sync on the next deploy.');
        }

        $this->components->info("Synced {$result['tasks']} scheduled tasks.");

        if (count($result['tokens']) < $result['tasks']) {
            $this->components->warn('The server returned ping tokens for '.count($result['tokens']).' of them; the rest send no heartbeats.');
        }

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
