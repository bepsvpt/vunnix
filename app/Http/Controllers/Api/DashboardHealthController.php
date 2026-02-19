<?php

namespace App\Http\Controllers\Api;

use App\Enums\HealthDimension;
use App\Http\Controllers\Controller;
use App\Http\Resources\HealthSnapshotResource;
use App\Models\AlertEvent;
use App\Models\HealthSnapshot;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class DashboardHealthController extends Controller
{
    public function trends(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeProjectMember($request->user(), $project);

        $validated = $request->validate([
            'dimension' => ['nullable', 'string', 'in:coverage,dependency,complexity'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse((string) $validated['from']) : now()->subDays(30);
        $to = isset($validated['to']) ? Carbon::parse((string) $validated['to']) : now();

        $query = HealthSnapshot::query()
            ->forProject($project->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at');

        $dimension = $validated['dimension'] ?? null;
        if (is_string($dimension) && $dimension !== '') {
            $query->ofDimension($dimension);
        }

        return HealthSnapshotResource::collection($query->get());
    }

    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectMember($request->user(), $project);

        $data = [];
        $comparisonCutoff = now()->subDays(7);

        foreach (HealthDimension::cases() as $dimension) {
            $latest = HealthSnapshot::query()
                ->forProject($project->id)
                ->ofDimension($dimension->value)
                ->orderByDesc('created_at')
                ->first();

            if (! $latest instanceof HealthSnapshot) {
                $data[$dimension->value] = [
                    'score' => null,
                    'trend_direction' => 'stable',
                    'last_checked_at' => null,
                ];

                continue;
            }

            $baseline = HealthSnapshot::query()
                ->forProject($project->id)
                ->ofDimension($dimension->value)
                ->where('created_at', '<=', $comparisonCutoff)
                ->orderByDesc('created_at')
                ->first();

            $baselineScore = $baseline instanceof HealthSnapshot ? $baseline->score : null;
            $data[$dimension->value] = [
                'score' => $latest->score,
                'trend_direction' => $this->trendDirection($baselineScore, $latest->score),
                'last_checked_at' => $latest->created_at?->toIso8601String(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function alerts(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectMember($request->user(), $project);

        $alerts = AlertEvent::query()
            ->active()
            ->whereIn('alert_type', array_map(
                static fn (HealthDimension $dimension): string => $dimension->alertType(),
                HealthDimension::cases(),
            ))
            ->where(function ($query) use ($project): void {
                $query->where('context->project_id', $project->id)
                    ->orWhere('context->project_id', (string) $project->id);
            })
            ->orderByDesc('created_at')
            ->cursorPaginate(25);

        return response()->json([
            'data' => $alerts->items(),
            'meta' => [
                'path' => $alerts->path(),
                'per_page' => $alerts->perPage(),
                'next_cursor' => $alerts->nextCursor()?->encode(),
                'prev_cursor' => $alerts->previousCursor()?->encode(),
            ],
        ]);
    }

    private function authorizeProjectMember(?User $user, Project $project): void
    {
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $user->projects()->where('projects.id', $project->id)->exists()) {
            abort(403, 'Project membership required.');
        }
    }

    private function trendDirection(?float $baseline, float $current): string
    {
        if ($baseline === null) {
            return 'stable';
        }

        $delta = $current - $baseline;
        if ($delta > 1.0) {
            return 'up';
        }
        if ($delta < -1.0) {
            return 'down';
        }

        return 'stable';
    }
}
