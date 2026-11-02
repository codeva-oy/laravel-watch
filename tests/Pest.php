<?php

use Codeva\Watch\Tests\TestCase;
use Codeva\Watch\Transport\CircuitBreaker;

require_once __DIR__.'/Support/helpers.php';

uses(TestCase::class)->in('Laravel');

uses()->beforeEach(fn () => CircuitBreaker::reset())->in('Unit', 'Feature', 'Laravel');
