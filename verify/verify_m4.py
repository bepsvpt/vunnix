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
#  T77: Dashboard — Quality
# ============================================================
section("T77: Dashboard — Quality")

# Backend
checker.check(
    "DashboardQualityController exists",
    file_exists("app/Http/Controllers/Api/DashboardQualityController.php"),
)
checker.check(
    "Quality controller queries code review tasks",
    file_contains("app/Http/Controllers/Api/DashboardQualityController.php", "CodeReview"),
)
checker.check(
    "Quality controller computes severity distribution",
    file_contains("app/Http/Controllers/Api/DashboardQualityController.php", "severity_distribution"),
)
checker.check(
    "Quality controller computes avg findings per review",
    file_contains("app/Http/Controllers/Api/DashboardQualityController.php", "avg_findings_per_review"),
)
checker.check(
    "Quality controller scoped to user projects",
    file_contains("app/Http/Controllers/Api/DashboardQualityController.php", "projectIds"),
)
checker.check(
    "Dashboard quality route registered",
    file_contains("routes/api.php", "/dashboard/quality"),
)
checker.check(
    "Dashboard quality API test exists",
    file_exists("tests/Feature/DashboardQualityApiTest.php"),
)

# Frontend
checker.check(
    "DashboardQuality component exists",
    file_exists("resources/js/components/DashboardQuality.vue"),
)
checker.check(
    "DashboardQuality has acceptance rate card",
    file_contains("resources/js/components/DashboardQuality.vue", "acceptance-rate-card"),
)
checker.check(
    "DashboardQuality has severity distribution",
    file_contains("resources/js/components/DashboardQuality.vue", "severity-distribution"),
)
checker.check(
    "DashboardQuality has avg findings card",
    file_contains("resources/js/components/DashboardQuality.vue", "avg-findings-card"),
)
checker.check(
    "Dashboard store has fetchQuality action",
    file_contains("resources/js/stores/dashboard.js", "fetchQuality"),
)
checker.check(
    "Dashboard store has quality state",
    file_contains("resources/js/stores/dashboard.js", "quality"),
)
checker.check(
    "DashboardPage imports DashboardQuality",
    file_contains("resources/js/pages/DashboardPage.vue", "DashboardQuality"),
)
checker.check(
    "DashboardPage has quality view tab",
    file_contains("resources/js/pages/DashboardPage.vue", "'quality'"),
)
checker.check(
    "DashboardQuality test exists",
    file_exists("resources/js/components/DashboardQuality.test.js"),
)

# ============================================================
#  T78: Dashboard — PM Activity
# ============================================================
section("T78: Dashboard — PM Activity")

# Backend
checker.check(
    "DashboardPMActivityController exists",
    file_exists("app/Http/Controllers/Api/DashboardPMActivityController.php"),
)
checker.check(
    "PM Activity controller queries PrdCreation tasks",
    file_contains("app/Http/Controllers/Api/DashboardPMActivityController.php", "PrdCreation"),
)
checker.check(
    "PM Activity controller counts conversations",
    file_contains("app/Http/Controllers/Api/DashboardPMActivityController.php", "conversations_held"),
)
checker.check(
    "PM Activity controller counts issues from chat",
    file_contains("app/Http/Controllers/Api/DashboardPMActivityController.php", "issues_from_chat"),
)
checker.check(
    "PM Activity controller computes avg turns per PRD",
    file_contains("app/Http/Controllers/Api/DashboardPMActivityController.php", "avg_turns_per_prd"),
)
checker.check(
    "PM Activity controller scoped to user projects",
    file_contains("app/Http/Controllers/Api/DashboardPMActivityController.php", "projectIds"),
)
checker.check(
    "Dashboard PM activity route registered",
    file_contains("routes/api.php", "/dashboard/pm-activity"),
)
checker.check(
    "Dashboard PM activity API test exists",
    file_exists("tests/Feature/DashboardPMActivityApiTest.php"),
)

# Frontend
checker.check(
    "DashboardPMActivity component exists",
    file_exists("resources/js/components/DashboardPMActivity.vue"),
)
checker.check(
    "DashboardPMActivity has PRDs created card",
    file_contains("resources/js/components/DashboardPMActivity.vue", "prds-created-card"),
)
checker.check(
    "DashboardPMActivity has conversations held card",
    file_contains("resources/js/components/DashboardPMActivity.vue", "conversations-held-card"),
)
checker.check(
    "DashboardPMActivity has issues from chat card",
    file_contains("resources/js/components/DashboardPMActivity.vue", "issues-from-chat-card"),
)
checker.check(
    "DashboardPMActivity has avg turns card",
    file_contains("resources/js/components/DashboardPMActivity.vue", "avg-turns-card"),
)
checker.check(
    "Dashboard store has fetchPMActivity action",
    file_contains("resources/js/stores/dashboard.js", "fetchPMActivity"),
)
checker.check(
    "Dashboard store has pmActivity state",
    file_contains("resources/js/stores/dashboard.js", "pmActivity"),
)
checker.check(
    "DashboardPage imports DashboardPMActivity",
    file_contains("resources/js/pages/DashboardPage.vue", "DashboardPMActivity"),
)
checker.check(
    "DashboardPage has pm-activity view tab",
    file_contains("resources/js/pages/DashboardPage.vue", "'pm-activity'"),
)
checker.check(
    "DashboardPMActivity test exists",
    file_exists("resources/js/components/DashboardPMActivity.test.js"),
)

# ============================================================
#  T86: Acceptance tracking (webhook-driven D149)
# ============================================================
section("T86: Acceptance Tracking")

# Model & migration
checker.check(
    "FindingAcceptance model exists",
    file_exists("app/Models/FindingAcceptance.php"),
)
checker.check(
    "FindingAcceptance migration exists",
    file_exists("database/migrations/2026_02_15_040000_create_finding_acceptances_table.php"),
)
checker.check(
    "FindingAcceptance has status field",
    file_contains("app/Models/FindingAcceptance.php", "'status'"),
)
checker.check(
    "FindingAcceptance has code_change_correlated field",
    file_contains("app/Models/FindingAcceptance.php", "code_change_correlated"),
)
checker.check(
    "FindingAcceptance has bulk_resolved field",
    file_contains("app/Models/FindingAcceptance.php", "bulk_resolved"),
)

# AcceptanceTrackingService
checker.check(
    "AcceptanceTrackingService exists",
    file_exists("app/Services/AcceptanceTrackingService.php"),
)
checker.check(
    "AcceptanceTrackingService classifies thread state",
    file_contains("app/Services/AcceptanceTrackingService.php", "classifyThreadState"),
)
checker.check(
    "AcceptanceTrackingService detects bulk resolution",
    file_contains("app/Services/AcceptanceTrackingService.php", "detectBulkResolution"),
)
checker.check(
    "AcceptanceTrackingService correlates code changes",
    file_contains("app/Services/AcceptanceTrackingService.php", "correlateCodeChange"),
)
checker.check(
    "AcceptanceTrackingService identifies AI discussions",
    file_contains("app/Services/AcceptanceTrackingService.php", "isAiCreatedDiscussion"),
)

# GitLabClient extension
checker.check(
    "GitLabClient has compareBranches method",
    file_contains("app/Services/GitLabClient.php", "compareBranches"),
)

# Jobs
checker.check(
    "ProcessAcceptanceTracking job exists",
    file_exists("app/Jobs/ProcessAcceptanceTracking.php"),
)
checker.check(
    "ProcessAcceptanceTracking handles MR merge",
    file_contains("app/Jobs/ProcessAcceptanceTracking.php", "listMergeRequestDiscussions"),
)
checker.check(
    "ProcessThreadResolution job exists",
    file_exists("app/Jobs/ProcessThreadResolution.php"),
)
checker.check(
    "ProcessCodeChangeCorrelation job exists",
    file_exists("app/Jobs/ProcessCodeChangeCorrelation.php"),
)
checker.check(
    "ProcessCodeChangeCorrelation uses compareBranches",
    file_contains("app/Jobs/ProcessCodeChangeCorrelation.php", "compareBranches"),
)

# WebhookController wiring
checker.check(
    "WebhookController dispatches acceptance tracking",
    file_contains("app/Http/Controllers/WebhookController.php", "ProcessAcceptanceTracking"),
)
checker.check(
    "WebhookController dispatches code change correlation",
    file_contains("app/Http/Controllers/WebhookController.php", "ProcessCodeChangeCorrelation"),
)

# Task model relationship
checker.check(
    "Task has findingAcceptances relationship",
    file_contains("app/Models/Task.php", "findingAcceptances"),
)

# Tests
checker.check(
    "AcceptanceTrackingService unit test exists",
    file_exists("tests/Unit/Services/AcceptanceTrackingServiceTest.php"),
)
checker.check(
    "ProcessAcceptanceTracking test exists",
    file_exists("tests/Feature/Jobs/ProcessAcceptanceTrackingTest.php"),
)
checker.check(
    "ProcessThreadResolution test exists",
    file_exists("tests/Feature/Jobs/ProcessThreadResolutionTest.php"),
)
checker.check(
    "ProcessCodeChangeCorrelation test exists",
    file_exists("tests/Feature/Jobs/ProcessCodeChangeCorrelationTest.php"),
)
checker.check(
    "Webhook acceptance tracking integration test exists",
    file_exists("tests/Feature/WebhookAcceptanceTrackingTest.php"),
)

# ============================================================
#  Summary
# ============================================================
checker.summary()
