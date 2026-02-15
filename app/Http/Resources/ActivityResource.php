<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'summary' => $this->result['title'] ?? $this->result['mr_title'] ?? $this->result['summary'] ?? null,
            'user_name' => $this->user?->name,
            'user_avatar' => $this->user?->avatar_url,
            'mr_iid' => $this->mr_iid,
            'issue_iid' => $this->issue_iid,
            'conversation_id' => $this->conversation_id,
            'error_reason' => $this->error_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
