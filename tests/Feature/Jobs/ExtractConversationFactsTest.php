<?php

use App\Jobs\ExtractConversationFacts;
use App\Models\MemoryEntry;
use App\Models\Project;
use App\Services\MemoryExtractionService;
use App\Services\ProjectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('extracts conversation facts and stores them as memory entries', function (): void {
    config([
        'vunnix.memory.enabled' => true,
        'vunnix.memory.conversation_continuity' => true,
    ]);

    $project = Project::factory()->create();
    $summary = 'We decided to use Redis for queue buffering. The team also configured Laravel Horizon.';

    $job = new ExtractConversationFacts($summary, $project->id, ['conversation_id' => 'conv-9']);
    $job->handle(
        app(MemoryExtractionService::class),
        app(ProjectMemoryService::class),
    );

    $entry = MemoryEntry::query()
        ->where('project_id', $project->id)
        ->where('type', 'conversation_fact')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry?->source_meta['conversation_id'])->toBe('conv-9');
});

it('returns early when the project cannot be found', function (): void {
    $extraction = Mockery::mock(MemoryExtractionService::class);
    $extraction->shouldNotReceive('extractFromConversationSummary');
    $memory = Mockery::mock(ProjectMemoryService::class);
    $memory->shouldNotReceive('invalidateProjectCache');

    $job = new ExtractConversationFacts('Uses Redis for jobs.', 999999, ['conversation_id' => 'conv-x']);
    $job->handle($extraction, $memory);

    expect(MemoryEntry::query()->count())->toBe(0);
});

it('logs a warning when extraction throws and does not rethrow', function (): void {
    $project = Project::factory()->create();

    $extraction = Mockery::mock(MemoryExtractionService::class);
    $extraction->shouldReceive('extractFromConversationSummary')->once()->andThrow(new RuntimeException('boom'));

    $memory = Mockery::mock(ProjectMemoryService::class);
    $memory->shouldNotReceive('invalidateProjectCache');

    Log::spy();

    $job = new ExtractConversationFacts('Uses Redis for jobs.', $project->id, ['conversation_id' => 'conv-y']);
    $job->handle($extraction, $memory);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'ExtractConversationFacts failed'
            && $context['project_id'] === $project->id);
});
