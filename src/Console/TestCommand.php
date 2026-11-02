<?php

declare(strict_types=1);

namespace Codeva\Watch\Console;

use Codeva\Watch\Core;
use Codeva\Watch\State\CommandState;
use Codeva\Watch\State\RequestState;
use Codeva\Watch\Transport\AgentStatus;
use Codeva\Watch\Transport\AgentUnreachable;
use Codeva\Watch\Transport\CircuitBreaker;
use Illuminate\Console\Command;
use RuntimeException;

use function config;
use function is_numeric;
use function microtime;
use function usleep;

/** @internal */
final class TestCommand extends Command
{
    protected $signature = 'watch:test {--wait=15 : Seconds to wait for the agent to reach the server}';

    protected $description = 'Send a test exception end to end and print the result';

    /**
     * @param  Core<RequestState|CommandState>  $watch
     */
    public function handle(Core $watch): int
    {
        if (! $watch->enabled()) {
            $this->components->error('Watch is disabled (WATCH_ENABLED=false).');

            return self::FAILURE;
        }

        $transport = (string) config('watch.transport', 'agent');
        $address = (string) config('watch.agent_address', '127.0.0.1:2408');

        // A failure a minute ago must not hide that things work now.
        CircuitBreaker::forAgent($address)->close();
        (new CircuitBreaker(name: 'http'))->close();

        $this->components->twoColumnDetail('Transport', $transport);

        $startedAt = microtime(true);

        $watch->reportNow(new RuntimeException('Codeva Watch test exception from watch:test'));

        ['delivered' => $delivered, 'via' => $via, 'error' => $error] = $watch->ingest->lastDelivery();

        if ($error !== null) {
            $this->components->twoColumnDetail('Primary transport', "<fg=yellow>{$error}</>");
        }

        if (! $delivered) {
            $this->components->error('The test exception was not delivered.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Delivered via', $via);

        if ($via !== 'agent') {
            $this->components->info($via === 'null' ? 'The null transport keeps records in memory; nothing was sent.' : 'The server accepted the test exception.');

            return self::SUCCESS;
        }

        return $this->waitForAgent($address, $startedAt);
    }

    /**
     * The agent acknowledges a frame once it is in memory, so the proof that
     * the server has it is the agent's next successful send.
     */
    private function waitForAgent(string $address, float $startedAt): int
    {
        $status = new AgentStatus($address, (string) config('watch.token', ''));
        $deadline = microtime(true) + (float) $this->option('wait');

        do {
            try {
                $sent = $status->fetch()['last_success_at'] ?? null;
            } catch (AgentUnreachable) {
                $sent = null;
            }

            if (is_numeric($sent) && (float) $sent >= $startedAt) {
                $this->components->info('The agent accepted the test exception and delivered it to the server.');

                return self::SUCCESS;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        $this->components->warn('The agent accepted the test exception but has not reached the server; check WATCH_SERVER_URL and the agent log. It stays spooled and is retried.');

        return self::FAILURE;
    }
}
