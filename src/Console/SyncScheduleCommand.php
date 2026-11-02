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
    protected $signature = 'watch:sync-schedule';

    protected $description = 'Send the application schedule to the Watch server';

    public function handle(ScheduleSync $sync): int
    {
        if (! config('watch.server_url') || ! config('watch.token')) {
            $this->components->error('WATCH_SERVER_URL and WATCH_TOKEN must be set.');

            return self::FAILURE;
        }

        $result = $sync->sync();

        if ($result['status'] < 200 || $result['status'] >= 300) {
            $this->components->error($result['status'] === 0 ? 'Watch server unreachable.' : "Watch server answered {$result['status']}.");

            return self::FAILURE;
        }

        $this->components->info("Synced {$result['tasks']} scheduled tasks.");

        if (count($result['tokens']) < $result['tasks']) {
            $this->components->warn('The server returned ping tokens for '.count($result['tokens']).' of them; the rest send no heartbeats.');
        }

        return self::SUCCESS;
    }
}
