<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gitlab_project_id' => $this->gitlab_project_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'webhook_configured' => $this->webhook_configured,
            'webhook_id' => $this->webhook_id,
            'recent_task_count' => Task::where('project_id', $this->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'active_conversation_count' => Conversation::where('project_id', $this->id)
                ->notArchived()
                ->where('updated_at', '>=', now()->subDays(30))
                ->count(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
