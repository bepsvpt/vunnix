<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Task */
class ExternalTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'user_name' => $this->user?->name,
            'mr_iid' => $this->mr_iid,
            'issue_iid' => $this->issue_iid,
            'commit_sha' => $this->commit_sha,
            'result' => $this->when($this->status->isTerminal(), $this->result),
            'cost' => $this->cost,
            'tokens_used' => $this->tokens_used,
            'duration_seconds' => $this->duration_seconds,
            'error_reason' => $this->error_reason,
            'retry_count' => $this->retry_count,
            'prompt_version' => $this->prompt_version,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
