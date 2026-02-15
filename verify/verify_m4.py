#!/usr/bin/env python3
"""Vunnix M4 — Dashboard & Metrics verification.

Checks implemented M4 tasks. Run from project root: python3 verify/verify_m4.py

Static checks (file existence, content patterns) always run.
Runtime checks (artisan commands, tests) run only when services are available.
"""

import sys
import os

# Add verify/ to path for helpers import
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from helpers import (
    Check,
    section,
    file_exists,
    file_contains,
    file_matches,
    dir_exists,
    run_command,
)

checker = Check()

print("=" * 60)
print("  VUNNIX M4 — Dashboard & Metrics Verification")
print("=" * 60)

# ============================================================
#  T73: Reverb channel configuration (already complete)
# ============================================================
section("T73: Reverb Channel Configuration")

checker.check(
    "Broadcast channel definitions exist",
    file_exists("routes/channels.php"),
)
checker.check(
    "Task channel defined",
    file_contains("routes/channels.php", "task.{taskId}"),
)
checker.check(
    "Project activity channel defined",
    file_contains("routes/channels.php", "project.{projectId}.activity"),
)
checker.check(
    "Metrics channel defined",
    file_contains("routes/channels.php", "metrics.{projectId}"),
)
checker.check(
    "TaskStatusChanged event exists",
    file_exists("app/Events/TaskStatusChanged.php"),
)
checker.check(
    "TaskStatusChanged implements ShouldBroadcast",
    file_contains("app/Events/TaskStatusChanged.php", "ShouldBroadcast"),
)
checker.check(
    "TaskStatusChanged broadcasts on task and activity channels",
    file_contains("app/Events/TaskStatusChanged.php", "project.{$this->task->project_id}.activity"),
)

# ============================================================
#  T74: Laravel Echo client
# ============================================================
section("T74: Laravel Echo Client")

# Echo singleton composable
checker.check(
    "useEcho composable exists",
    file_exists("resources/js/composables/useEcho.js"),
)
checker.check(
    "Echo configured with Reverb broadcaster",
    file_contains("resources/js/composables/useEcho.js", "broadcaster: 'reverb'"),
)
checker.check(
    "useEcho test exists",
    file_exists("resources/js/composables/useEcho.test.js"),
)

# Dashboard real-time composable
checker.check(
    "useDashboardRealtime composable exists",
    file_exists("resources/js/composables/useDashboardRealtime.js"),
)
checker.check(
    "Subscribes to project activity channel",
    file_contains("resources/js/composables/useDashboardRealtime.js", "project.${project.id}.activity"),
)
checker.check(
    "Subscribes to metrics channel",
    file_contains("resources/js/composables/useDashboardRealtime.js", "metrics.${project.id}"),
)
checker.check(
    "Listens for task.status.changed events",
    file_contains("resources/js/composables/useDashboardRealtime.js", ".task.status.changed"),
)
checker.check(
    "Listens for metrics.updated events",
    file_contains("resources/js/composables/useDashboardRealtime.js", ".metrics.updated"),
)
checker.check(
    "useDashboardRealtime test exists",
    file_exists("resources/js/composables/useDashboardRealtime.test.js"),
)

# Dashboard Pinia store
checker.check(
    "Dashboard store exists",
    file_exists("resources/js/stores/dashboard.js"),
)
checker.check(
    "Dashboard store has addActivityItem mutation",
    file_contains("resources/js/stores/dashboard.js", "addActivityItem"),
)
checker.check(
    "Dashboard store has addMetricsUpdate mutation",
    file_contains("resources/js/stores/dashboard.js", "addMetricsUpdate"),
)
checker.check(
    "Dashboard store has filteredFeed computed",
    file_contains("resources/js/stores/dashboard.js", "filteredFeed"),
)
checker.check(
    "Dashboard store has feed cap",
    file_contains("resources/js/stores/dashboard.js", "FEED_CAP"),
)
checker.check(
    "Dashboard store test exists",
    file_exists("resources/js/stores/dashboard.test.js"),
)

# DashboardPage wiring
checker.check(
    "DashboardPage uses useDashboardRealtime",
    file_contains("resources/js/pages/DashboardPage.vue", "useDashboardRealtime"),
)
checker.check(
    "DashboardPage subscribes on mount",
    file_contains("resources/js/pages/DashboardPage.vue", "onMounted"),
)
checker.check(
    "DashboardPage unsubscribes on unmount",
    file_contains("resources/js/pages/DashboardPage.vue", "onUnmounted"),
)

# MetricsUpdated broadcast event
checker.check(
    "MetricsUpdated event exists",
    file_exists("app/Events/MetricsUpdated.php"),
)
checker.check(
    "MetricsUpdated implements ShouldBroadcast",
    file_contains("app/Events/MetricsUpdated.php", "ShouldBroadcast"),
)
checker.check(
    "MetricsUpdated broadcasts on metrics channel",
    file_contains("app/Events/MetricsUpdated.php", "metrics.{$this->projectId}"),
)
checker.check(
    "MetricsUpdated event name is metrics.updated",
    file_contains("app/Events/MetricsUpdated.php", "'metrics.updated'"),
)
checker.check(
    "MetricsUpdated test exists",
    file_exists("tests/Feature/Events/MetricsUpdatedTest.php"),
)

# Blade template Reverb config injection
checker.check(
    "Blade template injects Reverb config",
    file_contains("resources/views/app.blade.php", "__REVERB_CONFIG__"),
)

# ============================================================
#  T75: Dashboard — activity feed
# ============================================================
section("T75: Dashboard — Activity Feed")

# Backend
checker.check(
    "ActivityResource exists",
    file_exists("app/Http/Resources/ActivityResource.php"),
)
checker.check(
    "ActivityController exists",
    file_exists("app/Http/Controllers/Api/ActivityController.php"),
)
checker.check(
    "ActivityController uses cursor pagination",
    file_contains("app/Http/Controllers/Api/ActivityController.php", "cursorPaginate"),
)
checker.check(
    "Activity route registered",
    file_contains("routes/api.php", "/activity"),
)
checker.check(
    "Activity API test exists",
    file_exists("tests/Feature/ActivityFeedApiTest.php"),
)

# Frontend
checker.check(
    "ActivityFeed component exists",
    file_exists("resources/js/components/ActivityFeed.vue"),
)
checker.check(
    "ActivityFeedItem component exists",
    file_exists("resources/js/components/ActivityFeedItem.vue"),
)
checker.check(
    "ActivityFeed has filter tabs",
    file_contains("resources/js/components/ActivityFeed.vue", "filter-tab"),
)
checker.check(
    "Dashboard store has fetchActivity",
    file_contains("resources/js/stores/dashboard.js", "fetchActivity"),
)
checker.check(
    "Dashboard store has loadMore",
    file_contains("resources/js/stores/dashboard.js", "loadMore"),
)
checker.check(
    "DashboardPage imports ActivityFeed",
    file_contains("resources/js/pages/DashboardPage.vue", "ActivityFeed"),
)
checker.check(
    "DashboardPage calls fetchActivity on mount",
    file_contains("resources/js/pages/DashboardPage.vue", "fetchActivity"),
)
checker.check(
    "ActivityFeed test exists",
    file_exists("resources/js/components/ActivityFeed.test.js"),
)
checker.check(
    "ActivityFeedItem test exists",
    file_exists("resources/js/components/ActivityFeedItem.test.js"),
)

# ============================================================
#  T76: Dashboard — Overview
# ============================================================
section("T76: Dashboard — Overview")

# Backend
checker.check(
    "DashboardOverviewController exists",
    file_exists("app/Http/Controllers/Api/DashboardOverviewController.php"),
)
checker.check(
    "Overview controller queries tasks by type",
    file_contains("app/Http/Controllers/Api/DashboardOverviewController.php", "tasks_by_type"),
)
checker.check(
    "Overview controller calculates active tasks",
    file_contains("app/Http/Controllers/Api/DashboardOverviewController.php", "active_tasks"),
)
checker.check(
    "Overview controller calculates success rate",
    file_contains("app/Http/Controllers/Api/DashboardOverviewController.php", "success_rate"),
)
checker.check(
    "Overview controller scoped to user projects",
    file_contains("app/Http/Controllers/Api/DashboardOverviewController.php", "projectIds"),
)
checker.check(
    "Dashboard overview route registered",
    file_contains("routes/api.php", "/dashboard/overview"),
)
checker.check(
    "Dashboard overview API test exists",
    file_exists("tests/Feature/DashboardOverviewApiTest.php"),
)

# Frontend
checker.check(
    "DashboardOverview component exists",
    file_exists("resources/js/components/DashboardOverview.vue"),
)
checker.check(
    "DashboardOverview has summary cards",
    file_contains("resources/js/components/DashboardOverview.vue", "active-tasks-card"),
)
checker.check(
    "DashboardOverview has success rate card",
    file_contains("resources/js/components/DashboardOverview.vue", "success-rate-card"),
)
checker.check(
    "DashboardOverview has recent activity card",
    file_contains("resources/js/components/DashboardOverview.vue", "recent-activity-card"),
)
checker.check(
    "DashboardOverview has type cards",
    file_contains("resources/js/components/DashboardOverview.vue", "type-card-"),
)
checker.check(
    "Dashboard store has fetchOverview action",
    file_contains("resources/js/stores/dashboard.js", "fetchOverview"),
)
checker.check(
    "Dashboard store has overview state",
    file_contains("resources/js/stores/dashboard.js", "overview"),
)
checker.check(
    "DashboardPage imports DashboardOverview",
    file_contains("resources/js/pages/DashboardPage.vue", "DashboardOverview"),
)
checker.check(
    "DashboardPage has view tabs",
    file_contains("resources/js/pages/DashboardPage.vue", "dashboard-view-tabs"),
)
checker.check(
    "DashboardPage calls fetchOverview on mount",
    file_contains("resources/js/pages/DashboardPage.vue", "fetchOverview"),
)
checker.check(
    "DashboardOverview test exists",
    file_exists("resources/js/components/DashboardOverview.test.js"),
)

# ============================================================
#  Summary
# ============================================================
checker.summary()
