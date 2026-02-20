<?php

use App\Agents\VunnixAgent;
use App\Modules\Chat\Application\Contracts\ChatPromptProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps default agent composition behavior stable', function (): void {
    $agent = new VunnixAgent;

    expect($agent->provider())->toBe('anthropic')
        ->and($agent->model())->not->toBe('')
        ->and(iterator_to_array($agent->tools()))->toHaveCount(10)
        ->and($agent->middleware())->toHaveCount(1)
        ->and($agent->instructions())->toContain('Identity');
});

it('uses bound prompt provider during composition', function (): void {
    app()->bind(ChatPromptProvider::class, static fn (): ChatPromptProvider => new class implements ChatPromptProvider
    {
        public function build(VunnixAgent $agent): string
        {
            return 'custom-prompt-provider-output';
        }
    });

    $agent = new VunnixAgent;

    expect($agent->instructions())->toBe('custom-prompt-provider-output');
});
