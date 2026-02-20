<?php

use App\Modules\Shared\Application\Contracts\PipelineExecutorPort;
use App\Modules\Shared\Infrastructure\Adapters\PipelineExecutorAdapter;

it('resolves PipelineExecutorPort contract to compatibility adapter', function (): void {
    $resolved = app(PipelineExecutorPort::class);

    expect($resolved)->toBeInstanceOf(PipelineExecutorAdapter::class);
});
