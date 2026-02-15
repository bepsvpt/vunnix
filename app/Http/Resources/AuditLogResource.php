<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'task_id' => $this->task_id,
            'conversation_id' => $this->conversation_id,
            'summary' => $this->summary,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
