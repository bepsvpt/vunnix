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
#  Summary
# ============================================================
checker.summary()
