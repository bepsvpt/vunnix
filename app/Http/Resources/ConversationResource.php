<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin \App\Models\Conversation */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
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
            'last_message' => $this->whenLoaded('latestMessage', function () {
                return [
                    'content' => Str::limit($this->latestMessage->content, 150),
                    'role' => $this->latestMessage->role,
                    'created_at' => $this->latestMessage->created_at->toIso8601String(),
                ];
            }),
            'projects' => $this->whenLoaded('projects', function () {
                return $this->projects->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ]);
            }),
        ];
    }
}
