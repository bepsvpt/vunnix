<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MemoryEntry */
class MemoryEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'content' => $this->content,
            'confidence' => $this->confidence,
            'applied_count' => $this->applied_count,
            'source_task_id' => $this->source_task_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
