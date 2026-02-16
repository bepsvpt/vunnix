<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Conversation */
class ConversationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'projects' => $this->whenLoaded('projects', function () {
                return $this->projects->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ]);
            }),
        ];
    }
}
