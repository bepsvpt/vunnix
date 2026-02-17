<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardPMActivityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $projectIds = $user->projects()
            ->where('enabled', true)
            ->pluck('projects.id');

        // PRDs created — completed PrdCreation tasks dispatched from conversations
        $prdsCreated = Task::whereIn('project_id', $projectIds)
            ->where('type', TaskType::PrdCreation)
            ->where('origin', TaskOrigin::Conversation)
            ->where('status', TaskStatus::Completed)
            ->count();

        // Conversations held — total conversations in user's enabled projects
        $conversationsHeld = Conversation::whereIn('project_id', $projectIds)->count();

        // Issues created from chat — completed tasks dispatched from conversations that created an issue
        $issuesFromChat = Task::whereIn('project_id', $projectIds)
            ->where('origin', TaskOrigin::Conversation)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('issue_iid')
            ->count();

        // Average turns per PRD — message count in conversations that led to completed PRDs
        $prdConversationIds = Task::whereIn('project_id', $projectIds)
            ->where('type', TaskType::PrdCreation)
            ->where('origin', TaskOrigin::Conversation)
            ->where('status', TaskStatus::Completed)
            ->whereNotNull('conversation_id')
            ->pluck('conversation_id')
            ->unique();

        $avgTurnsPerPrd = null;
        if ($prdConversationIds->isNotEmpty()) {
            $turnCounts = Message::whereIn('conversation_id', $prdConversationIds)
                ->selectRaw('conversation_id, count(*) as turn_count')
                ->groupBy('conversation_id')
                ->pluck('turn_count');

            if ($turnCounts->isNotEmpty()) {
                $avgTurnsPerPrd = round($turnCounts->avg() ?? 0, 1);
            }
        }

        return response()->json([
            'data' => [
                'prds_created' => $prdsCreated,
                'conversations_held' => $conversationsHeld,
                'issues_from_chat' => $issuesFromChat,
                'avg_turns_per_prd' => $avgTurnsPerPrd,
            ],
        ]);
    }
}
