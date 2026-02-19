<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\HealthSnapshot */
class HealthSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dimension' => $this->dimension,
            'score' => $this->score,
            'details' => $this->details,
            'source_ref' => $this->source_ref,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
