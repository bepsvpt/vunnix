<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Can the user view this conversation?
     * User must be a member of the conversation's project.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->projects()->where('projects.id', $conversation->project_id)->exists();
    }

    /**
     * Can the user send a message to this conversation?
     * Same as view — must be a project member.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * Can the user archive/unarchive this conversation?
     * Same as view — any project member can archive.
     */
    public function archive(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
