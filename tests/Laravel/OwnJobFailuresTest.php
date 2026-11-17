<?php

use Codeva\Watch\Core;
use Codeva\Watch\Jobs\SendBatch;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;

function queuedJob(string $class): SyncJob
{
    return new SyncJob(app(), json_encode(['displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']), 'sqs', 'default');
}

function reportedMessages(Core $core): array
{
    $inner = (new ReflectionProperty($core->ingest, 'inner'))->getValue($core->ingest);
    $records = json_decode(json_encode($inner->records), true);

    return array_column(array_filter($records, fn ($r) => $r['t'] === 'exception'), 'message');
}

// SQS delivers a message at least once, and SendBatch runs once: a second
// delivery fails as "attempted too many times" without anything being wrong.
it('does not report a Watch job being delivered again or timing out', function () {
    $core = app(Core::class);
    $core->sample(1.0);

    $core->report(MaxAttemptsExceededException::forJob(queuedJob(SendBatch::class)));
    $core->report(TimeoutExceededException::forJob(queuedJob(SendBatch::class)));
    $core->report(MaxAttemptsExceededException::forJob(queuedJob('App\\Jobs\\SendInvoice')));

    expect(reportedMessages($core))->toBe(['App\\Jobs\\SendInvoice has been attempted too many times.']);
});
