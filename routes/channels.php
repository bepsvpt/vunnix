<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Channel authorization callbacks. Each returns true/false to allow/deny
| the authenticated user's subscription to the private channel.
|
*/

/**
 * task.{id} — User must have access to the task's project.
 */
Broadcast::channel('task.{taskId}', function (User $user, int $taskId) {
    $task = Task::find($taskId);

    if (! $task) {
        return false;
    }

    return $user->projects()
        ->where('projects.id', $task->project_id)
        ->exists();
});

/**
 * project.{id}.activity — User must be a member of the project.
 */
Broadcast::channel('project.{projectId}.activity', function (User $user, int $projectId) {
    return $user->projects()
        ->where('projects.id', $projectId)
        ->exists();
});

/**
 * metrics.{projectId} — User must be a member of the project.
 */
Broadcast::channel('metrics.{projectId}', function (User $user, int $projectId) {
    return $user->projects()
        ->where('projects.id', $projectId)
        ->exists();
});

/**
 * project.{projectId}.health — User must be a member of the project.
 */
Broadcast::channel('project.{projectId}.health', function (User $user, int $projectId) {
    return $user->projects()
        ->where('projects.id', $projectId)
        ->exists();
});
