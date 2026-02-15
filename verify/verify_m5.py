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
#  Summary
# ============================================================
checker.summary()
