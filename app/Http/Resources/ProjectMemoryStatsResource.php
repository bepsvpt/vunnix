<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMemoryStatsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'total_entries' => $this['total_entries'] ?? 0,
            'by_type' => $this['by_type'] ?? [],
            'by_category' => $this['by_category'] ?? [],
            'average_confidence' => $this['average_confidence'] ?? 0,
            'last_created_at' => $this['last_created_at'] ?? null,
        ];
    }
}
