<?php

use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabIssueAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabMergeRequestAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabPipelineAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabPortAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabRepoAdapter;
use App\Services\GitLabClient;

it('delegates repository operations in GitLabRepoAdapter', function (): void {
    $client = Mockery::mock(GitLabClient::class);
    $adapter = new GitLabRepoAdapter($client);

    $client->shouldReceive('getFile')->once()->with(1, 'README.md', 'main')->andReturn(['content' => 'hi']);
    $client->shouldReceive('listTree')->once()->with(1, 'src', 'main', true)->andReturn([['path' => 'src/App.php']]);
    $client->shouldReceive('searchCode')->once()->with(1, 'needle')->andReturn([['path' => 'app/Services/Foo.php']]);

    expect($adapter->getFile(1, 'README.md'))->toBe(['content' => 'hi']);
    expect($adapter->listTree(1, 'src', 'main', true))->toBe([['path' => 'src/App.php']]);
    expect($adapter->searchCode(1, 'needle'))->toBe([['path' => 'app/Services/Foo.php']]);
});

it('delegates issue operations in GitLabIssueAdapter', function (): void {
    $client = Mockery::mock(GitLabClient::class);
    $adapter = new GitLabIssueAdapter($client);

    $client->shouldReceive('listIssues')->once()->with(1, ['state' => 'opened'])->andReturn([['iid' => 10]]);
    $client->shouldReceive('getIssue')->once()->with(1, 10)->andReturn(['iid' => 10]);
    $client->shouldReceive('createIssue')->once()->with(1, ['title' => 'Bug'])->andReturn(['iid' => 11]);
    $client->shouldReceive('createIssueNote')->once()->with(1, 10, 'LGTM')->andReturn(['id' => 100]);

    expect($adapter->listIssues(1, ['state' => 'opened']))->toBe([['iid' => 10]]);
    expect($adapter->getIssue(1, 10))->toBe(['iid' => 10]);
    expect($adapter->createIssue(1, ['title' => 'Bug']))->toBe(['iid' => 11]);
    expect($adapter->createIssueNote(1, 10, 'LGTM'))->toBe(['id' => 100]);
});

it('delegates merge request operations in GitLabMergeRequestAdapter', function (): void {
    $client = Mockery::mock(GitLabClient::class);
    $adapter = new GitLabMergeRequestAdapter($client);

    $client->shouldReceive('listMergeRequests')->once()->with(1, ['state' => 'opened'])->andReturn([['iid' => 1]]);
    $client->shouldReceive('getMergeRequest')->once()->with(1, 1)->andReturn(['iid' => 1]);
    $client->shouldReceive('getMergeRequestChanges')->once()->with(1, 1)->andReturn(['changes' => []]);
    $client->shouldReceive('createMergeRequest')->once()->with(1, ['title' => 'Add feature'])->andReturn(['iid' => 2]);
    $client->shouldReceive('updateMergeRequest')->once()->with(1, 1, ['title' => 'Updated'])->andReturn(['iid' => 1]);
    $client->shouldReceive('findOpenMergeRequestForBranch')->once()->with(1, 'feature/x')->andReturn(['iid' => 1]);
    $client->shouldReceive('createMergeRequestNote')->once()->with(1, 1, 'note')->andReturn(['id' => 9]);
    $client->shouldReceive('updateMergeRequestNote')->once()->with(1, 1, 9, 'new note')->andReturn(['id' => 9]);
    $client->shouldReceive('listMergeRequestDiscussions')->once()->with(1, 1, ['per_page' => 5])->andReturn([['id' => 'd1']]);
    $client->shouldReceive('createMergeRequestDiscussion')->once()->with(1, 1, 'thread', ['position_type' => 'text'])->andReturn(['id' => 'd2']);

    expect($adapter->listMergeRequests(1, ['state' => 'opened']))->toBe([['iid' => 1]]);
    expect($adapter->getMergeRequest(1, 1))->toBe(['iid' => 1]);
    expect($adapter->getMergeRequestChanges(1, 1))->toBe(['changes' => []]);
    expect($adapter->createMergeRequest(1, ['title' => 'Add feature']))->toBe(['iid' => 2]);
    expect($adapter->updateMergeRequest(1, 1, ['title' => 'Updated']))->toBe(['iid' => 1]);
    expect($adapter->findOpenMergeRequestForBranch(1, 'feature/x'))->toBe(['iid' => 1]);
    expect($adapter->createMergeRequestNote(1, 1, 'note'))->toBe(['id' => 9]);
    expect($adapter->updateMergeRequestNote(1, 1, 9, 'new note'))->toBe(['id' => 9]);
    expect($adapter->listMergeRequestDiscussions(1, 1, ['per_page' => 5]))->toBe([['id' => 'd1']]);
    expect($adapter->createMergeRequestDiscussion(1, 1, 'thread', ['position_type' => 'text']))->toBe(['id' => 'd2']);
});

it('delegates pipeline operations in GitLabPipelineAdapter', function (): void {
    $client = Mockery::mock(GitLabClient::class);
    $adapter = new GitLabPipelineAdapter($client);

    $client->shouldReceive('listPipelines')->once()->with(1, ['ref' => 'main'])->andReturn([['id' => 101]]);
    $client->shouldReceive('createPipelineTrigger')->once()->with(1, 'vunnix')->andReturn(['id' => 5, 'token' => 'tok']);
    $client->shouldReceive('triggerPipeline')->once()->with(1, 'main', 'tok', ['A' => 'B'])->andReturn(['id' => 102]);
    $client->shouldReceive('cancelPipeline')->once()->with(1, 102);

    expect($adapter->listPipelines(1, ['ref' => 'main']))->toBe([['id' => 101]]);
    expect($adapter->createPipelineTrigger(1, 'vunnix'))->toBe(['id' => 5, 'token' => 'tok']);
    expect($adapter->triggerPipeline(1, 'main', 'tok', ['A' => 'B']))->toBe(['id' => 102]);

    $adapter->cancelPipeline(1, 102);
    expect(true)->toBeTrue();
});

it('delegates all operations in GitLabPortAdapter', function (): void {
    $repo = Mockery::mock(GitLabRepoAdapter::class);
    $issue = Mockery::mock(GitLabIssueAdapter::class);
    $mr = Mockery::mock(GitLabMergeRequestAdapter::class);
    $pipeline = Mockery::mock(GitLabPipelineAdapter::class);
    $port = new GitLabPortAdapter($repo, $issue, $mr, $pipeline);

    $repo->shouldReceive('getFile')->once()->with(1, 'README.md', 'main')->andReturn(['content' => 'x']);
    $repo->shouldReceive('listTree')->once()->with(1, '', 'main', false)->andReturn([]);
    $repo->shouldReceive('searchCode')->once()->with(1, 'needle')->andReturn([]);
    $issue->shouldReceive('listIssues')->once()->with(1, [])->andReturn([]);
    $issue->shouldReceive('getIssue')->once()->with(1, 7)->andReturn(['iid' => 7]);
    $issue->shouldReceive('createIssue')->once()->with(1, ['title' => 'T'])->andReturn(['iid' => 8]);
    $issue->shouldReceive('createIssueNote')->once()->with(1, 7, 'body')->andReturn(['id' => 1]);
    $mr->shouldReceive('listMergeRequests')->once()->with(1, [])->andReturn([]);
    $mr->shouldReceive('getMergeRequest')->once()->with(1, 2)->andReturn(['iid' => 2]);
    $mr->shouldReceive('getMergeRequestChanges')->once()->with(1, 2)->andReturn(['changes' => []]);
    $mr->shouldReceive('createMergeRequest')->once()->with(1, ['title' => 'MR'])->andReturn(['iid' => 3]);
    $mr->shouldReceive('updateMergeRequest')->once()->with(1, 2, ['title' => 'MR2'])->andReturn(['iid' => 2]);
    $mr->shouldReceive('findOpenMergeRequestForBranch')->once()->with(1, 'feature/x')->andReturn(['iid' => 2]);
    $mr->shouldReceive('createMergeRequestNote')->once()->with(1, 2, 'n')->andReturn(['id' => 9]);
    $mr->shouldReceive('updateMergeRequestNote')->once()->with(1, 2, 9, 'u')->andReturn(['id' => 9]);
    $mr->shouldReceive('listMergeRequestDiscussions')->once()->with(1, 2, [])->andReturn([]);
    $mr->shouldReceive('createMergeRequestDiscussion')->once()->with(1, 2, 'd', [])->andReturn(['id' => 'd1']);
    $pipeline->shouldReceive('listPipelines')->once()->with(1, [])->andReturn([]);
    $pipeline->shouldReceive('createPipelineTrigger')->once()->with(1, 'trigger')->andReturn(['id' => 11]);
    $pipeline->shouldReceive('triggerPipeline')->once()->with(1, 'main', 'tok', [])->andReturn(['id' => 12]);
    $pipeline->shouldReceive('cancelPipeline')->once()->with(1, 12);

    expect($port->getFile(1, 'README.md'))->toBe(['content' => 'x']);
    expect($port->listTree(1))->toBe([]);
    expect($port->searchCode(1, 'needle'))->toBe([]);
    expect($port->listIssues(1))->toBe([]);
    expect($port->getIssue(1, 7))->toBe(['iid' => 7]);
    expect($port->createIssue(1, ['title' => 'T']))->toBe(['iid' => 8]);
    expect($port->createIssueNote(1, 7, 'body'))->toBe(['id' => 1]);
    expect($port->listMergeRequests(1))->toBe([]);
    expect($port->getMergeRequest(1, 2))->toBe(['iid' => 2]);
    expect($port->getMergeRequestChanges(1, 2))->toBe(['changes' => []]);
    expect($port->createMergeRequest(1, ['title' => 'MR']))->toBe(['iid' => 3]);
    expect($port->updateMergeRequest(1, 2, ['title' => 'MR2']))->toBe(['iid' => 2]);
    expect($port->findOpenMergeRequestForBranch(1, 'feature/x'))->toBe(['iid' => 2]);
    expect($port->createMergeRequestNote(1, 2, 'n'))->toBe(['id' => 9]);
    expect($port->updateMergeRequestNote(1, 2, 9, 'u'))->toBe(['id' => 9]);
    expect($port->listMergeRequestDiscussions(1, 2))->toBe([]);
    expect($port->createMergeRequestDiscussion(1, 2, 'd'))->toBe(['id' => 'd1']);
    expect($port->listPipelines(1))->toBe([]);
    expect($port->createPipelineTrigger(1, 'trigger'))->toBe(['id' => 11]);
    expect($port->triggerPipeline(1, 'main', 'tok'))->toBe(['id' => 12]);

    $port->cancelPipeline(1, 12);
    expect(true)->toBeTrue();
});
