<?php

use App\Agents\VunnixAgent;

it('exposes a PROMPT_VERSION constant', function (): void {
    expect(VunnixAgent::PROMPT_VERSION)->toBe('1.0');
});

it('PROMPT_VERSION is a non-empty string', function (): void {
    expect(VunnixAgent::PROMPT_VERSION)
        ->toBeString()
        ->not->toBeEmpty();
});
