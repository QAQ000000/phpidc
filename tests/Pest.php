<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests touch the database and get a fresh schema per test.
|
| `Specs` holds cases for behaviour that is not implemented yet. Every case
| there carries the `spec` group, which phpunit.xml excludes from the default
| run — so a red suite always means a real regression, never "not built yet".
|
| Run the specs deliberately:  ./vendor/bin/pest --group=spec
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

uses(TestCase::class, RefreshDatabase::class)->group('spec')->in('Specs');

uses(TestCase::class)->in('Unit');
