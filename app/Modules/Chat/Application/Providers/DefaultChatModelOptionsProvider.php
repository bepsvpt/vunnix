<?php

namespace App\Modules\Chat\Application\Providers;

use App\Models\GlobalSetting;
use App\Modules\Chat\Application\Contracts\ChatModelOptionsProvider;

class DefaultChatModelOptionsProvider implements ChatModelOptionsProvider
{
    /**
     * @var array<string, string>
     */
    private const MODEL_MAP = [
        'opus' => 'claude-opus-4-20250514',
        'sonnet' => 'claude-sonnet-4-20250514',
        'haiku' => 'claude-haiku-4-20250514',
    ];

    private const DEFAULT_MODEL = 'claude-opus-4-20250514';

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        $aiModel = GlobalSetting::get('ai_model', 'opus');

        return self::MODEL_MAP[$aiModel] ?? self::DEFAULT_MODEL;
    }
}
