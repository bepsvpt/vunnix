<?php

use App\Modules\GitLabIntegration\Application\Contracts\GitLabPort;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabPortAdapter;

it('resolves GitLabPort contract to compatibility adapter', function (): void {
    $resolved = app(GitLabPort::class);

    expect($resolved)->toBeInstanceOf(GitLabPortAdapter::class);
});
