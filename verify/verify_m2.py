#!/usr/bin/env python3
"""Vunnix M2 — Path A Functional verification.

Checks implemented M2 tasks. Run from project root: python3 verify/verify_m2.py

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
print("  VUNNIX M2 — Path A Functional Verification")
print("=" * 60)

# ============================================================
#  T12: Webhook controller + middleware
# ============================================================
section("T12: Webhook Controller + Middleware")

# Controller
checker.check(
    "WebhookController exists",
    file_exists("app/Http/Controllers/WebhookController.php"),
)
checker.check(
    "WebhookController handles event types",
    file_contains(
        "app/Http/Controllers/WebhookController.php",
        "EVENT_MAP",
    ),
)
checker.check(
    "WebhookController parses MR events",
    file_contains(
        "app/Http/Controllers/WebhookController.php",
        "merge_request",
    ),
)
checker.check(
    "WebhookController parses Note events",
    file_contains(
        "app/Http/Controllers/WebhookController.php",
        "'note'",
    ),
)
checker.check(
    "WebhookController parses Issue events",
    file_contains(
        "app/Http/Controllers/WebhookController.php",
        "'issue'",
    ),
)
checker.check(
    "WebhookController parses Push events",
    file_contains(
        "app/Http/Controllers/WebhookController.php",
        "'push'",
    ),
)

# Middleware
checker.check(
    "VerifyWebhookToken middleware exists",
    file_exists("app/Http/Middleware/VerifyWebhookToken.php"),
)
checker.check(
    "Middleware validates X-Gitlab-Token",
    file_contains(
        "app/Http/Middleware/VerifyWebhookToken.php",
        "X-Gitlab-Token",
    ),
)
checker.check(
    "Middleware returns 401 on invalid token",
    file_contains(
        "app/Http/Middleware/VerifyWebhookToken.php",
        "401",
    ),
)

# Model
checker.check(
    "ProjectConfig model exists",
    file_exists("app/Models/ProjectConfig.php"),
)
checker.check(
    "ProjectConfig uses encrypted cast for webhook_secret",
    file_contains(
        "app/Models/ProjectConfig.php",
        "'encrypted'",
    ),
)

# Route registration
checker.check(
    "Webhook route registered",
    file_contains("routes/web.php", "/webhook"),
)
checker.check(
    "Webhook route uses verify middleware",
    file_contains("routes/web.php", "webhook.verify"),
)

# CSRF exclusion
checker.check(
    "CSRF exclusion configured for webhook",
    file_contains("bootstrap/app.php", "validateCsrfTokens")
    and file_contains("bootstrap/app.php", "webhook"),
)

# Middleware alias
checker.check(
    "webhook.verify middleware alias registered",
    file_contains("bootstrap/app.php", "webhook.verify"),
)

# Factory
checker.check(
    "ProjectConfig factory exists",
    file_exists("database/factories/ProjectConfigFactory.php"),
)

# Tests
checker.check(
    "Webhook middleware test exists",
    file_exists("tests/Feature/Middleware/VerifyWebhookTokenTest.php"),
)
checker.check(
    "Webhook controller test exists",
    file_exists("tests/Feature/WebhookControllerTest.php"),
)

# Project model relationship
checker.check(
    "Project model has projectConfig relationship",
    file_contains("app/Models/Project.php", "projectConfig"),
)

# ============================================================
#  T13: Event types + event router
# ============================================================
section("T13: Event Types + Event Router")

# Event base class
checker.check(
    "WebhookEvent base class exists",
    file_exists("app/Events/Webhook/WebhookEvent.php"),
)

# Event classes
event_classes = [
    "MergeRequestOpened",
    "MergeRequestUpdated",
    "MergeRequestMerged",
    "NoteOnMR",
    "NoteOnIssue",
    "IssueLabelChanged",
    "PushToMRBranch",
]
for cls in event_classes:
    checker.check(
        f"{cls} event class exists",
        file_exists(f"app/Events/Webhook/{cls}.php"),
    )
    checker.check(
        f"{cls} extends WebhookEvent",
        file_contains(f"app/Events/Webhook/{cls}.php", "extends WebhookEvent"),
    )
    checker.check(
        f"{cls} has type() method",
        file_contains(f"app/Events/Webhook/{cls}.php", "function type()"),
    )

# EventRouter service
checker.check(
    "EventRouter service exists",
    file_exists("app/Services/EventRouter.php"),
)
checker.check(
    "EventRouter has route() method",
    file_contains("app/Services/EventRouter.php", "function route("),
)
checker.check(
    "EventRouter has parseEvent() method",
    file_contains("app/Services/EventRouter.php", "function parseEvent("),
)
checker.check(
    "EventRouter implements bot filtering (D154)",
    file_contains("app/Services/EventRouter.php", "isBotNoteEvent"),
)
checker.check(
    "EventRouter implements @ai command parsing",
    file_contains("app/Services/EventRouter.php", "@ai"),
)
checker.check(
    "EventRouter handles help response (D155)",
    file_contains("app/Services/EventRouter.php", "help_response"),
)

# RoutingResult value object
checker.check(
    "RoutingResult class exists",
    file_exists("app/Services/RoutingResult.php"),
)
checker.check(
    "RoutingResult has intent property",
    file_contains("app/Services/RoutingResult.php", "intent"),
)
checker.check(
    "RoutingResult has priority property",
    file_contains("app/Services/RoutingResult.php", "priority"),
)

# PostHelpResponse job (D155)
checker.check(
    "PostHelpResponse job exists",
    file_exists("app/Jobs/PostHelpResponse.php"),
)
checker.check(
    "PostHelpResponse implements ShouldQueue",
    file_contains("app/Jobs/PostHelpResponse.php", "ShouldQueue"),
)
checker.check(
    "PostHelpResponse uses vunnix-server queue",
    file_contains("app/Jobs/PostHelpResponse.php", "QueueNames::SERVER"),
)

# Controller integration
checker.check(
    "WebhookController uses EventRouter",
    file_contains("app/Http/Controllers/WebhookController.php", "EventRouter"),
)

# Bot account ID config
checker.check(
    "Bot account ID in config/services.php",
    file_contains("config/services.php", "bot_account_id"),
)
checker.check(
    "Bot account ID in .env.example",
    file_contains(".env.example", "GITLAB_BOT_ACCOUNT_ID"),
)

# Tests
checker.check(
    "Event parser test exists",
    file_exists("tests/Feature/Services/EventParserTest.php"),
)
checker.check(
    "Event router test exists",
    file_exists("tests/Feature/Services/EventRouterTest.php"),
)

# ============================================================
#  T14: Deduplication + latest-wins superseding (D140)
# ============================================================
section("T14: Deduplication + Latest-Wins Superseding")

# Migration
checker.check(
    "webhook_events migration exists",
    file_exists("database/migrations/2024_01_01_000016_create_webhook_events_table.php"),
)
checker.check(
    "Migration creates webhook_events table",
    file_contains(
        "database/migrations/2024_01_01_000016_create_webhook_events_table.php",
        "webhook_events",
    ),
)
checker.check(
    "Migration includes gitlab_event_uuid column",
    file_contains(
        "database/migrations/2024_01_01_000016_create_webhook_events_table.php",
        "gitlab_event_uuid",
    ),
)
checker.check(
    "Migration adds superseded_by_id to tasks table",
    file_contains(
        "database/migrations/2024_01_01_000016_create_webhook_events_table.php",
        "superseded_by_id",
    ),
)
checker.check(
    "Migration adds unique constraint on project+UUID",
    file_contains(
        "database/migrations/2024_01_01_000016_create_webhook_events_table.php",
        "unique",
    ),
)

# WebhookEventLog model
checker.check(
    "WebhookEventLog model exists",
    file_exists("app/Models/WebhookEventLog.php"),
)
checker.check(
    "WebhookEventLog uses webhook_events table",
    file_contains("app/Models/WebhookEventLog.php", "webhook_events"),
)
checker.check(
    "WebhookEventLog has gitlab_event_uuid in fillable",
    file_contains("app/Models/WebhookEventLog.php", "gitlab_event_uuid"),
)

# EventDeduplicator service
checker.check(
    "EventDeduplicator service exists",
    file_exists("app/Services/EventDeduplicator.php"),
)
checker.check(
    "EventDeduplicator has process() method",
    file_contains("app/Services/EventDeduplicator.php", "function process("),
)
checker.check(
    "EventDeduplicator checks UUID uniqueness",
    file_contains("app/Services/EventDeduplicator.php", "isDuplicateUuid"),
)
checker.check(
    "EventDeduplicator checks commit SHA uniqueness",
    file_contains("app/Services/EventDeduplicator.php", "isDuplicateCommit"),
)
checker.check(
    "EventDeduplicator implements latest-wins superseding (D140)",
    file_contains("app/Services/EventDeduplicator.php", "supersedeActiveTasks"),
)
checker.check(
    "EventDeduplicator sets status to superseded",
    file_contains("app/Services/EventDeduplicator.php", "'superseded'"),
)

# DeduplicationResult value object
checker.check(
    "DeduplicationResult class exists",
    file_exists("app/Services/DeduplicationResult.php"),
)
checker.check(
    "DeduplicationResult has accepted() method",
    file_contains("app/Services/DeduplicationResult.php", "function accepted()"),
)

# Controller integration
checker.check(
    "WebhookController uses EventDeduplicator",
    file_contains("app/Http/Controllers/WebhookController.php", "EventDeduplicator"),
)
checker.check(
    "WebhookController reads X-Gitlab-Event-UUID header",
    file_contains("app/Http/Controllers/WebhookController.php", "X-Gitlab-Event-UUID"),
)
checker.check(
    "WebhookController returns duplicate status on rejection",
    file_contains("app/Http/Controllers/WebhookController.php", "'duplicate'"),
)

# Tests
checker.check(
    "EventDeduplicator test exists",
    file_exists("tests/Feature/Services/EventDeduplicatorTest.php"),
)
checker.check(
    "EventDeduplicator test covers UUID dedup",
    file_contains("tests/Feature/Services/EventDeduplicatorTest.php", "duplicate UUID"),
)
checker.check(
    "EventDeduplicator test covers commit SHA dedup",
    file_contains("tests/Feature/Services/EventDeduplicatorTest.php", "commit SHA"),
)
checker.check(
    "EventDeduplicator test covers D140 superseding",
    file_contains("tests/Feature/Services/EventDeduplicatorTest.php", "D140"),
)
checker.check(
    "WebhookController test covers UUID dedup integration",
    file_contains("tests/Feature/WebhookControllerTest.php", "X-Gitlab-Event-UUID"),
)

# ============================================================
#  T15: Task model + lifecycle (state machine)
# ============================================================
section("T15: Task Model + State Machine Lifecycle")

checker.check(
    "Task model exists",
    file_exists("app/Models/Task.php"),
)
checker.check(
    "Task model has transitionTo() method",
    file_contains("app/Models/Task.php", "function transitionTo("),
)
checker.check(
    "Task model has isTerminal() method",
    file_contains("app/Models/Task.php", "function isTerminal()"),
)
checker.check(
    "TaskStatus enum exists",
    file_exists("app/Enums/TaskStatus.php"),
)
checker.check(
    "TaskStatus has canTransitionTo() method",
    file_contains("app/Enums/TaskStatus.php", "function canTransitionTo("),
)
checker.check(
    "Task transition logging exists (observer or inline)",
    file_exists("app/Observers/TaskObserver.php")
    and file_contains("app/Observers/TaskObserver.php", "task_transition_logs"),
)

# ============================================================
#  T16: Task queue — Redis with priority + queue isolation
# ============================================================
section("T16: Task Queue — Redis Priority + Queue Isolation")

# ProcessTask job
checker.check(
    "ProcessTask job exists",
    file_exists("app/Jobs/ProcessTask.php"),
)
checker.check(
    "ProcessTask implements ShouldQueue",
    file_contains("app/Jobs/ProcessTask.php", "ShouldQueue"),
)
checker.check(
    "ProcessTask has resolveQueue() method",
    file_contains("app/Jobs/ProcessTask.php", "function resolveQueue("),
)
checker.check(
    "ProcessTask routes to vunnix-server for server tasks",
    file_contains("app/Jobs/ProcessTask.php", "QueueNames::SERVER"),
)
checker.check(
    "ProcessTask routes to runner queues for runner tasks",
    file_contains("app/Jobs/ProcessTask.php", "runnerQueueName"),
)

# TaskDispatchService
checker.check(
    "TaskDispatchService exists",
    file_exists("app/Services/TaskDispatchService.php"),
)
checker.check(
    "TaskDispatchService has dispatch() method",
    file_contains("app/Services/TaskDispatchService.php", "function dispatch("),
)
checker.check(
    "TaskDispatchService maps intents to TaskType",
    file_contains("app/Services/TaskDispatchService.php", "INTENT_TO_TYPE"),
)
checker.check(
    "TaskDispatchService creates Task in received status",
    file_contains("app/Services/TaskDispatchService.php", "TaskStatus::Received"),
)
checker.check(
    "TaskDispatchService transitions to queued",
    file_contains("app/Services/TaskDispatchService.php", "TaskStatus::Queued"),
)
checker.check(
    "TaskDispatchService dispatches ProcessTask",
    file_contains("app/Services/TaskDispatchService.php", "ProcessTask"),
)

# TaskType execution mode
checker.check(
    "TaskType has executionMode() method",
    file_contains("app/Enums/TaskType.php", "function executionMode()"),
)
checker.check(
    "TaskType maps PrdCreation to server mode",
    file_contains("app/Enums/TaskType.php", "'server'"),
)

# TaskPriority queue naming
checker.check(
    "TaskPriority has runnerQueueName() method",
    file_contains("app/Enums/TaskPriority.php", "function runnerQueueName()"),
)
checker.check(
    "TaskPriority generates vunnix-runner prefix",
    file_contains("app/Enums/TaskPriority.php", "vunnix-runner-"),
)

# QueueNames constants
checker.check(
    "QueueNames constants class exists",
    file_exists("app/Support/QueueNames.php"),
)
checker.check(
    "QueueNames defines SERVER constant",
    file_contains("app/Support/QueueNames.php", "SERVER"),
)
checker.check(
    "QueueNames defines RUNNER_ constants",
    file_contains("app/Support/QueueNames.php", "RUNNER_HIGH"),
)

# Tests
checker.check(
    "TaskType unit test exists",
    file_exists("tests/Unit/Enums/TaskTypeTest.php"),
)
checker.check(
    "TaskPriority unit test exists",
    file_exists("tests/Unit/Enums/TaskPriorityTest.php"),
)
checker.check(
    "ProcessTask unit test exists",
    file_exists("tests/Unit/Jobs/ProcessTaskTest.php"),
)
checker.check(
    "TaskDispatchService feature test exists",
    file_exists("tests/Feature/Services/TaskDispatchServiceTest.php"),
)

# ============================================================
#  T17: Task Dispatcher — strategy selection + execution mode
# ============================================================
section("T17: Task Dispatcher — Strategy + Execution Mode")

# TaskDispatcher service
checker.check(
    "TaskDispatcher service exists",
    file_exists("app/Services/TaskDispatcher.php"),
)
checker.check(
    "TaskDispatcher has dispatch() method",
    file_contains("app/Services/TaskDispatcher.php", "function dispatch("),
)
checker.check(
    "TaskDispatcher routes server-side tasks",
    file_contains("app/Services/TaskDispatcher.php", "dispatchServerSide"),
)
checker.check(
    "TaskDispatcher routes runner tasks",
    file_contains("app/Services/TaskDispatcher.php", "dispatchToRunner"),
)

# StrategyResolver
checker.check(
    "StrategyResolver service exists",
    file_exists("app/Services/StrategyResolver.php"),
)
checker.check(
    "StrategyResolver has resolve() method",
    file_contains("app/Services/StrategyResolver.php", "function resolve("),
)

# ReviewStrategy enum
checker.check(
    "ReviewStrategy enum exists",
    file_exists("app/Enums/ReviewStrategy.php"),
)
checker.check(
    "ReviewStrategy has skills() method",
    file_contains("app/Enums/ReviewStrategy.php", "function skills()"),
)

# ProcessTask integration
checker.check(
    "ProcessTask delegates to TaskDispatcher",
    file_contains("app/Jobs/ProcessTask.php", "TaskDispatcher"),
)

# Tests
checker.check(
    "StrategyResolver unit test exists",
    file_exists("tests/Unit/Services/StrategyResolverTest.php"),
)
checker.check(
    "TaskDispatcher feature test exists",
    file_exists("tests/Feature/Services/TaskDispatcherTest.php"),
)
checker.check(
    "ProcessTask feature test exists",
    file_exists("tests/Feature/Jobs/ProcessTaskTest.php"),
)

# ============================================================
#  T18: Pipeline trigger integration (task-scoped token D127)
# ============================================================
section("T18: Pipeline Trigger Integration (D127)")

# TaskTokenService
checker.check(
    "TaskTokenService exists",
    file_exists("app/Services/TaskTokenService.php"),
)
checker.check(
    "TaskTokenService has generate() method",
    file_contains("app/Services/TaskTokenService.php", "function generate("),
)
checker.check(
    "TaskTokenService has validate() method",
    file_contains("app/Services/TaskTokenService.php", "function validate("),
)
checker.check(
    "TaskTokenService uses HMAC-SHA256",
    file_contains("app/Services/TaskTokenService.php", "hash_hmac"),
)

# Pipeline trigger in TaskDispatcher
checker.check(
    "TaskDispatcher generates task token",
    file_contains("app/Services/TaskDispatcher.php", "taskTokenService"),
)
checker.check(
    "TaskDispatcher calls triggerPipeline",
    file_contains("app/Services/TaskDispatcher.php", "triggerPipeline"),
)
checker.check(
    "TaskDispatcher passes VUNNIX_TASK_ID variable",
    file_contains("app/Services/TaskDispatcher.php", "VUNNIX_TASK_ID"),
)
checker.check(
    "TaskDispatcher passes VUNNIX_TOKEN variable",
    file_contains("app/Services/TaskDispatcher.php", "VUNNIX_TOKEN"),
)
checker.check(
    "TaskDispatcher stores pipeline_id on task",
    file_contains("app/Services/TaskDispatcher.php", "pipeline_id"),
)

# Migration
checker.check(
    "Pipeline columns migration exists",
    file_exists("database/migrations/2024_01_01_000018_add_pipeline_columns.php"),
)

# Config
checker.check(
    "Vunnix config file exists",
    file_exists("config/vunnix.php"),
)
checker.check(
    "Config has task_budget_minutes",
    file_contains("config/vunnix.php", "task_budget_minutes"),
)
checker.check(
    "Config has api_url",
    file_contains("config/vunnix.php", "api_url"),
)

# Tests
checker.check(
    "TaskTokenService unit test exists",
    file_exists("tests/Unit/Services/TaskTokenServiceTest.php"),
)
checker.check(
    "PipelineTrigger feature test exists",
    file_exists("tests/Feature/Services/PipelineTriggerTest.php"),
)

# ============================================================
#  T19: Executor Dockerfile + entrypoint (D131)
# ============================================================
section("T19: Executor Dockerfile + Entrypoint (D131)")

# Directory structure
checker.check(
    "executor/ directory exists",
    dir_exists("executor"),
)
checker.check(
    "executor/.claude/skills/ directory exists",
    dir_exists("executor/.claude/skills"),
)
checker.check(
    "executor/scripts/ directory exists",
    dir_exists("executor/scripts"),
)
checker.check(
    "executor/mcp/ directory exists",
    dir_exists("executor/mcp"),
)

# Dockerfile
checker.check(
    "Dockerfile exists",
    file_exists("executor/Dockerfile"),
)
checker.check(
    "Dockerfile installs claude CLI",
    file_contains("executor/Dockerfile", "claude-code"),
)
checker.check(
    "Dockerfile installs Playwright",
    file_contains("executor/Dockerfile", "playwright"),
)
checker.check(
    "Dockerfile installs eslint",
    file_contains("executor/Dockerfile", "eslint"),
)
checker.check(
    "Dockerfile installs PHPStan",
    file_contains("executor/Dockerfile", "phpstan"),
)
checker.check(
    "Dockerfile installs stylelint",
    file_contains("executor/Dockerfile", "stylelint"),
)
checker.check(
    "Dockerfile sets entrypoint",
    file_contains("executor/Dockerfile", "ENTRYPOINT"),
)
checker.check(
    "Dockerfile copies .claude/ config",
    file_contains("executor/Dockerfile", "COPY .claude/"),
)

# Entrypoint
checker.check(
    "entrypoint.sh exists",
    file_exists("executor/entrypoint.sh"),
)
checker.check(
    "entrypoint.sh validates VUNNIX_TASK_ID",
    file_contains("executor/entrypoint.sh", "VUNNIX_TASK_ID"),
)
checker.check(
    "entrypoint.sh validates VUNNIX_TOKEN",
    file_contains("executor/entrypoint.sh", "VUNNIX_TOKEN"),
)
checker.check(
    "entrypoint.sh validates VUNNIX_STRATEGY",
    file_contains("executor/entrypoint.sh", "VUNNIX_STRATEGY"),
)
checker.check(
    "entrypoint.sh validates VUNNIX_SKILLS",
    file_contains("executor/entrypoint.sh", "VUNNIX_SKILLS"),
)
checker.check(
    "entrypoint.sh validates VUNNIX_API_URL",
    file_contains("executor/entrypoint.sh", "VUNNIX_API_URL"),
)
checker.check(
    "entrypoint.sh validates token expiry (D127)",
    file_contains("executor/entrypoint.sh", "scheduling_timeout"),
)
checker.check(
    "entrypoint.sh runs Claude CLI",
    file_contains("executor/entrypoint.sh", "claude"),
)
checker.check(
    "entrypoint.sh POSTs results to Vunnix API",
    file_contains("executor/entrypoint.sh", "/api/v1/tasks/"),
)
checker.check(
    "entrypoint.sh saves debug artifacts",
    file_contains("executor/entrypoint.sh", "artifact"),
)

# Playwright screenshot script (D131)
checker.check(
    "capture-screenshot.js exists",
    file_exists("executor/scripts/capture-screenshot.js"),
)
checker.check(
    "capture-screenshot.js uses Playwright chromium",
    file_contains("executor/scripts/capture-screenshot.js", "chromium"),
)
checker.check(
    "capture-screenshot.js captures PNG screenshots",
    file_contains("executor/scripts/capture-screenshot.js", "screenshot"),
)
checker.check(
    "capture-screenshot.js supports dev server startup",
    file_contains("executor/scripts/capture-screenshot.js", "start-server"),
)
checker.check(
    "capture-screenshot.js outputs JSON result",
    file_contains("executor/scripts/capture-screenshot.js", "JSON.stringify"),
)

# ============================================================
#  T20: Executor CLAUDE.md (output format, severity, safety)
# ============================================================
section("T20: Executor CLAUDE.md (Output Format, Severity, Safety)")

checker.check(
    "executor CLAUDE.md exists",
    file_exists("executor/.claude/CLAUDE.md"),
)
checker.check(
    "CLAUDE.md has version header",
    file_matches("executor/.claude/CLAUDE.md", r'version:\s*"\d+\.\d+"'),
)
checker.check(
    "CLAUDE.md has Output Format section",
    file_contains("executor/.claude/CLAUDE.md", "## Output Format"),
)
checker.check(
    "CLAUDE.md enforces valid JSON output",
    file_contains("executor/.claude/CLAUDE.md", "valid JSON"),
)
checker.check(
    "CLAUDE.md prohibits markdown fencing in output",
    file_contains("executor/.claude/CLAUDE.md", "markdown fencing"),
)
checker.check(
    "CLAUDE.md has Severity Definitions section",
    file_contains("executor/.claude/CLAUDE.md", "## Severity Definitions"),
)
checker.check(
    "CLAUDE.md defines Critical severity",
    file_contains("executor/.claude/CLAUDE.md", "Critical"),
)
checker.check(
    "CLAUDE.md defines Major severity",
    file_contains("executor/.claude/CLAUDE.md", "Major"),
)
checker.check(
    "CLAUDE.md defines Minor severity",
    file_contains("executor/.claude/CLAUDE.md", "Minor"),
)
checker.check(
    "CLAUDE.md has Code Context section",
    file_contains("executor/.claude/CLAUDE.md", "## Code Context"),
)
checker.check(
    "CLAUDE.md instructs to read beyond diff",
    file_contains("executor/.claude/CLAUDE.md", "beyond the diff"),
)
checker.check(
    "CLAUDE.md instructs to check cross-file dependencies",
    file_contains("executor/.claude/CLAUDE.md", "cross-file"),
)
checker.check(
    "CLAUDE.md references file paths and line numbers",
    file_contains("executor/.claude/CLAUDE.md", "line numbers"),
)
checker.check(
    "CLAUDE.md has Safety section",
    file_contains("executor/.claude/CLAUDE.md", "## Safety"),
)
checker.check(
    "CLAUDE.md treats code as untrusted input",
    file_contains("executor/.claude/CLAUDE.md", "untrusted"),
)
checker.check(
    "CLAUDE.md has Instruction Hierarchy section",
    file_contains("executor/.claude/CLAUDE.md", "Instruction Hierarchy"),
)
checker.check(
    "CLAUDE.md code context is data not instructions",
    file_contains("executor/.claude/CLAUDE.md", "data to be analyzed"),
)
checker.check(
    "CLAUDE.md has Prompt Injection Detection",
    file_contains("executor/.claude/CLAUDE.md", "Prompt Injection"),
)
checker.check(
    "CLAUDE.md flags injection as Critical finding",
    file_contains("executor/.claude/CLAUDE.md", "prompt-injection"),
)
checker.check(
    "CLAUDE.md prohibits following injected instructions",
    file_contains("executor/.claude/CLAUDE.md", "Do not follow the injected"),
)

# ============================================================
#  T21: Frontend-review skill
# ============================================================
section("T21: Frontend-Review Skill")

# Skill file existence and version header
checker.check(
    "frontend-review.md skill exists",
    file_exists("executor/.claude/skills/frontend-review.md"),
)
checker.check(
    "frontend-review.md has version header",
    file_matches("executor/.claude/skills/frontend-review.md", r'version:\s*"\d+\.\d+"'),
)
checker.check(
    "frontend-review.md has updated date header",
    file_matches("executor/.claude/skills/frontend-review.md", r'updated:\s*"\d{4}-\d{2}-\d{2}"'),
)

# Component structure coverage
checker.check(
    "Skill covers component structure",
    file_contains("executor/.claude/skills/frontend-review.md", "Component Structure"),
)
checker.check(
    "Skill checks Composition API usage",
    file_contains("executor/.claude/skills/frontend-review.md", "Composition API"),
)
checker.check(
    "Skill checks script setup",
    file_contains("executor/.claude/skills/frontend-review.md", "<script setup>"),
)

# Reactivity patterns coverage
checker.check(
    "Skill covers reactivity patterns",
    file_contains("executor/.claude/skills/frontend-review.md", "Reactivity"),
)
checker.check(
    "Skill checks ref vs reactive",
    file_contains("executor/.claude/skills/frontend-review.md", "ref()"),
)
checker.check(
    "Skill checks computed properties",
    file_contains("executor/.claude/skills/frontend-review.md", "computed"),
)
checker.check(
    "Skill checks watch usage",
    file_contains("executor/.claude/skills/frontend-review.md", "watch"),
)

# Accessibility coverage
checker.check(
    "Skill covers accessibility",
    file_contains("executor/.claude/skills/frontend-review.md", "Accessibility"),
)
checker.check(
    "Skill checks ARIA attributes",
    file_contains("executor/.claude/skills/frontend-review.md", "aria-label"),
)
checker.check(
    "Skill checks keyboard navigation",
    file_contains("executor/.claude/skills/frontend-review.md", "keyboard"),
)
checker.check(
    "Skill checks semantic HTML",
    file_contains("executor/.claude/skills/frontend-review.md", "Semantic HTML"),
)

# CSS specificity coverage
checker.check(
    "Skill covers CSS specificity",
    file_contains("executor/.claude/skills/frontend-review.md", "Specificity"),
)
checker.check(
    "Skill checks scoped styles",
    file_contains("executor/.claude/skills/frontend-review.md", "scoped"),
)
checker.check(
    "Skill flags !important usage",
    file_contains("executor/.claude/skills/frontend-review.md", "!important"),
)

# i18n coverage
checker.check(
    "Skill covers internationalization",
    file_contains("executor/.claude/skills/frontend-review.md", "i18n"),
)
checker.check(
    "Skill checks hardcoded strings",
    file_contains("executor/.claude/skills/frontend-review.md", "Hardcoded"),
)

# Design tokens coverage
checker.check(
    "Skill references design tokens",
    file_contains("executor/.claude/skills/frontend-review.md", "design tokens"),
)

# ESLint severity classification
checker.check(
    "Skill integrates eslint findings",
    file_contains("executor/.claude/skills/frontend-review.md", "eslint"),
)
checker.check(
    "Skill classifies eslint findings by severity",
    file_contains("executor/.claude/skills/frontend-review.md", "vue3-essential"),
)
checker.check(
    "Skill maps eslint errors to Major severity",
    file_contains("executor/.claude/skills/frontend-review.md", "Major"),
)

# Stylelint integration
checker.check(
    "Skill integrates stylelint findings",
    file_contains("executor/.claude/skills/frontend-review.md", "stylelint"),
)

# Large diff handling
checker.check(
    "Skill handles large diffs",
    file_contains("executor/.claude/skills/frontend-review.md", "Large Diff"),
)
checker.check(
    "Skill summarizes patterns across similar changes",
    file_contains("executor/.claude/skills/frontend-review.md", "Summarize patterns"),
)

# Output schema reference
checker.check(
    "Skill references code review JSON schema output",
    file_contains("executor/.claude/skills/frontend-review.md", "code review schema"),
)
checker.check(
    "Skill specifies commit_status logic",
    file_contains("executor/.claude/skills/frontend-review.md", "commit_status"),
)
checker.check(
    "Skill specifies label output",
    file_contains("executor/.claude/skills/frontend-review.md", "ai::reviewed"),
)

# ============================================================
#  Runtime checks
# ============================================================
section("Runtime: Laravel Tests")

success, stdout, stderr = run_command("php artisan test 2>&1")
if success:
    checker.check(
        "php artisan test passes",
        True,
        stdout.split("\n")[-1] if stdout else "",
    )
else:
    if "not found" in stderr or "No such file" in stderr:
        checker.check(
            "php artisan test passes",
            False,
            "artisan not available (Laravel not yet scaffolded?)",
        )
    else:
        last_line = (
            stdout.split("\n")[-1]
            if stdout
            else stderr.split("\n")[-1]
            if stderr
            else "unknown"
        )
        checker.check("php artisan test passes", False, last_line)

# ============================================================
#  Summary
# ============================================================
checker.summary()
