<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Can the user view this conversation?
     * User must be a member of the primary project or any additional pivot project.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        $userProjectIds = $user->projects()->pluck('projects.id')->toArray();
        $primaryProject = $conversation->project;

        if ($primaryProject === null || ! $user->hasPermission('chat.access', $primaryProject)) {
            return false;
        }

        // Check primary project
        if (in_array($conversation->project_id, $userProjectIds, true)) {
            return true;
        }

        // Check additional projects via pivot
        return $conversation->projects()
            ->whereIn('projects.id', $userProjectIds)
            ->exists();
    }

    /**
     * Can the user add a project to this conversation?
     * Must be able to view the conversation AND have access to the new project.
     */
    public function addProject(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
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
     * Can the user stream an AI response in this conversation?
     * Same as sendMessage — must be a project member.
     */
    public function stream(User $user, Conversation $conversation): bool
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
