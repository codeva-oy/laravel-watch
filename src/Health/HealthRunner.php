<?php

declare(strict_types=1);

namespace Codeva\Watch\Health;

use Illuminate\Contracts\Container\Container;
use Spatie\Health\Checks\Check;
use Spatie\Health\Health;
use Throwable;

use function class_basename;
use function class_exists;
use function time;

/**
 * Produces the spatie/laravel-health JSON that Oh Dear and the Watch server
 * read: the app's Spatie checks when it has any, otherwise the built-in set.
 *
 * @internal
 *
 * @phpstan-type Result array{name: string, label: string, status: string, notificationMessage: string, shortSummary: string, meta: array<string, mixed>}
 */
final class HealthRunner
{
    public function __construct(private readonly Container $app, private readonly BuiltInChecks $builtIn)
    {
        //
    }

    /**
     * @return array{finishedAt: int, checkResults: list<Result>}
     */
    public function run(): array
    {
        $results = $this->spatieResults() ?? $this->builtInResults();

        return ['finishedAt' => time(), 'checkResults' => $results];
    }

    /**
     * @return list<Result>|null
     */
    private function spatieResults(): ?array
    {
        if (! class_exists(Health::class) || ! $this->app->bound(Health::class)) {
            return null;
        }

        /** @var array<int, Check> $checks */
        $checks = $this->app->make(Health::class)->registeredChecks()->all();

        if ($checks === []) {
            return null;
        }

        $results = [];

        foreach ($checks as $check) {
            $results[] = $this->spatieResult($check);
        }

        return $results;
    }

    /**
     * @return Result
     */
    private function spatieResult(Check $check): array
    {
        $result = ['name' => $check->getName(), 'label' => $check->getLabel(), 'meta' => []];

        if (! $check->shouldRun()) {
            return [...$result, 'status' => 'skipped', 'notificationMessage' => '', 'shortSummary' => 'Skipped'];
        }

        try {
            $outcome = $check->run()->check($check);

            return [
                ...$result,
                'status' => (string) $outcome->status->value,
                'notificationMessage' => $outcome->getNotificationMessage(),
                'shortSummary' => $outcome->getShortSummary(),
                'meta' => $outcome->meta,
            ];
        } catch (Throwable $e) {
            return [...$result, ...self::crashed($e)];
        }
    }

    /**
     * @return list<Result>
     */
    private function builtInResults(): array
    {
        $results = [];

        foreach ($this->builtIn->all() as $name => [$label, $check]) {
            $base = ['name' => $name, 'label' => $label];

            try {
                $results[] = [...$base, ...$check()];
            } catch (Throwable $e) {
                $results[] = [...$base, 'meta' => [], ...self::crashed($e)];
            }
        }

        return $results;
    }

    /**
     * The exception's message can hold hosts or credentials, so only its class
     * leaves the application.
     *
     * @return array{status: string, notificationMessage: string, shortSummary: string}
     */
    private static function crashed(Throwable $e): array
    {
        return ['status' => 'crashed', 'notificationMessage' => 'The check crashed with '.class_basename($e), 'shortSummary' => 'Crashed'];
    }
}
