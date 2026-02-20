<?php

use App\Modules\Shared\Infrastructure\Adapters\PipelineExecutorAdapter;
use App\Services\GitLabClient;

it('delegates pipeline trigger operations to gitlab client', function (): void {
    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('triggerPipeline')
        ->once()
        ->with(42, 'main', 'token-123', ['VUNNIX_TASK_ID' => '1'])
        ->andReturn(['id' => 999]);

    $adapter = new PipelineExecutorAdapter($gitLab);

    $result = $adapter->triggerPipeline(42, 'main', 'token-123', ['VUNNIX_TASK_ID' => '1']);

    expect($result)->toBe(['id' => 999]);
});

it('delegates list and cancel pipeline operations to gitlab client', function (): void {
    $gitLab = Mockery::mock(GitLabClient::class);
    $gitLab->shouldReceive('listPipelines')
        ->once()
        ->with(42, ['status' => 'running'])
        ->andReturn([['id' => 1]]);
    $gitLab->shouldReceive('cancelPipeline')
        ->once()
        ->with(42, 1);

    $adapter = new PipelineExecutorAdapter($gitLab);

    expect($adapter->listPipelines(42, ['status' => 'running']))->toBe([['id' => 1]]);

    $adapter->cancelPipeline(42, 1);
    expect(true)->toBeTrue();
});
