<?php

namespace App\Http\Resources;

use App\Services\ProjectConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProjectConfig */
class ProjectConfigResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $service = app(ProjectConfigService::class);

        return [
            'settings' => $this->settings ?? [],
            'effective' => $service->allEffective($this->project),
            'setting_keys' => ProjectConfigService::settingKeys(),
        ];
    }
}
