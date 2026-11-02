<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use Codeva\Watch\Transport\AgentStatus;
use Codeva\Watch\Transport\AgentUnreachable;
use Illuminate\Console\Command;

use function config;
use function date;
use function is_numeric;
use function is_scalar;
use function number_format;

/** @internal */
final class StatusCommand extends Command
{
    protected $signature = 'watch:status';

    protected $description = 'Show the agent buffer, spool and last successful send';

    public function handle(): int
    {
        $address = (string) config('watch.agent_address', '127.0.0.1:2408');

        try {
            $status = (new AgentStatus($address, (string) config('watch.token', '')))->fetch();
        } catch (AgentUnreachable $e) {
            $this->components->error("Agent not reachable at {$address}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($status === null) {
            $this->components->error('Agent rejected the request. Check WATCH_TOKEN and the agent log.');

            return self::FAILURE;
        }

        $number = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $lastSuccess = $number($status['last_success_at'] ?? null);

        $this->components->twoColumnDetail('Protocol', is_scalar($status['protocol'] ?? null) ? (string) $status['protocol'] : '');
        $this->components->twoColumnDetail('Buffer', number_format($number($status['buffer_bytes'] ?? null)).' bytes');
        $this->components->twoColumnDetail('Spool', number_format($number($status['spool_bytes'] ?? null)).' bytes');
        $this->components->twoColumnDetail('In flight', (string) $number($status['in_flight'] ?? null));
        $this->components->twoColumnDetail('Last successful send', $lastSuccess > 0 ? date('Y-m-d H:i:s', $lastSuccess) : 'never');

        return self::SUCCESS;
    }
}
