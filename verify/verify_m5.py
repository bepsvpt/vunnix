#!/usr/bin/env python3
"""Vunnix M5 — Admin & Configuration verification.

Checks implemented M5 tasks. Run from project root: python3 verify/verify_m5.py

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
print("  VUNNIX M5 — Admin & Configuration Verification")
print("=" * 60)

# ============================================================
#  T88: Admin page — project management
# ============================================================
section("T88: Admin Page — Project Management")

checker.check(
    "AdminProjectController exists",
    file_exists("app/Http/Controllers/Api/AdminProjectController.php"),
)
checker.check(
    "AdminProjectController has enable method",
    file_contains("app/Http/Controllers/Api/AdminProjectController.php", "enable"),
)
checker.check(
    "AdminProjectController has disable method",
    file_contains("app/Http/Controllers/Api/AdminProjectController.php", "disable"),
)
checker.check(
    "Admin project routes registered",
    file_contains("routes/api.php", "admin/projects"),
)
checker.check(
    "AdminProjectList component exists",
    file_exists("resources/js/components/AdminProjectList.vue"),
)
checker.check(
    "AdminProjectList test exists",
    file_exists("resources/js/components/AdminProjectList.test.js"),
)
checker.check(
    "AdminPage exists",
    file_exists("resources/js/pages/AdminPage.vue"),
)
checker.check(
    "Admin store has fetchProjects",
    file_contains("resources/js/stores/admin.js", "fetchProjects"),
)

# ============================================================
#  T89: Admin page — role management
# ============================================================
section("T89: Admin Page — Role Management")

checker.check(
    "AdminRoleController exists",
    file_exists("app/Http/Controllers/Api/AdminRoleController.php"),
)
checker.check(
    "Admin role routes registered",
    file_contains("routes/api.php", "admin/roles"),
)
checker.check(
    "AdminRoleList component exists",
    file_exists("resources/js/components/AdminRoleList.vue"),
)
checker.check(
    "AdminRoleAssignments component exists",
    file_exists("resources/js/components/AdminRoleAssignments.vue"),
)
checker.check(
    "AdminRoleList test exists",
    file_exists("resources/js/components/AdminRoleList.test.js"),
)
checker.check(
    "AdminRoleAssignments test exists",
    file_exists("resources/js/components/AdminRoleAssignments.test.js"),
)
checker.check(
    "Admin store has fetchRoles",
    file_contains("resources/js/stores/admin.js", "fetchRoles"),
)
checker.check(
    "Admin store has createRole",
    file_contains("resources/js/stores/admin.js", "createRole"),
)
checker.check(
    "Admin store has assignRole",
    file_contains("resources/js/stores/admin.js", "assignRole"),
)

# ============================================================
#  T90: Admin page — global settings
# ============================================================
section("T90: Admin Page — Global Settings")

checker.check(
    "AdminSettingsController exists",
    file_exists("app/Http/Controllers/Api/AdminSettingsController.php"),
)
checker.check(
    "Admin settings routes registered",
    file_contains("routes/api.php", "admin/settings"),
)
checker.check(
    "AdminGlobalSettings component exists",
    file_exists("resources/js/components/AdminGlobalSettings.vue"),
)
checker.check(
    "AdminGlobalSettings test exists",
    file_exists("resources/js/components/AdminGlobalSettings.test.js"),
)
checker.check(
    "Admin store has fetchSettings",
    file_contains("resources/js/stores/admin.js", "fetchSettings"),
)
checker.check(
    "Admin store has updateSettings",
    file_contains("resources/js/stores/admin.js", "updateSettings"),
)
checker.check(
    "Admin store tracks API key status",
    file_contains("resources/js/stores/admin.js", "apiKeyConfigured"),
)

# ============================================================
#  T91: Per-project configuration
# ============================================================
section("T91: Per-Project Configuration")

checker.check(
    "AdminProjectConfigController exists",
    file_exists("app/Http/Controllers/Api/AdminProjectConfigController.php"),
)
checker.check(
    "Project config routes registered",
    file_contains("routes/api.php", "config"),
)
checker.check(
    "AdminProjectConfig component exists",
    file_exists("resources/js/components/AdminProjectConfig.vue"),
)
checker.check(
    "AdminProjectConfig test exists",
    file_exists("resources/js/components/AdminProjectConfig.test.js"),
)
checker.check(
    "Admin store has fetchProjectConfig",
    file_contains("resources/js/stores/admin.js", "fetchProjectConfig"),
)
checker.check(
    "Admin store has updateProjectConfig",
    file_contains("resources/js/stores/admin.js", "updateProjectConfig"),
)

# ============================================================
#  T92: Optional .vunnix.toml support
# ============================================================
section("T92: .vunnix.toml Support")

checker.check(
    "VunnixTomlService exists",
    file_exists("app/Services/VunnixTomlService.php"),
)
checker.check(
    "VunnixTomlService parses TOML content",
    file_contains("app/Services/VunnixTomlService.php", "parse"),
)

# ============================================================
#  T93: PRD template management
# ============================================================
section("T93: PRD Template Management")

checker.check(
    "PrdTemplateController exists",
    file_exists("app/Http/Controllers/Api/PrdTemplateController.php"),
)
checker.check(
    "PRD template routes registered",
    file_contains("routes/api.php", "prd-template"),
)
checker.check(
    "AdminPrdTemplate component exists",
    file_exists("resources/js/components/AdminPrdTemplate.vue"),
)
checker.check(
    "AdminPrdTemplate test exists",
    file_exists("resources/js/components/AdminPrdTemplate.test.js"),
)
checker.check(
    "Admin store has fetchPrdTemplate",
    file_contains("resources/js/stores/admin.js", "fetchPrdTemplate"),
)
checker.check(
    "Admin store has updatePrdTemplate",
    file_contains("resources/js/stores/admin.js", "updatePrdTemplate"),
)
checker.check(
    "Admin store has global PRD template support",
    file_contains("resources/js/stores/admin.js", "fetchGlobalPrdTemplate"),
)

# ============================================================
#  T94: Cost monitoring — 4 alert rules
# ============================================================
section("T94: Cost Monitoring — 4 Alert Rules")

# Migration
checker.check(
    "cost_alerts migration exists",
    file_exists("database/migrations/2026_02_15_060000_create_cost_alerts_table.php"),
)
checker.check(
    "Migration creates cost_alerts table",
    file_contains("database/migrations/2026_02_15_060000_create_cost_alerts_table.php", "cost_alerts"),
)

# Model
checker.check(
    "CostAlert model exists",
    file_exists("app/Models/CostAlert.php"),
)
checker.check(
    "CostAlert has rule field",
    file_contains("app/Models/CostAlert.php", "'rule'"),
)
checker.check(
    "CostAlert has severity field",
    file_contains("app/Models/CostAlert.php", "'severity'"),
)
checker.check(
    "CostAlert has active scope",
    file_contains("app/Models/CostAlert.php", "scopeActive"),
)
checker.check(
    "CostAlert casts context as array",
    file_contains("app/Models/CostAlert.php", "'context' => 'array'"),
)

# Service
checker.check(
    "CostAlertService exists",
    file_exists("app/Services/CostAlertService.php"),
)
checker.check(
    "CostAlertService has evaluateAll method",
    file_contains("app/Services/CostAlertService.php", "evaluateAll"),
)
checker.check(
    "CostAlertService has evaluateMonthlyAnomaly",
    file_contains("app/Services/CostAlertService.php", "evaluateMonthlyAnomaly"),
)
checker.check(
    "CostAlertService has evaluateDailySpike",
    file_contains("app/Services/CostAlertService.php", "evaluateDailySpike"),
)
checker.check(
    "CostAlertService has evaluateSingleTaskOutlier",
    file_contains("app/Services/CostAlertService.php", "evaluateSingleTaskOutlier"),
)
checker.check(
    "CostAlertService has evaluateApproachingProjection",
    file_contains("app/Services/CostAlertService.php", "evaluateApproachingProjection"),
)
checker.check(
    "CostAlertService has dedup check",
    file_contains("app/Services/CostAlertService.php", "isDuplicateToday"),
)

# Controller
checker.check(
    "CostAlertController exists",
    file_exists("app/Http/Controllers/Api/CostAlertController.php"),
)
checker.check(
    "CostAlertController has index method",
    file_contains("app/Http/Controllers/Api/CostAlertController.php", "function index"),
)
checker.check(
    "CostAlertController has acknowledge method",
    file_contains("app/Http/Controllers/Api/CostAlertController.php", "function acknowledge"),
)
checker.check(
    "Cost alert routes registered",
    file_contains("routes/api.php", "cost-alerts"),
)

# Scheduled command
checker.check(
    "EvaluateCostAlerts command exists",
    file_exists("app/Console/Commands/EvaluateCostAlerts.php"),
)
checker.check(
    "EvaluateCostAlerts has correct signature",
    file_contains("app/Console/Commands/EvaluateCostAlerts.php", "cost-alerts:evaluate"),
)
checker.check(
    "Schedule entry in console routes",
    file_contains("routes/console.php", "cost-alerts:evaluate"),
)

# TaskObserver integration
checker.check(
    "TaskObserver wires single-task outlier alert",
    file_contains("app/Observers/TaskObserver.php", "evaluateSingleTaskOutlier"),
)
checker.check(
    "TaskObserver imports CostAlertService",
    file_contains("app/Observers/TaskObserver.php", "CostAlertService"),
)

# Frontend — Vue
checker.check(
    "DashboardCost has cost-alerts section",
    file_contains("resources/js/components/DashboardCost.vue", 'data-testid="cost-alerts"'),
)
checker.check(
    "DashboardCost has acknowledge handler",
    file_contains("resources/js/components/DashboardCost.vue", "handleAcknowledge"),
)
checker.check(
    "DashboardCost imports admin store",
    file_contains("resources/js/components/DashboardCost.vue", "useAdminStore"),
)
checker.check(
    "DashboardCost has severity color mapping",
    file_contains("resources/js/components/DashboardCost.vue", "severityColors"),
)
checker.check(
    "DashboardCost has rule label mapping",
    file_contains("resources/js/components/DashboardCost.vue", "ruleLabels"),
)

# Frontend — Pinia stores
checker.check(
    "Dashboard store has costAlerts ref",
    file_contains("resources/js/stores/dashboard.js", "costAlerts"),
)
checker.check(
    "Dashboard store has fetchCostAlerts action",
    file_contains("resources/js/stores/dashboard.js", "fetchCostAlerts"),
)
checker.check(
    "Admin store has costAlerts ref",
    file_contains("resources/js/stores/admin.js", "costAlerts"),
)
checker.check(
    "Admin store has fetchCostAlerts action",
    file_contains("resources/js/stores/admin.js", "fetchCostAlerts"),
)
checker.check(
    "Admin store has acknowledgeCostAlert action",
    file_contains("resources/js/stores/admin.js", "acknowledgeCostAlert"),
)

# Tests
checker.check(
    "CostAlertService test exists",
    file_exists("tests/Feature/Services/CostAlertServiceTest.php"),
)
checker.check(
    "TaskObserver cost alert test exists",
    file_exists("tests/Feature/Observers/TaskObserverCostAlertTest.php"),
)
checker.check(
    "EvaluateCostAlerts command test exists",
    file_exists("tests/Feature/Console/EvaluateCostAlertsTest.php"),
)
checker.check(
    "CostAlertController API test exists",
    file_exists("tests/Feature/Http/Controllers/Api/CostAlertControllerTest.php"),
)
checker.check(
    "DashboardCost component test exists",
    file_exists("resources/js/components/DashboardCost.test.js"),
)
checker.check(
    "DashboardCost test covers alerts",
    file_contains("resources/js/components/DashboardCost.test.js", "cost-alerts"),
)

# ============================================================
#  T95: Over-reliance detection
# ============================================================
section("T95: Over-Reliance Detection")

# Migration
checker.check(
    "overreliance_alerts migration exists",
    file_exists("database/migrations/2026_02_15_070000_create_overreliance_alerts_table.php"),
)
checker.check(
    "Migration creates overreliance_alerts table",
    file_contains("database/migrations/2026_02_15_070000_create_overreliance_alerts_table.php", "overreliance_alerts"),
)

# Model
checker.check(
    "OverrelianceAlert model exists",
    file_exists("app/Models/OverrelianceAlert.php"),
)
checker.check(
    "OverrelianceAlert has rule field",
    file_contains("app/Models/OverrelianceAlert.php", "'rule'"),
)
checker.check(
    "OverrelianceAlert has severity field",
    file_contains("app/Models/OverrelianceAlert.php", "'severity'"),
)
checker.check(
    "OverrelianceAlert has active scope",
    file_contains("app/Models/OverrelianceAlert.php", "scopeActive"),
)
checker.check(
    "OverrelianceAlert casts context as array",
    file_contains("app/Models/OverrelianceAlert.php", "'context' => 'array'"),
)

# Service — 4 detection rules
checker.check(
    "OverrelianceDetectionService exists",
    file_exists("app/Services/OverrelianceDetectionService.php"),
)
checker.check(
    "Service has evaluateAll method",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateAll"),
)
checker.check(
    "Service has evaluateHighAcceptanceRate",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateHighAcceptanceRate"),
)
checker.check(
    "Service has evaluateCriticalAcceptanceRate",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateCriticalAcceptanceRate"),
)
checker.check(
    "Service has evaluateBulkResolution",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateBulkResolution"),
)
checker.check(
    "Service has evaluateZeroReactions",
    file_contains("app/Services/OverrelianceDetectionService.php", "evaluateZeroReactions"),
)

# Controller
checker.check(
    "OverrelianceAlertController exists",
    file_exists("app/Http/Controllers/Api/OverrelianceAlertController.php"),
)
checker.check(
    "OverrelianceAlertController has index method",
    file_contains("app/Http/Controllers/Api/OverrelianceAlertController.php", "function index"),
)
checker.check(
    "OverrelianceAlertController has acknowledge method",
    file_contains("app/Http/Controllers/Api/OverrelianceAlertController.php", "function acknowledge"),
)
checker.check(
    "Overreliance alert routes registered",
    file_contains("routes/api.php", "overreliance-alerts"),
)

# Scheduled command
checker.check(
    "EvaluateOverrelianceAlerts command exists",
    file_exists("app/Console/Commands/EvaluateOverrelianceAlerts.php"),
)
checker.check(
    "EvaluateOverrelianceAlerts has correct signature",
    file_contains("app/Console/Commands/EvaluateOverrelianceAlerts.php", "overreliance:evaluate"),
)
checker.check(
    "Schedule entry for overreliance:evaluate",
    file_contains("routes/console.php", "overreliance:evaluate"),
)

# Frontend — DashboardQuality component
checker.check(
    "DashboardQuality has overreliance-alerts section",
    file_contains("resources/js/components/DashboardQuality.vue", 'data-testid="overreliance-alerts"'),
)
checker.check(
    "DashboardQuality has acknowledge handler",
    file_contains("resources/js/components/DashboardQuality.vue", "handleOverrelianceAcknowledge"),
)
checker.check(
    "DashboardQuality imports admin store",
    file_contains("resources/js/components/DashboardQuality.vue", "useAdminStore"),
)
checker.check(
    "DashboardQuality has severity color mapping",
    file_contains("resources/js/components/DashboardQuality.vue", "overrelianceSeverityColors"),
)
checker.check(
    "DashboardQuality has rule label mapping",
    file_contains("resources/js/components/DashboardQuality.vue", "overrelianceRuleLabels"),
)

# Frontend — Pinia stores
checker.check(
    "Dashboard store has overrelianceAlerts ref",
    file_contains("resources/js/stores/dashboard.js", "overrelianceAlerts"),
)
checker.check(
    "Dashboard store has fetchOverrelianceAlerts action",
    file_contains("resources/js/stores/dashboard.js", "fetchOverrelianceAlerts"),
)
checker.check(
    "Admin store has overrelianceAlerts ref",
    file_contains("resources/js/stores/admin.js", "overrelianceAlerts"),
)
checker.check(
    "Admin store has fetchOverrelianceAlerts action",
    file_contains("resources/js/stores/admin.js", "fetchOverrelianceAlerts"),
)
checker.check(
    "Admin store has acknowledgeOverrelianceAlert action",
    file_contains("resources/js/stores/admin.js", "acknowledgeOverrelianceAlert"),
)

# Tests
checker.check(
    "OverrelianceDetectionService test exists",
    file_exists("tests/Feature/Services/OverrelianceDetectionServiceTest.php"),
)
checker.check(
    "OverrelianceAlertController API test exists",
    file_exists("tests/Feature/Http/Controllers/Api/OverrelianceAlertControllerTest.php"),
)
checker.check(
    "EvaluateOverrelianceAlerts command test exists",
    file_exists("tests/Feature/Console/EvaluateOverrelianceAlertsTest.php"),
)
checker.check(
    "DashboardQuality component test exists",
    file_exists("resources/js/components/DashboardQuality.test.js"),
)
checker.check(
    "DashboardQuality test covers overreliance alerts",
    file_contains("resources/js/components/DashboardQuality.test.js", "overreliance"),
)

# ============================================================
#  T96: Dead letter queue — backend
# ============================================================
section("T96: Dead Letter Queue — Backend")

# Migration
checker.check(
    "DLQ retry columns migration exists",
    file_exists("database/migrations/2026_02_15_080000_add_retry_columns_to_dead_letter_queue_table.php"),
)

# Model
checker.check(
    "DeadLetterEntry model exists",
    file_exists("app/Models/DeadLetterEntry.php"),
)
checker.check(
    "DeadLetterEntry has task_id fillable",
    file_contains("app/Models/DeadLetterEntry.php", "'task_id'"),
)
checker.check(
    "DeadLetterEntry has retried fillable",
    file_contains("app/Models/DeadLetterEntry.php", "'retried'"),
)
checker.check(
    "DeadLetterEntry has retried_task_id fillable",
    file_contains("app/Models/DeadLetterEntry.php", "'retried_task_id'"),
)
checker.check(
    "DeadLetterEntry has scopeActive",
    file_contains("app/Models/DeadLetterEntry.php", "scopeActive"),
)
checker.check(
    "DeadLetterEntry has task relationship",
    file_contains("app/Models/DeadLetterEntry.php", "function task()"),
)
checker.check(
    "DeadLetterEntry has retriedTask relationship",
    file_contains("app/Models/DeadLetterEntry.php", "function retriedTask()"),
)
checker.check(
    "DeadLetterEntry factory exists",
    file_exists("database/factories/DeadLetterEntryFactory.php"),
)

# Service
checker.check(
    "DeadLetterService exists",
    file_exists("app/Services/DeadLetterService.php"),
)
checker.check(
    "DeadLetterService has retry method",
    file_contains("app/Services/DeadLetterService.php", "function retry"),
)
checker.check(
    "DeadLetterService has dismiss method",
    file_contains("app/Services/DeadLetterService.php", "function dismiss"),
)
checker.check(
    "DeadLetterService dispatches ProcessTask on retry",
    file_contains("app/Services/DeadLetterService.php", "ProcessTask"),
)

# FailureHandler passes task_id
checker.check(
    "FailureHandler passes task_id to DLQ",
    file_contains("app/Services/FailureHandler.php", "'task_id'"),
)

# Attempt history wiring
checker.check(
    "ProcessTask has attemptHistory property",
    file_contains("app/Jobs/ProcessTask.php", "attemptHistory"),
)
checker.check(
    "ProcessTaskResult has attemptHistory property",
    file_contains("app/Jobs/ProcessTaskResult.php", "attemptHistory"),
)
checker.check(
    "RetryWithBackoff records attempt history",
    file_contains("app/Jobs/Middleware/RetryWithBackoff.php", "attemptHistory"),
)

# Tests
checker.check(
    "DeadLetterService test exists",
    file_exists("tests/Feature/Services/DeadLetterServiceTest.php"),
)
checker.check(
    "DeadLetterService test covers retry",
    file_contains("tests/Feature/Services/DeadLetterServiceTest.php", "retry"),
)
checker.check(
    "DeadLetterService test covers dismiss",
    file_contains("tests/Feature/Services/DeadLetterServiceTest.php", "dismiss"),
)

# ============================================================
#  T97 — Dead letter queue — admin UI
# ============================================================
section("T97 — Dead letter queue — admin UI")

# Controller
checker.check(
    "DeadLetterController exists",
    file_exists("app/Http/Controllers/Api/DeadLetterController.php"),
)
checker.check(
    "DeadLetterController has index method",
    file_contains("app/Http/Controllers/Api/DeadLetterController.php", "function index"),
)
checker.check(
    "DeadLetterController has show method",
    file_contains("app/Http/Controllers/Api/DeadLetterController.php", "function show"),
)
checker.check(
    "DeadLetterController has retry method",
    file_contains("app/Http/Controllers/Api/DeadLetterController.php", "function retry"),
)
checker.check(
    "DeadLetterController has dismiss method",
    file_contains("app/Http/Controllers/Api/DeadLetterController.php", "function dismiss"),
)
checker.check(
    "DeadLetterController has authorizeAdmin method",
    file_contains("app/Http/Controllers/Api/DeadLetterController.php", "authorizeAdmin"),
)

# Routes
checker.check(
    "DLQ routes registered in api.php",
    file_contains("routes/api.php", "/admin/dead-letter"),
)

# Vue component
checker.check(
    "AdminDeadLetterQueue component exists",
    file_exists("resources/js/components/AdminDeadLetterQueue.vue"),
)
checker.check(
    "Component has retry button test ID",
    file_contains("resources/js/components/AdminDeadLetterQueue.vue", "dlq-retry-btn"),
)
checker.check(
    "Component has dismiss button test ID",
    file_contains("resources/js/components/AdminDeadLetterQueue.vue", "dlq-dismiss-btn"),
)

# Admin store
checker.check(
    "Admin store has fetchDeadLetterEntries",
    file_contains("resources/js/stores/admin.js", "fetchDeadLetterEntries"),
)
checker.check(
    "Admin store has retryDeadLetterEntry",
    file_contains("resources/js/stores/admin.js", "retryDeadLetterEntry"),
)
checker.check(
    "Admin store has dismissDeadLetterEntry",
    file_contains("resources/js/stores/admin.js", "dismissDeadLetterEntry"),
)

# AdminPage integration
checker.check(
    "AdminPage includes dlq tab",
    file_contains("resources/js/pages/AdminPage.vue", "dlq"),
)

# Tests
checker.check(
    "Component test file exists",
    file_exists("resources/js/components/AdminDeadLetterQueue.test.js"),
)
checker.check(
    "Controller test file exists",
    file_exists("tests/Feature/Http/Controllers/Api/DeadLetterControllerTest.php"),
)

# ============================================================
#  T98: Team chat notifications — webhook integration
# ============================================================
section("T98: Team Chat Notifications — Webhook Integration")

# Service files
checker.check(
    "TeamChatNotificationService exists",
    file_exists("app/Services/TeamChat/TeamChatNotificationService.php"),
)
checker.check(
    "ChatFormatterInterface exists",
    file_exists("app/Services/TeamChat/ChatFormatterInterface.php"),
)
checker.check(
    "SlackFormatter exists",
    file_exists("app/Services/TeamChat/SlackFormatter.php"),
)
checker.check(
    "MattermostFormatter exists",
    file_exists("app/Services/TeamChat/MattermostFormatter.php"),
)
checker.check(
    "GoogleChatFormatter exists",
    file_exists("app/Services/TeamChat/GoogleChatFormatter.php"),
)
checker.check(
    "GenericFormatter exists",
    file_exists("app/Services/TeamChat/GenericFormatter.php"),
)

# Service methods
checker.check(
    "Service has send method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function send"),
)
checker.check(
    "Service has sendTest method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function sendTest"),
)
checker.check(
    "Service has isEnabled method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function isEnabled"),
)
checker.check(
    "Service has isCategoryEnabled method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function isCategoryEnabled"),
)
checker.check(
    "Service checks GlobalSetting for webhook URL",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "team_chat_webhook_url"),
)
checker.check(
    "Service checks GlobalSetting for platform",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "team_chat_platform"),
)

# Formatters have correct structure
checker.check(
    "SlackFormatter uses Block Kit attachments",
    file_contains("app/Services/TeamChat/SlackFormatter.php", "attachments"),
)
checker.check(
    "MattermostFormatter uses attachments",
    file_contains("app/Services/TeamChat/MattermostFormatter.php", "attachments"),
)
checker.check(
    "GoogleChatFormatter uses cardsV2",
    file_contains("app/Services/TeamChat/GoogleChatFormatter.php", "cardsV2"),
)
checker.check(
    "GenericFormatter uses plain text",
    file_contains("app/Services/TeamChat/GenericFormatter.php", "'text'"),
)

# Settings defaults
checker.check(
    "GlobalSetting defaults include team_chat_enabled",
    file_contains("app/Models/GlobalSetting.php", "team_chat_enabled"),
)
checker.check(
    "GlobalSetting defaults include team_chat_categories",
    file_contains("app/Models/GlobalSetting.php", "team_chat_categories"),
)

# Controller endpoint
checker.check(
    "AdminSettingsController has testWebhook method",
    file_contains("app/Http/Controllers/Api/AdminSettingsController.php", "testWebhook"),
)
checker.check(
    "Test-webhook route registered",
    file_contains("routes/api.php", "test-webhook"),
)

# Vue component
checker.check(
    "AdminGlobalSettings has enabled toggle",
    file_contains("resources/js/components/AdminGlobalSettings.vue", "team_chat_enabled"),
)
checker.check(
    "AdminGlobalSettings has test webhook button",
    file_contains("resources/js/components/AdminGlobalSettings.vue", "test-webhook-btn"),
)
checker.check(
    "AdminGlobalSettings has notification categories",
    file_contains("resources/js/components/AdminGlobalSettings.vue", "team_chat_categories"),
)

# Admin store
checker.check(
    "Admin store has testWebhook action",
    file_contains("resources/js/stores/admin.js", "testWebhook"),
)

# Tests
checker.check(
    "SlackFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/SlackFormatterTest.php"),
)
checker.check(
    "MattermostFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/MattermostFormatterTest.php"),
)
checker.check(
    "GoogleChatFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/GoogleChatFormatterTest.php"),
)
checker.check(
    "GenericFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/GenericFormatterTest.php"),
)
checker.check(
    "TeamChatNotificationService feature test exists",
    file_exists("tests/Feature/Services/TeamChatNotificationServiceTest.php"),
)
checker.check(
    "Webhook API test exists",
    file_exists("tests/Feature/Http/Controllers/Api/AdminSettingsControllerWebhookTest.php"),
)

# ============================================================
#  T99: Team chat notifications — event routing
# ============================================================
section("T99: Team Chat Notifications — Event Routing")

# AlertEvent model & migration
checker.check(
    "alert_events migration exists",
    file_exists("database/migrations/2026_02_15_090000_create_alert_events_table.php"),
)
checker.check(
    "Migration creates alert_events table",
    file_contains("database/migrations/2026_02_15_090000_create_alert_events_table.php", "alert_events"),
)
checker.check(
    "AlertEvent model exists",
    file_exists("app/Models/AlertEvent.php"),
)
checker.check(
    "AlertEvent has alert_type field",
    file_contains("app/Models/AlertEvent.php", "'alert_type'"),
)
checker.check(
    "AlertEvent has status field",
    file_contains("app/Models/AlertEvent.php", "'status'"),
)
checker.check(
    "AlertEvent has scopeActive",
    file_contains("app/Models/AlertEvent.php", "scopeActive"),
)
checker.check(
    "AlertEvent has scopeOfType",
    file_contains("app/Models/AlertEvent.php", "scopeOfType"),
)
checker.check(
    "AlertEvent factory exists",
    file_exists("database/factories/AlertEventFactory.php"),
)

# AlertEventService
checker.check(
    "AlertEventService exists",
    file_exists("app/Services/AlertEventService.php"),
)
checker.check(
    "AlertEventService has evaluateAll method",
    file_contains("app/Services/AlertEventService.php", "evaluateAll"),
)
checker.check(
    "AlertEventService has evaluateApiOutage",
    file_contains("app/Services/AlertEventService.php", "evaluateApiOutage"),
)
checker.check(
    "AlertEventService has evaluateHighFailureRate",
    file_contains("app/Services/AlertEventService.php", "evaluateHighFailureRate"),
)
checker.check(
    "AlertEventService has evaluateQueueDepth",
    file_contains("app/Services/AlertEventService.php", "evaluateQueueDepth"),
)
checker.check(
    "AlertEventService has evaluateAuthFailure",
    file_contains("app/Services/AlertEventService.php", "evaluateAuthFailure"),
)
checker.check(
    "AlertEventService has evaluateDiskUsage",
    file_contains("app/Services/AlertEventService.php", "evaluateDiskUsage"),
)
checker.check(
    "AlertEventService has notifyAlert (detection notification)",
    file_contains("app/Services/AlertEventService.php", "function notifyAlert"),
)
checker.check(
    "AlertEventService has notifyRecovery (recovery notification)",
    file_contains("app/Services/AlertEventService.php", "function notifyRecovery"),
)
checker.check(
    "AlertEventService has notifyCostAlert",
    file_contains("app/Services/AlertEventService.php", "function notifyCostAlert"),
)
checker.check(
    "AlertEventService has notifyTaskCompletion",
    file_contains("app/Services/AlertEventService.php", "function notifyTaskCompletion"),
)
checker.check(
    "AlertEventService uses TeamChatNotificationService",
    file_contains("app/Services/AlertEventService.php", "TeamChatNotificationService"),
)

# Alert deduplication: active alert check prevents re-trigger
checker.check(
    "Alert dedup: checks for active alert before creating new",
    file_contains("app/Services/AlertEventService.php", "! $activeAlert"),
)

# EvaluateSystemAlerts command
checker.check(
    "EvaluateSystemAlerts command exists",
    file_exists("app/Console/Commands/EvaluateSystemAlerts.php"),
)
checker.check(
    "EvaluateSystemAlerts has correct signature",
    file_contains("app/Console/Commands/EvaluateSystemAlerts.php", "system-alerts:evaluate"),
)
checker.check(
    "Schedule entry for system-alerts:evaluate",
    file_contains("routes/console.php", "system-alerts:evaluate"),
)

# Task completion listener
checker.check(
    "SendTaskCompletionNotification listener exists",
    file_exists("app/Listeners/SendTaskCompletionNotification.php"),
)
checker.check(
    "Listener handles TaskStatusChanged event",
    file_contains("app/Listeners/SendTaskCompletionNotification.php", "TaskStatusChanged"),
)
checker.check(
    "Listener uses cache for idempotency",
    file_contains("app/Listeners/SendTaskCompletionNotification.php", "Cache"),
)

# Cost alert → team chat wiring
checker.check(
    "EvaluateCostAlerts wires AlertEventService",
    file_contains("app/Console/Commands/EvaluateCostAlerts.php", "AlertEventService"),
)
checker.check(
    "EvaluateCostAlerts calls notifyCostAlert",
    file_contains("app/Console/Commands/EvaluateCostAlerts.php", "notifyCostAlert"),
)
checker.check(
    "TaskObserver wires AlertEventService for single-task cost alerts",
    file_contains("app/Observers/TaskObserver.php", "AlertEventService"),
)

# Tests
checker.check(
    "AlertEventService test exists",
    file_exists("tests/Feature/Services/AlertEventServiceTest.php"),
)
checker.check(
    "Test covers API outage detection",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "api_outage"),
)
checker.check(
    "Test covers high failure rate",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "high_failure_rate"),
)
checker.check(
    "Test covers queue depth",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "queue_depth"),
)
checker.check(
    "Test covers auth failure",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "auth_failure"),
)
checker.check(
    "Test covers alert deduplication",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "deduplicat"),
)
checker.check(
    "Test covers recovery notification",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "recovery"),
)
checker.check(
    "Test covers task completion notification",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "notifyTaskCompletion"),
)
checker.check(
    "Test covers cost alert notification",
    file_contains("tests/Feature/Services/AlertEventServiceTest.php", "notifyCostAlert"),
)

# ============================================================
#  T100: API versioning + external access
# ============================================================
section("T100: API Versioning + External Access")

# Model
checker.check(
    "ApiKey model exists",
    file_exists("app/Models/ApiKey.php"),
)
checker.check(
    "ApiKey has isActive method",
    file_contains("app/Models/ApiKey.php", "isActive"),
)
checker.check(
    "ApiKey has isRevoked method",
    file_contains("app/Models/ApiKey.php", "isRevoked"),
)
checker.check(
    "ApiKey has isExpired method",
    file_contains("app/Models/ApiKey.php", "isExpired"),
)
checker.check(
    "ApiKey has active scope",
    file_contains("app/Models/ApiKey.php", "scopeActive"),
)
checker.check(
    "ApiKey has recordUsage method",
    file_contains("app/Models/ApiKey.php", "recordUsage"),
)
checker.check(
    "ApiKey factory exists",
    file_exists("database/factories/ApiKeyFactory.php"),
)
checker.check(
    "User model has apiKeys relationship",
    file_contains("app/Models/User.php", "apiKeys"),
)

# Service
checker.check(
    "ApiKeyService exists",
    file_exists("app/Services/ApiKeyService.php"),
)
checker.check(
    "ApiKeyService has generate method",
    file_contains("app/Services/ApiKeyService.php", "function generate"),
)
checker.check(
    "ApiKeyService has resolveUser method",
    file_contains("app/Services/ApiKeyService.php", "function resolveUser"),
)
checker.check(
    "ApiKeyService has revoke method",
    file_contains("app/Services/ApiKeyService.php", "function revoke"),
)
checker.check(
    "ApiKeyService uses SHA-256 hashing (D152)",
    file_contains("app/Services/ApiKeyService.php", "sha256"),
)

# Middleware
checker.check(
    "AuthenticateApiKey middleware exists",
    file_exists("app/Http/Middleware/AuthenticateApiKey.php"),
)
checker.check(
    "AuthenticateSessionOrApiKey middleware exists",
    file_exists("app/Http/Middleware/AuthenticateSessionOrApiKey.php"),
)
checker.check(
    "Middleware registered in bootstrap/app.php",
    file_contains("bootstrap/app.php", "api.key"),
)
checker.check(
    "Dual-auth middleware registered in bootstrap/app.php",
    file_contains("bootstrap/app.php", "auth.api_key_or_session"),
)

# Rate limiting
checker.check(
    "API key rate limiter registered",
    file_contains("app/Providers/AppServiceProvider.php", "api_key"),
)
checker.check(
    "Rate limit is per-key (60/min)",
    file_contains("app/Providers/AppServiceProvider.php", "perMinute(60)"),
)

# Controller
checker.check(
    "ApiKeyController exists",
    file_exists("app/Http/Controllers/Api/ApiKeyController.php"),
)
checker.check(
    "ApiKeyController has index method",
    file_contains("app/Http/Controllers/Api/ApiKeyController.php", "function index"),
)
checker.check(
    "ApiKeyController has store method",
    file_contains("app/Http/Controllers/Api/ApiKeyController.php", "function store"),
)
checker.check(
    "ApiKeyController has destroy method",
    file_contains("app/Http/Controllers/Api/ApiKeyController.php", "function destroy"),
)
checker.check(
    "CreateApiKeyRequest exists",
    file_exists("app/Http/Requests/CreateApiKeyRequest.php"),
)

# Admin controller
checker.check(
    "AdminApiKeyController exists",
    file_exists("app/Http/Controllers/Api/AdminApiKeyController.php"),
)
checker.check(
    "AdminApiKeyController has index method",
    file_contains("app/Http/Controllers/Api/AdminApiKeyController.php", "function index"),
)
checker.check(
    "AdminApiKeyController has destroy method",
    file_contains("app/Http/Controllers/Api/AdminApiKeyController.php", "function destroy"),
)

# External API routes
checker.check(
    "External API routes registered",
    file_contains("routes/api.php", "api.ext.tasks"),
)
checker.check(
    "External projects route registered",
    file_contains("routes/api.php", "api.ext.projects"),
)
checker.check(
    "API key routes registered",
    file_contains("routes/api.php", "api-keys"),
)
checker.check(
    "Admin API key routes registered",
    file_contains("routes/api.php", "admin/api-keys"),
)

# External stub controllers
checker.check(
    "ExternalTaskController exists",
    file_exists("app/Http/Controllers/Api/ExternalTaskController.php"),
)
checker.check(
    "ExternalProjectController exists",
    file_exists("app/Http/Controllers/Api/ExternalProjectController.php"),
)
checker.check(
    "ExternalMetricsController exists",
    file_exists("app/Http/Controllers/Api/ExternalMetricsController.php"),
)
checker.check(
    "ExternalActivityController exists",
    file_exists("app/Http/Controllers/Api/ExternalActivityController.php"),
)

# Tests
checker.check(
    "ApiKey model test exists",
    file_exists("tests/Feature/Models/ApiKeyTest.php"),
)
checker.check(
    "ApiKeyService test exists",
    file_exists("tests/Feature/Services/ApiKeyServiceTest.php"),
)
checker.check(
    "AuthenticateApiKey middleware test exists",
    file_exists("tests/Feature/Middleware/AuthenticateApiKeyTest.php"),
)
checker.check(
    "Rate limit test exists",
    file_exists("tests/Feature/Middleware/ApiKeyRateLimitTest.php"),
)
checker.check(
    "ApiKeyController test exists",
    file_exists("tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php"),
)
checker.check(
    "AdminApiKeyController test exists",
    file_exists("tests/Feature/Http/Controllers/Api/AdminApiKeyControllerTest.php"),
)
checker.check(
    "External API auth test exists",
    file_exists("tests/Feature/ExternalApiAuthTest.php"),
)

# ============================================================
#  Summary
# ============================================================
checker.summary()
