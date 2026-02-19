<?php

use App\Providers\AppServiceProvider;
use App\Services\Health\HealthAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('resolves health analysis service from tagged analyzers binding', function (): void {
    $provider = new AppServiceProvider(app());
    $provider->register();

    $service = app(HealthAnalysisService::class);

    expect($service)->toBeInstanceOf(HealthAnalysisService::class);
});

it('swallows registerPermissionGates errors when database is unavailable', function (): void {
    $provider = new AppServiceProvider(app());
    $provider->register();

    Schema::shouldReceive('hasTable')
        ->once()
        ->with('permissions')
        ->andThrow(new RuntimeException('db unavailable'));

    $method = new ReflectionMethod(AppServiceProvider::class, 'registerPermissionGates');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(true)->toBeTrue();
});
