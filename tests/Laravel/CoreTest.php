<?php

use Codeva\Watch\Compatibility;
use Codeva\Watch\Core;
use Codeva\Watch\Facades\Watch;
use Codeva\Watch\Http\Middleware\Sample;
use Codeva\Watch\Location;
use Codeva\Watch\Sensors\ExceptionSensor;
use Codeva\Watch\Transport\CircuitBreaker;
use Codeva\Watch\Transport\NullIngest;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use Symfony\Component\ErrorHandler\Error\FatalError;

beforeEach(function () {
    $this->core = app(Core::class);
});

function ingest(object $t): NullIngest
{
    return (new ReflectionProperty($t->core->ingest, 'inner'))->getValue($t->core->ingest);
}

function wire(object $t): array
{
    return json_decode(json_encode(ingest($t)->records), true);
}

it('reflects Watch::sample() on records written before and after it', function () {
    $this->core->sample(1.0);
    $this->core->report(new RuntimeException('x'));
    $this->core->ingest->write(['t' => 'query', 'sql' => '']);

    Watch::sample(1.0);
    $this->core->sampleRate->value = 0.25;
    $this->core->ingest->write(['t' => 'query', 'sql' => '']);

    expect(array_column(wire($this), 'sample_rate'))->toBe([1, 0.25, 0.25]);
});

it('remembers the rate an override used', function () {
    $this->core->sample(0.0);

    expect($this->core->sampleRate->value)->toBe(0.0)->and($this->core->sampling())->toBeFalse();
});

it('treats a rate outside 0 to 1 as not sampled', function () {
    $this->core->sample(3.0);

    expect($this->core->sampling())->toBeFalse()->and($this->core->sampleRate->value)->toBe(0.0);
});

it('drops an exception that loses its own sampling roll', function () {
    $this->core->config['sampling']['exceptions'] = 0.0;
    mt_srand(1);

    $this->core->sample(1.0);
    $this->core->report(new RuntimeException('x'));

    expect(array_filter(wire($this), fn ($r) => $r['t'] === 'exception'))->toBeEmpty();
});

it('keeps a job sampled by its dispatcher at the job rate, multiplied', function () {
    $this->core->config['sampling']['jobs'] = 1.0;
    $this->core->sample(1.0);
    $this->core->sampleRate->value = 0.5;
    Compatibility::addSampleRateToContext(0.5);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('J');
    $job->shouldReceive('attempts')->andReturn(1);

    $this->core->prepareForJob($job);

    expect($this->core->sampling())->toBeTrue()->and($this->core->sampleRate->value)->toBe(0.5);
});

it('skips a job at WATCH_SAMPLE_JOBS 0 and when its dispatcher was not sampled', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('J');
    $job->shouldReceive('attempts')->andReturn(1);

    $this->core->config['sampling']['jobs'] = 0.0;
    $this->core->sample(1.0);
    $this->core->prepareForJob($job);
    expect($this->core->sampling())->toBeFalse();

    $this->core->config['sampling']['jobs'] = 1.0;
    Compatibility::addSamplingToContext(false);
    $this->core->prepareForJob($job);
    expect($this->core->sampling())->toBeFalse();
});

it('groups exceptions with groupUsing, by class and subclass', function () {
    Watch::groupUsing(InvalidArgumentException::class, fn (Throwable $e) => 'all-the-same');
    $this->core->sample(1.0);

    $this->core->report(new InvalidArgumentException('a'));
    $this->core->report(new DomainException('b'));

    [$first, $second] = wire($this);

    expect($first['fingerprint'])->toBe(hash('xxh128', 'all-the-same'))
        ->and($second['fingerprint'])->not->toBe($first['fingerprint']);
});

it('falls back to the default fingerprint when groupUsing returns null', function () {
    Watch::groupUsing(RuntimeException::class, fn () => null);
    $this->core->sample(1.0);
    $this->core->report(new RuntimeException('a'));

    expect(wire($this)[0]['fingerprint'])->toHaveLength(32);
});

it('honours capture_source_code', function () {
    $sensor = new ExceptionSensor(
        executionState: $this->core->executionState,
        clock: $this->core->clock,
        location: new Location(dirname(__DIR__), dirname(__DIR__).'/public'),
        captureSourceCode: false,
    );

    [, $resolver] = $sensor(new RuntimeException('x'), true);

    foreach (json_decode($resolver()['trace'], true) as $frame) {
        expect($frame['code'])->toBeNull();
    }
});

it('reads five lines either side of an application frame', function () {
    $sensor = new ExceptionSensor(
        executionState: $this->core->executionState,
        clock: $this->core->clock,
        location: new Location(dirname(__DIR__), dirname(__DIR__).'/public'),
        captureSourceCode: true,
    );

    [, $resolver] = $sensor(new RuntimeException('x'), true);
    $code = json_decode($resolver()['trace'], true)[0]['code'];

    expect($code)->toHaveCount(11);
});

it('finishes an execution without touching a reset breaker', function () {
    $this->core->finishExecution();

    expect((new CircuitBreaker)->isOpen())->toBeFalse();
});

it('sets the watch channel level from the config, warning by default', function () {
    expect(config('logging.channels.watch.level'))->toBe('warning');
});

it('does not capture logs when told to ignore them', function () {
    $this->core->config['filtering']['ignore_logs'] = true;
    $this->core->sample(1.0);

    expect($this->core->shouldCaptureLogs())->toBeFalse();

    $this->core->config['filtering']['ignore_logs'] = false;

    expect($this->core->shouldCaptureLogs())->toBeTrue();
    expect(Log::channel('watch'))->not->toBeNull();
});

it('sends a fatal error immediately, even from an unsampled execution', function () {
    $this->core->sample(0.0);

    $this->core->report(new FatalError('Allowed memory size of 123 bytes exhausted', 0, ['file' => '/x/app/Foo.php', 'line' => 3, 'type' => 1, 'message' => 'm']));

    expect(wire($this)[0])->toMatchArray(['t' => 'exception', 'handled' => false])->toHaveKey('fingerprint');
});

it('resolves in standby from the container, as facades and the Sample middleware do', function () {
    expect(app(Sample::class))->toBeInstanceOf(Sample::class);
});

it('reads WATCH_TRANSPORT=null as the null transport', function () {
    expect(config('watch.transport'))->toBe('null')
        ->and((new ReflectionProperty($this->core->ingest, 'inner'))->getValue($this->core->ingest))->toBeInstanceOf(NullIngest::class);
});
