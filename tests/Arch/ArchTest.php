<?php

/*
|--------------------------------------------------------------------------
| Architecture Tests
|--------------------------------------------------------------------------
|
| Enforce coding standards from CLAUDE.md as automated rules.
| These tests run with `composer test` alongside all other Pest tests.
|
*/

// ---------------------------------------------------------------------------
// Global safety rules
// ---------------------------------------------------------------------------

arch('no debug functions in application code')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r']);

arch('no die or exit in application code')
    ->expect('App')
    ->not->toUse(['die', 'exit']);

arch('no env() calls outside config — use config() instead')
    ->expect('env')
    ->not->toBeUsedIn('App');

// ---------------------------------------------------------------------------
// Layer dependency rules
// ---------------------------------------------------------------------------

arch('models do not depend on the HTTP layer')
    ->expect('App\Models')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Http\Requests',
        'App\Http\Resources',
        'App\Http\Middleware',
    ]);

arch('models do not depend on jobs')
    ->expect('App\Models')
    ->not->toUse('App\Jobs');

arch('services do not depend on controllers')
    ->expect('App\Services')
    ->not->toUse('App\Http\Controllers');

arch('jobs do not depend on controllers')
    ->expect('App\Jobs')
    ->not->toUse('App\Http\Controllers');

arch('enums have no dependencies on services, jobs, or HTTP layer')
    ->expect('App\Enums')
    ->not->toUse([
        'App\Services',
        'App\Jobs',
        'App\Http',
    ]);

// ---------------------------------------------------------------------------
// Naming and structure conventions
// ---------------------------------------------------------------------------

arch('controllers have Controller suffix')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('form requests have Request suffix')
    ->expect('App\Http\Requests')
    ->toHaveSuffix('Request');

arch('API resources have Resource suffix')
    ->expect('App\Http\Resources')
    ->toHaveSuffix('Resource');

arch('jobs are classes')
    ->expect('App\Jobs')
    ->toBeClasses();

arch('enums are enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('policies have Policy suffix')
    ->expect('App\Policies')
    ->toHaveSuffix('Policy');

arch('events are classes')
    ->expect('App\Events')
    ->toBeClasses();

arch('listeners are classes')
    ->expect('App\Listeners')
    ->toBeClasses();
