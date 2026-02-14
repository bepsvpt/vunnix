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
#  T22: Backend-review skill
# ============================================================
section("T22: Backend-Review Skill")

# Skill file existence and version header
checker.check(
    "backend-review.md skill exists",
    file_exists("executor/.claude/skills/backend-review.md"),
)
checker.check(
    "backend-review.md has version header",
    file_matches("executor/.claude/skills/backend-review.md", r'version:\s*"\d+\.\d+"'),
)
checker.check(
    "backend-review.md has updated date header",
    file_matches("executor/.claude/skills/backend-review.md", r'updated:\s*"\d{4}-\d{2}-\d{2}"'),
)

# SQL injection & query safety coverage
checker.check(
    "Skill covers SQL injection",
    file_contains("executor/.claude/skills/backend-review.md", "SQL Injection"),
)
checker.check(
    "Skill checks DB::raw() usage",
    file_contains("executor/.claude/skills/backend-review.md", "DB::raw()"),
)
checker.check(
    "Skill checks parameter binding",
    file_contains("executor/.claude/skills/backend-review.md", "parameter binding"),
)
checker.check(
    "Skill checks mass assignment",
    file_contains("executor/.claude/skills/backend-review.md", "Mass Assignment"),
)

# N+1 queries & performance coverage
checker.check(
    "Skill covers N+1 queries",
    file_contains("executor/.claude/skills/backend-review.md", "N+1"),
)
checker.check(
    "Skill checks eager loading",
    file_contains("executor/.claude/skills/backend-review.md", "Eager Loading"),
)
checker.check(
    "Skill checks pagination convention",
    file_contains("executor/.claude/skills/backend-review.md", "cursorPaginate"),
)
checker.check(
    "Skill checks transaction usage",
    file_contains("executor/.claude/skills/backend-review.md", "DB::transaction()"),
)

# Validation & input handling coverage
checker.check(
    "Skill covers validation",
    file_contains("executor/.claude/skills/backend-review.md", "Validation"),
)
checker.check(
    "Skill checks FormRequest usage",
    file_contains("executor/.claude/skills/backend-review.md", "FormRequest"),
)
checker.check(
    "Skill checks authorization in FormRequest",
    file_contains("executor/.claude/skills/backend-review.md", "authorize()"),
)

# Error handling coverage
checker.check(
    "Skill covers error handling",
    file_contains("executor/.claude/skills/backend-review.md", "Error Handling"),
)
checker.check(
    "Skill checks exception types",
    file_contains("executor/.claude/skills/backend-review.md", "Exception"),
)
checker.check(
    "Skill checks null safety",
    file_contains("executor/.claude/skills/backend-review.md", "Null Safety"),
)

# Laravel conventions coverage
checker.check(
    "Skill covers Laravel conventions",
    file_contains("executor/.claude/skills/backend-review.md", "Laravel Conventions"),
)
checker.check(
    "Skill checks Eloquent API Resources",
    file_contains("executor/.claude/skills/backend-review.md", "Resource"),
)
checker.check(
    "Skill checks Policies and Gates",
    file_contains("executor/.claude/skills/backend-review.md", "Policy"),
)
checker.check(
    "Skill checks service layer pattern",
    file_contains("executor/.claude/skills/backend-review.md", "Service"),
)
checker.check(
    "Skill checks route model binding",
    file_contains("executor/.claude/skills/backend-review.md", "route model binding"),
)
checker.check(
    "Skill flags env() outside config files",
    file_contains("executor/.claude/skills/backend-review.md", "env()"),
)

# Migrations & schema coverage
checker.check(
    "Skill covers migrations",
    file_contains("executor/.claude/skills/backend-review.md", "Migration"),
)
checker.check(
    "Skill checks PostgreSQL-specific guards",
    file_contains("executor/.claude/skills/backend-review.md", "getDriverName"),
)
checker.check(
    "Skill checks foreign key constraints",
    file_contains("executor/.claude/skills/backend-review.md", "foreign key"),
)

# Authentication & authorization coverage
checker.check(
    "Skill covers authentication",
    file_contains("executor/.claude/skills/backend-review.md", "Authentication"),
)
checker.check(
    "Skill checks secret exposure",
    file_contains("executor/.claude/skills/backend-review.md", "Secret Exposure"),
)

# PHPStan integration
checker.check(
    "Skill integrates PHPStan findings",
    file_contains("executor/.claude/skills/backend-review.md", "PHPStan"),
)
checker.check(
    "Skill classifies PHPStan findings by severity",
    file_contains("executor/.claude/skills/backend-review.md", "Level 5"),
)
checker.check(
    "Skill maps PHPStan errors to Major severity",
    file_contains("executor/.claude/skills/backend-review.md", "Major"),
)

# Large diff handling
checker.check(
    "Skill handles large diffs",
    file_contains("executor/.claude/skills/backend-review.md", "Large Diff"),
)
checker.check(
    "Skill summarizes patterns across similar changes",
    file_contains("executor/.claude/skills/backend-review.md", "Summarize patterns"),
)

# Output schema reference
checker.check(
    "Skill references code review JSON schema output",
    file_contains("executor/.claude/skills/backend-review.md", "code review schema"),
)
checker.check(
    "Skill specifies commit_status logic",
    file_contains("executor/.claude/skills/backend-review.md", "commit_status"),
)
checker.check(
    "Skill specifies label output",
    file_contains("executor/.claude/skills/backend-review.md", "ai::reviewed"),
)

#  T23: Mixed-review skill

section("T23: Mixed-Review Skill")

# Skill file existence and version header
checker.check(
    "mixed-review.md skill exists",
    file_exists("executor/.claude/skills/mixed-review.md"),
)
checker.check(
    "Skill has version header",
    file_matches("executor/.claude/skills/mixed-review.md", r'version:\s*"\d+\.\d+"'),
)
checker.check(
    "Skill has updated date header",
    file_matches("executor/.claude/skills/mixed-review.md", r'updated:\s*"\d{4}-\d{2}-\d{2}"'),
)

# Frontend review references
checker.check(
    "Skill references frontend-review checklist",
    file_contains("executor/.claude/skills/mixed-review.md", "frontend-review"),
)
checker.check(
    "Skill covers Component Structure",
    file_contains("executor/.claude/skills/mixed-review.md", "Component Structure"),
)
checker.check(
    "Skill covers Composition API",
    file_contains("executor/.claude/skills/mixed-review.md", "Composition API"),
)
checker.check(
    "Skill covers Reactivity Patterns",
    file_contains("executor/.claude/skills/mixed-review.md", "Reactivity"),
)
checker.check(
    "Skill covers Accessibility",
    file_contains("executor/.claude/skills/mixed-review.md", "Accessibility"),
)
checker.check(
    "Skill covers CSS Specificity",
    file_contains("executor/.claude/skills/mixed-review.md", "Specificity"),
)
checker.check(
    "Skill covers i18n",
    file_contains("executor/.claude/skills/mixed-review.md", "i18n"),
)

# Backend review references
checker.check(
    "Skill references backend-review checklist",
    file_contains("executor/.claude/skills/mixed-review.md", "backend-review"),
)
checker.check(
    "Skill covers SQL Injection",
    file_contains("executor/.claude/skills/mixed-review.md", "SQL Injection"),
)
checker.check(
    "Skill covers N+1 Queries",
    file_contains("executor/.claude/skills/mixed-review.md", "N+1"),
)
checker.check(
    "Skill covers FormRequest",
    file_contains("executor/.claude/skills/mixed-review.md", "FormRequest"),
)
checker.check(
    "Skill covers Laravel Conventions",
    file_contains("executor/.claude/skills/mixed-review.md", "Laravel Conventions"),
)
checker.check(
    "Skill covers Migrations",
    file_contains("executor/.claude/skills/mixed-review.md", "Migration"),
)
checker.check(
    "Skill covers Authentication",
    file_contains("executor/.claude/skills/mixed-review.md", "Authentication"),
)

# API Contract Consistency (unique to mixed-review)
checker.check(
    "Skill has API Contract Consistency section",
    file_contains("executor/.claude/skills/mixed-review.md", "API Contract Consistency"),
)
checker.check(
    "Skill checks route matching",
    file_contains("executor/.claude/skills/mixed-review.md", "Route Matching"),
)
checker.check(
    "Skill checks HTTP method matching",
    file_contains("executor/.claude/skills/mixed-review.md", "HTTP method"),
)
checker.check(
    "Skill checks request payload consistency",
    file_contains("executor/.claude/skills/mixed-review.md", "Request Payload Consistency"),
)
checker.check(
    "Skill checks response shape consistency",
    file_contains("executor/.claude/skills/mixed-review.md", "Response Shape Consistency"),
)
checker.check(
    "Skill checks pagination format alignment",
    file_contains("executor/.claude/skills/mixed-review.md", "cursorPaginate"),
)
checker.check(
    "Skill checks auth/middleware alignment",
    file_contains("executor/.claude/skills/mixed-review.md", "Middleware Alignment"),
)
checker.check(
    "Skill uses api-contract category for cross-domain findings",
    file_contains("executor/.claude/skills/mixed-review.md", "api-contract"),
)

# Tool integration
checker.check(
    "Skill integrates eslint findings",
    file_contains("executor/.claude/skills/mixed-review.md", "eslint"),
)
checker.check(
    "Skill integrates stylelint findings",
    file_contains("executor/.claude/skills/mixed-review.md", "stylelint"),
)
checker.check(
    "Skill integrates PHPStan findings",
    file_contains("executor/.claude/skills/mixed-review.md", "PHPStan"),
)

# Large diff handling
checker.check(
    "Skill handles large diffs",
    file_contains("executor/.claude/skills/mixed-review.md", "Large Diff"),
)
checker.check(
    "Skill summarizes patterns across similar changes",
    file_contains("executor/.claude/skills/mixed-review.md", "Summarize patterns"),
)

# Output section
checker.check(
    "Skill references code review JSON schema output",
    file_contains("executor/.claude/skills/mixed-review.md", "code review schema"),
)
checker.check(
    "Skill specifies commit_status logic",
    file_contains("executor/.claude/skills/mixed-review.md", "commit_status"),
)
checker.check(
    "Skill specifies label output",
    file_contains("executor/.claude/skills/mixed-review.md", "ai::reviewed"),
)

# ============================================================
#  T24: Security-audit skill
# ============================================================
section("T24: Security-Audit Skill")

# File existence and metadata
checker.check(
    "security-audit.md skill exists",
    file_exists("executor/.claude/skills/security-audit.md"),
)
checker.check(
    "Skill has version header",
    file_contains("executor/.claude/skills/security-audit.md", 'version: "1.0"'),
)
checker.check(
    "Skill has updated date header",
    file_contains("executor/.claude/skills/security-audit.md", "updated:"),
)

# Severity floor — the defining characteristic of this skill
checker.check(
    "Skill enforces Major minimum severity for all security findings",
    file_contains("executor/.claude/skills/security-audit.md", "Major minimum"),
)
checker.check(
    "Skill prohibits Minor severity for security findings",
    file_contains(
        "executor/.claude/skills/security-audit.md",
        "Do not classify any security finding",
    ),
)
checker.check(
    "Skill has Severity Floor Enforcement section",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Severity Floor Enforcement"
    ),
)

# OWASP Top 10 coverage
checker.check(
    "Skill covers Injection (OWASP A03)",
    file_contains("executor/.claude/skills/security-audit.md", "Injection"),
)
checker.check(
    "Skill checks SQL injection",
    file_contains("executor/.claude/skills/security-audit.md", "SQL Injection"),
)
checker.check(
    "Skill checks command injection",
    file_contains("executor/.claude/skills/security-audit.md", "Command Injection"),
)
checker.check(
    "Skill covers Broken Authentication (OWASP A07)",
    file_contains("executor/.claude/skills/security-audit.md", "Broken Authentication"),
)
checker.check(
    "Skill checks session management",
    file_contains("executor/.claude/skills/security-audit.md", "Session Management"),
)
checker.check(
    "Skill checks brute force protection",
    file_contains("executor/.claude/skills/security-audit.md", "Brute Force"),
)
checker.check(
    "Skill covers Broken Access Control (OWASP A01)",
    file_contains("executor/.claude/skills/security-audit.md", "Broken Access Control"),
)
checker.check(
    "Skill checks IDOR vulnerabilities",
    file_contains(
        "executor/.claude/skills/security-audit.md",
        "Insecure Direct Object References",
    ),
)
checker.check(
    "Skill checks privilege escalation",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Privilege Escalation"
    ),
)
checker.check(
    "Skill covers Sensitive Data Exposure (OWASP A02)",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Sensitive Data Exposure"
    ),
)
checker.check(
    "Skill checks secrets in source code",
    file_contains("executor/.claude/skills/security-audit.md", "Secrets in Source Code"),
)
checker.check(
    "Skill checks secrets in logs",
    file_contains("executor/.claude/skills/security-audit.md", "Secrets in Logs"),
)
checker.check(
    "Skill covers Security Misconfiguration (OWASP A05)",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Security Misconfiguration"
    ),
)
checker.check(
    "Skill checks CORS policy",
    file_contains("executor/.claude/skills/security-audit.md", "CORS"),
)
checker.check(
    "Skill checks CSRF protection",
    file_contains("executor/.claude/skills/security-audit.md", "CSRF"),
)
checker.check(
    "Skill covers XSS (OWASP A03)",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Cross-Site Scripting"
    ),
)
checker.check(
    "Skill checks Vue XSS via v-html",
    file_contains("executor/.claude/skills/security-audit.md", "v-html"),
)
checker.check(
    "Skill checks DOM XSS",
    file_contains("executor/.claude/skills/security-audit.md", "DOM XSS"),
)
checker.check(
    "Skill covers Insecure Deserialization (OWASP A08)",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Insecure Deserialization"
    ),
)
checker.check(
    "Skill checks PHP unserialize()",
    file_contains("executor/.claude/skills/security-audit.md", "unserialize()"),
)
checker.check(
    "Skill covers Known Vulnerabilities (OWASP A06)",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Known Vulnerabilities"
    ),
)
checker.check(
    "Skill checks dependency CVEs",
    file_contains("executor/.claude/skills/security-audit.md", "CVE"),
)
checker.check(
    "Skill covers Insufficient Logging (OWASP A09)",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Insufficient Logging"
    ),
)
checker.check(
    "Skill checks security event logging",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Security Event Logging"
    ),
)
checker.check(
    "Skill covers SSRF (OWASP A10)",
    file_contains(
        "executor/.claude/skills/security-audit.md",
        "Server-Side Request Forgery",
    ),
)
checker.check(
    "Skill checks internal network address access",
    file_contains("executor/.claude/skills/security-audit.md", "169.254.169.254"),
)

# Auth/authz bypasses (spec requirement)
checker.check(
    "Skill checks authorization bypass patterns",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Authorization Checks"
    ),
)
checker.check(
    "Skill checks horizontal and vertical escalation",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Horizontal Privilege Escalation"
    ),
)

# Input validation (spec requirement)
checker.check(
    "Skill covers Mass Assignment & Data Tampering",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Mass Assignment"
    ),
)
checker.check(
    "Skill checks model guarding ($fillable vs $guarded)",
    file_contains("executor/.claude/skills/security-audit.md", "$fillable"),
)

# Secret exposure (spec requirement)
checker.check(
    "Skill checks hardcoded credentials",
    file_contains("executor/.claude/skills/security-audit.md", "hardcoded"),
)
checker.check(
    "Skill checks API response data exposure",
    file_contains("executor/.claude/skills/security-audit.md", "API Response Exposure"),
)

# Dependency vulnerabilities (spec requirement)
checker.check(
    "Skill checks abandoned packages",
    file_contains("executor/.claude/skills/security-audit.md", "Abandoned Packages"),
)

# Cryptographic failures
checker.check(
    "Skill covers Cryptographic Failures",
    file_contains("executor/.claude/skills/security-audit.md", "Cryptographic Failures"),
)
checker.check(
    "Skill checks weak algorithms",
    file_contains("executor/.claude/skills/security-audit.md", "Weak Algorithms"),
)
checker.check(
    "Skill checks predictable randomness",
    file_contains(
        "executor/.claude/skills/security-audit.md", "Predictable Randomness"
    ),
)

# Tool integration
checker.check(
    "Skill integrates PHPStan findings",
    file_contains("executor/.claude/skills/security-audit.md", "PHPStan"),
)
checker.check(
    "Skill integrates eslint security rules",
    file_contains("executor/.claude/skills/security-audit.md", "eslint"),
)
checker.check(
    "Skill integrates dependency audit tools",
    file_contains("executor/.claude/skills/security-audit.md", "composer audit"),
)

# Large diff handling
checker.check(
    "Skill handles large diffs with depth-over-breadth strategy",
    file_contains("executor/.claude/skills/security-audit.md", "Depth over breadth"),
)
checker.check(
    "Skill traces data flows end-to-end",
    file_contains("executor/.claude/skills/security-audit.md", "Trace data flows"),
)

# Output section
checker.check(
    "Skill uses security category for findings",
    file_contains("executor/.claude/skills/security-audit.md", '"security"'),
)
checker.check(
    "Skill includes ai::security-audit label",
    file_contains("executor/.claude/skills/security-audit.md", "ai::security-audit"),
)
checker.check(
    "Skill references code review JSON schema output",
    file_contains("executor/.claude/skills/security-audit.md", "code review schema"),
)
checker.check(
    "Skill specifies commit_status logic",
    file_contains("executor/.claude/skills/security-audit.md", "commit_status"),
)
checker.check(
    "Skill specifies risk_level mapping",
    file_contains("executor/.claude/skills/security-audit.md", "risk_level"),
)

# ============================================================
#  T25: UI-adjustment skill (D131)
# ============================================================
section("T25: UI-Adjustment Skill")

# File existence and metadata
checker.check(
    "ui-adjustment.md skill exists",
    file_exists("executor/.claude/skills/ui-adjustment.md"),
)
checker.check(
    "Skill has version header",
    file_contains("executor/.claude/skills/ui-adjustment.md", 'version: "1.0"'),
)
checker.check(
    "Skill has updated date header",
    file_contains("executor/.claude/skills/ui-adjustment.md", "updated:"),
)

# Core requirements from spec: targeted visual changes
checker.check(
    "Skill describes targeted visual changes",
    file_contains("executor/.claude/skills/ui-adjustment.md", "targeted"),
)
checker.check(
    "Skill instructs to minimize scope",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Minimize scope"),
)
checker.check(
    "Skill instructs to change only what is needed",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md", "change only what"
    ),
)

# Preserve existing styles
checker.check(
    "Skill instructs to preserve existing styles",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md", "Preserve existing styles"
    ),
)
checker.check(
    "Skill checks scoped styles",
    file_contains("executor/.claude/skills/ui-adjustment.md", "scoped"),
)
checker.check(
    "Skill references design tokens",
    file_contains("executor/.claude/skills/ui-adjustment.md", "design tokens"),
)

# Responsive breakpoints
checker.check(
    "Skill covers responsive breakpoints",
    file_contains("executor/.claude/skills/ui-adjustment.md", "breakpoint"),
)
checker.check(
    "Skill checks desktop viewport",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Desktop"),
)
checker.check(
    "Skill checks tablet viewport",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Tablet"),
)
checker.check(
    "Skill checks mobile viewport",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Mobile"),
)

# Screenshot capture (D131 — key differentiator)
checker.check(
    "Skill has screenshot capture section",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Capture Screenshots"),
)
checker.check(
    "Skill references capture-screenshot.js",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md", "capture-screenshot.js"
    ),
)
checker.check(
    "Skill documents --start-server option",
    file_contains("executor/.claude/skills/ui-adjustment.md", "--start-server"),
)
checker.check(
    "Skill documents --full-page option",
    file_contains("executor/.claude/skills/ui-adjustment.md", "--full-page"),
)
checker.check(
    "Skill documents --width option for mobile screenshots",
    file_contains("executor/.claude/skills/ui-adjustment.md", "--width"),
)
checker.check(
    "Skill includes example capture command",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md",
        "/executor/scripts/capture-screenshot.js",
    ),
)
checker.check(
    "Skill documents base64 encoding for screenshot output",
    file_contains("executor/.claude/skills/ui-adjustment.md", "base64"),
)

# Graceful fallback (D131)
checker.check(
    "Skill has graceful fallback when screenshot fails",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Graceful fallback"),
)
checker.check(
    "Skill sets screenshot to null on failure",
    file_contains("executor/.claude/skills/ui-adjustment.md", "screenshot: null"),
)
checker.check(
    "Skill does not fail task when screenshot fails",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md", "do not fail the entire task"
    ),
)

# What NOT to do — scope guard
checker.check(
    "Skill has scope restrictions (what not to do)",
    file_contains("executor/.claude/skills/ui-adjustment.md", "Do not refactor"),
)
checker.check(
    "Skill prohibits adding features",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md", "Do not add features"
    ),
)
checker.check(
    "Skill prohibits changing behavior",
    file_contains(
        "executor/.claude/skills/ui-adjustment.md", "Do not change behavior"
    ),
)

# Output schema — feature dev / UI adjustment format (not code review)
checker.check(
    "Skill references feature dev output schema (branch field)",
    file_contains("executor/.claude/skills/ui-adjustment.md", '"branch"'),
)
checker.check(
    "Skill references mr_title field",
    file_contains("executor/.claude/skills/ui-adjustment.md", "mr_title"),
)
checker.check(
    "Skill references mr_description field",
    file_contains("executor/.claude/skills/ui-adjustment.md", "mr_description"),
)
checker.check(
    "Skill references files_changed field",
    file_contains("executor/.claude/skills/ui-adjustment.md", "files_changed"),
)
checker.check(
    "Skill references screenshot field in output",
    file_contains("executor/.claude/skills/ui-adjustment.md", '"screenshot"'),
)
checker.check(
    "Skill references screenshot_mobile field",
    file_contains("executor/.claude/skills/ui-adjustment.md", "screenshot_mobile"),
)

# ============================================================
#  T26: Issue-discussion skill
# ============================================================
section("T26: Issue-Discussion Skill")

# File existence and metadata
checker.check(
    "issue-discussion.md skill exists",
    file_exists("executor/.claude/skills/issue-discussion.md"),
)
checker.check(
    "Skill has version header",
    file_contains("executor/.claude/skills/issue-discussion.md", 'version: "1.0"'),
)
checker.check(
    "Skill has updated date header",
    file_contains("executor/.claude/skills/issue-discussion.md", "updated:"),
)

# Core requirement: answer question in Issue + codebase context
checker.check(
    "Skill describes responding to @ai mention on Issue",
    file_contains("executor/.claude/skills/issue-discussion.md", "@ai"),
)
checker.check(
    "Skill instructs to read Issue description and thread",
    file_contains("executor/.claude/skills/issue-discussion.md", "Issue description"),
)
checker.check(
    "Skill instructs to explore the codebase",
    file_contains("executor/.claude/skills/issue-discussion.md", "Explore the Codebase"),
)
checker.check(
    "Skill instructs to read actual source files",
    file_contains(
        "executor/.claude/skills/issue-discussion.md", "read the actual source files"
    ),
)

# Core requirement: reference relevant code
checker.check(
    "Skill requires specific file and line references",
    file_contains("executor/.claude/skills/issue-discussion.md", "files and line numbers"),
)
checker.check(
    "Skill requires code references for factual claims",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "must include a file reference",
    ),
)

# Core requirement: concise and actionable
checker.check(
    "Skill instructs to be concise",
    file_contains("executor/.claude/skills/issue-discussion.md", "Be Concise"),
)
checker.check(
    "Skill instructs to lead with the direct answer",
    file_contains(
        "executor/.claude/skills/issue-discussion.md", "Lead with the direct answer"
    ),
)
checker.check(
    "Skill instructs to be actionable",
    file_contains("executor/.claude/skills/issue-discussion.md", "Be Actionable"),
)
checker.check(
    "Skill instructs to suggest specific changes when relevant",
    file_contains(
        "executor/.claude/skills/issue-discussion.md", "suggest the specific change"
    ),
)

# Honesty and accuracy
checker.check(
    "Skill instructs to be honest about limitations",
    file_contains("executor/.claude/skills/issue-discussion.md", "Be Honest"),
)
checker.check(
    "Skill prohibits fabricating code references",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "Don't fabricate code references",
    ),
)

# Scope restrictions (read-only task)
checker.check(
    "Skill prohibits modifying files",
    file_contains(
        "executor/.claude/skills/issue-discussion.md", "Do not modify any files"
    ),
)
checker.check(
    "Skill is a read-only analysis task",
    file_contains("executor/.claude/skills/issue-discussion.md", "read-only"),
)
checker.check(
    "Skill prohibits creating branches or commits",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "Do not create branches or commits",
    ),
)
checker.check(
    "Skill prohibits executing code",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "Do not execute code",
    ),
)

# Edge case handling
checker.check(
    "Skill handles vague questions",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "Question is too vague",
    ),
)
checker.check(
    "Skill handles questions about external systems",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "not in the codebase",
    ),
)
checker.check(
    "Skill handles multiple questions in one comment",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "Multiple questions",
    ),
)

# Task parameters from §14.5
checker.check(
    "Skill references Issue IID parameter",
    file_contains("executor/.claude/skills/issue-discussion.md", "Issue IID"),
)
checker.check(
    "Skill references triggering comment ID parameter",
    file_contains(
        "executor/.claude/skills/issue-discussion.md", "Triggering comment ID"
    ),
)

# Output schema — issue discussion format (not code review)
checker.check(
    "Skill defines JSON output with response field",
    file_contains("executor/.claude/skills/issue-discussion.md", '"response"'),
)
checker.check(
    "Skill defines references array in output",
    file_contains("executor/.claude/skills/issue-discussion.md", '"references"'),
)
checker.check(
    "Skill defines confidence field in output",
    file_contains("executor/.claude/skills/issue-discussion.md", '"confidence"'),
)
checker.check(
    "Skill specifies confidence levels (high/medium/low)",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "high | medium | low",
    ),
)
checker.check(
    "Skill produces only JSON output (no fencing)",
    file_contains(
        "executor/.claude/skills/issue-discussion.md",
        "No markdown fencing",
    ),
)

# ============================================================
#  T27: Feature-dev skill
# ============================================================
section("T27: Feature-Dev Skill")

# File existence and metadata
checker.check(
    "feature-dev.md skill exists",
    file_exists("executor/.claude/skills/feature-dev.md"),
)
checker.check(
    "Skill has version header",
    file_contains("executor/.claude/skills/feature-dev.md", 'version: "1.0"'),
)
checker.check(
    "Skill has updated date header",
    file_contains("executor/.claude/skills/feature-dev.md", "updated:"),
)

# Core requirement: implement feature per task parameters
checker.check(
    "Skill describes implementing a feature",
    file_contains("executor/.claude/skills/feature-dev.md", "implementing a feature"),
)
checker.check(
    "Skill references Issue IID parameter",
    file_contains("executor/.claude/skills/feature-dev.md", "Issue IID"),
)
checker.check(
    "Skill references branch prefix parameter",
    file_contains("executor/.claude/skills/feature-dev.md", "Branch prefix"),
)
checker.check(
    "Skill references target branch parameter",
    file_contains("executor/.claude/skills/feature-dev.md", "Target branch"),
)

# Core requirement: follow project conventions (CLAUDE.md)
checker.check(
    "Skill instructs to read project CLAUDE.md",
    file_contains("executor/.claude/skills/feature-dev.md", "CLAUDE.md"),
)
checker.check(
    "Skill instructs to follow project conventions",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Follow Project Conventions"
    ),
)
checker.check(
    "Skill instructs to read existing files before creating new ones",
    file_contains("executor/.claude/skills/feature-dev.md", "Read before writing"),
)
checker.check(
    "Skill instructs to match naming conventions",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Match naming conventions"
    ),
)
checker.check(
    "Skill instructs to follow directory structure",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Follow directory structure"
    ),
)
checker.check(
    "Skill instructs to use existing abstractions",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Use existing abstractions"
    ),
)

# Core requirement: create clean code
checker.check(
    "Skill has clean code principles",
    file_contains("executor/.claude/skills/feature-dev.md", "Write Clean Code"),
)
checker.check(
    "Skill instructs single responsibility",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Single responsibility"
    ),
)
checker.check(
    "Skill instructs clear naming",
    file_contains("executor/.claude/skills/feature-dev.md", "Clear naming"),
)
checker.check(
    "Skill instructs appropriate error handling",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Handle errors appropriately"
    ),
)
checker.check(
    "Skill prohibits dead code",
    file_contains("executor/.claude/skills/feature-dev.md", "No dead code"),
)

# Core requirement: write tests if project has test suite
checker.check(
    "Skill has test writing section",
    file_contains("executor/.claude/skills/feature-dev.md", "Write Tests"),
)
checker.check(
    "Skill detects test framework",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Detect the Test Framework"
    ),
)
checker.check(
    "Skill checks for PHP test frameworks",
    file_contains("executor/.claude/skills/feature-dev.md", "phpunit.xml"),
)
checker.check(
    "Skill checks for Pest test framework",
    file_contains("executor/.claude/skills/feature-dev.md", "Pest"),
)
checker.check(
    "Skill checks for JavaScript test frameworks",
    file_contains("executor/.claude/skills/feature-dev.md", "vitest"),
)
checker.check(
    "Skill instructs to follow existing test patterns",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Follow existing test patterns",
    ),
)
checker.check(
    "Skill instructs to cover main success path",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Cover the main success path"
    ),
)
checker.check(
    "Skill instructs to cover error paths",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Cover key error paths"
    ),
)
checker.check(
    "Skill instructs to mock external services",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Mock external services"
    ),
)
checker.check(
    "Skill handles projects with no test suite",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "No Test Suite Exists"
    ),
)

# Scope discipline
checker.check(
    "Skill has scope discipline section",
    file_contains("executor/.claude/skills/feature-dev.md", "Scope Discipline"),
)
checker.check(
    "Skill instructs to implement only what was requested",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Implement what was requested",
    ),
)
checker.check(
    "Skill prohibits refactoring existing code",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Do not refactor existing code"
    ),
)
checker.check(
    "Skill prohibits modifying unrelated files",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Do not modify unrelated files"
    ),
)

# Verification step
checker.check(
    "Skill has verify step before finalizing",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Verify Your Work"
    ),
)
checker.check(
    "Skill instructs to run project linter",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Run the project's linter"
    ),
)
checker.check(
    "Skill instructs to run test suite",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Run the test suite"
    ),
)

# Branch creation
checker.check(
    "Skill instructs to create feature branch",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Create the Feature Branch"
    ),
)
checker.check(
    "Skill shows git checkout command",
    file_contains("executor/.claude/skills/feature-dev.md", "git checkout -b"),
)

# Commit instructions
checker.check(
    "Skill instructs to commit changes",
    file_contains(
        "executor/.claude/skills/feature-dev.md", "Commit Your Changes"
    ),
)
checker.check(
    "Skill shows git commit command",
    file_contains("executor/.claude/skills/feature-dev.md", "git commit"),
)

# Edge cases
checker.check(
    "Skill handles insufficient Issue detail",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Issue has insufficient detail",
    ),
)
checker.check(
    "Skill handles feature conflicts with existing code",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Feature conflicts with existing code",
    ),
)
checker.check(
    "Skill handles database changes",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Feature requires database changes",
    ),
)
checker.check(
    "Skill handles multi-language features",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Feature spans multiple languages",
    ),
)

# What NOT to do — scope guard
checker.check(
    "Skill prohibits creating documentation files",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Do not create documentation files",
    ),
)
checker.check(
    "Skill prohibits modifying CI/CD configuration",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "Do not modify CI/CD configuration",
    ),
)

# Output schema — feature development format
checker.check(
    "Skill defines JSON output with branch field",
    file_contains("executor/.claude/skills/feature-dev.md", '"branch"'),
)
checker.check(
    "Skill defines mr_title field",
    file_contains("executor/.claude/skills/feature-dev.md", "mr_title"),
)
checker.check(
    "Skill defines mr_description field",
    file_contains("executor/.claude/skills/feature-dev.md", "mr_description"),
)
checker.check(
    "Skill defines files_changed field",
    file_contains("executor/.claude/skills/feature-dev.md", "files_changed"),
)
checker.check(
    "Skill defines tests_added field",
    file_contains("executor/.claude/skills/feature-dev.md", "tests_added"),
)
checker.check(
    "Skill defines notes field",
    file_contains("executor/.claude/skills/feature-dev.md", '"notes"'),
)
checker.check(
    "Skill produces only JSON output (no fencing)",
    file_contains(
        "executor/.claude/skills/feature-dev.md",
        "No markdown fencing",
    ),
)

# ============================================================
#  T29: Runner result API endpoint
# ============================================================
section("T29: Runner Result API Endpoint")

# API route file
checker.check(
    "API routes file exists",
    file_exists("routes/api.php"),
)
checker.check(
    "API routes define tasks result endpoint",
    file_contains("routes/api.php", "tasks/{task}/result"),
)
checker.check(
    "API routes use v1 prefix",
    file_contains("routes/api.php", "v1"),
)
checker.check(
    "API routes use task.token middleware",
    file_contains("routes/api.php", "task.token"),
)

# Route registration in bootstrap/app.php
checker.check(
    "API routes registered in bootstrap/app.php",
    file_contains("bootstrap/app.php", "api:"),
)
checker.check(
    "task.token middleware alias registered",
    file_contains("bootstrap/app.php", "task.token"),
)
checker.check(
    "AuthenticateTaskToken middleware referenced",
    file_contains("bootstrap/app.php", "AuthenticateTaskToken"),
)

# AuthenticateTaskToken middleware
checker.check(
    "AuthenticateTaskToken middleware exists",
    file_exists("app/Http/Middleware/AuthenticateTaskToken.php"),
)
checker.check(
    "Middleware validates bearer token",
    file_contains(
        "app/Http/Middleware/AuthenticateTaskToken.php",
        "bearerToken",
    ),
)
checker.check(
    "Middleware uses TaskTokenService for validation",
    file_contains(
        "app/Http/Middleware/AuthenticateTaskToken.php",
        "TaskTokenService",
    ),
)
checker.check(
    "Middleware returns 401 on invalid token",
    file_contains(
        "app/Http/Middleware/AuthenticateTaskToken.php",
        "401",
    ),
)

# StoreTaskResultRequest FormRequest
checker.check(
    "StoreTaskResultRequest exists",
    file_exists("app/Http/Requests/StoreTaskResultRequest.php"),
)
checker.check(
    "FormRequest validates status field",
    file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "'status'",
    ),
)
checker.check(
    "FormRequest validates completed/failed status values",
    file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "completed",
    )
    and file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "failed",
    ),
)
checker.check(
    "FormRequest validates tokens fields",
    file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "tokens.input",
    ),
)
checker.check(
    "FormRequest validates duration_seconds",
    file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "duration_seconds",
    ),
)
checker.check(
    "FormRequest validates prompt_version fields",
    file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "prompt_version.skill",
    ),
)
checker.check(
    "FormRequest requires result when status is completed",
    file_contains(
        "app/Http/Requests/StoreTaskResultRequest.php",
        "required_if:status,completed",
    ),
)

# TaskResultController
checker.check(
    "TaskResultController exists",
    file_exists("app/Http/Controllers/TaskResultController.php"),
)
checker.check(
    "Controller uses StoreTaskResultRequest",
    file_contains(
        "app/Http/Controllers/TaskResultController.php",
        "StoreTaskResultRequest",
    ),
)
checker.check(
    "Controller dispatches ProcessTaskResult for completed results",
    file_contains(
        "app/Http/Controllers/TaskResultController.php",
        "ProcessTaskResult::dispatch",
    ),
)
checker.check(
    "Controller transitions task to failed",
    file_contains(
        "app/Http/Controllers/TaskResultController.php",
        "TaskStatus::Failed",
    ),
)
checker.check(
    "Controller checks task is in running state",
    file_contains(
        "app/Http/Controllers/TaskResultController.php",
        "TaskStatus::Running",
    ),
)
checker.check(
    "Controller returns 409 for non-running tasks",
    file_contains(
        "app/Http/Controllers/TaskResultController.php",
        "409",
    ),
)

# Tests
checker.check(
    "TaskResultApi feature test exists",
    file_exists("tests/Feature/TaskResultApiTest.php"),
)
checker.check(
    "Test covers completed result acceptance",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "accepts a completed result",
    ),
)
checker.check(
    "Test covers failed result acceptance",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "accepts a failed result",
    ),
)
checker.check(
    "Test covers 401 — missing token",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "401 when bearer token is missing",
    ),
)
checker.check(
    "Test covers 401 — invalid token",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "401 when bearer token is invalid",
    ),
)
checker.check(
    "Test covers 401 — wrong task (token scoping)",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "token belongs to a different task",
    ),
)
checker.check(
    "Test covers 401 — expired token",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "401 when bearer token is expired",
    ),
)
checker.check(
    "Test covers 404 — non-existent task",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "404 when task does not exist",
    ),
)
checker.check(
    "Test covers 422 — validation errors",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "422 when status field is missing",
    ),
)
checker.check(
    "Test covers 409 — non-running task",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "409 when task is already completed",
    ),
)
checker.check(
    "Test covers cross-task token reuse security",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "cross-task token reuse",
    ),
)

# ============================================================
#  T30: Structured output schema — code review
# ============================================================
section("T30: Structured Output Schema — Code Review")

# Schema class
checker.check(
    "CodeReviewSchema class exists",
    file_exists("app/Schemas/CodeReviewSchema.php"),
)
checker.check(
    "Schema defines version constant",
    file_contains("app/Schemas/CodeReviewSchema.php", "VERSION"),
)
checker.check(
    "Schema defines severity constants",
    file_contains("app/Schemas/CodeReviewSchema.php", "SEVERITIES"),
)
checker.check(
    "Schema defines category constants",
    file_contains("app/Schemas/CodeReviewSchema.php", "CATEGORIES"),
)
checker.check(
    "Schema defines risk level constants",
    file_contains("app/Schemas/CodeReviewSchema.php", "RISK_LEVELS"),
)
checker.check(
    "Schema defines commit status constants",
    file_contains("app/Schemas/CodeReviewSchema.php", "COMMIT_STATUSES"),
)

# validate() method
checker.check(
    "Schema has validate method",
    file_contains("app/Schemas/CodeReviewSchema.php", "function validate"),
)
checker.check(
    "validate uses Laravel Validator",
    file_contains("app/Schemas/CodeReviewSchema.php", "Validator::make"),
)
checker.check(
    "validate returns valid boolean and errors",
    file_contains("app/Schemas/CodeReviewSchema.php", "'valid'")
    and file_contains("app/Schemas/CodeReviewSchema.php", "'errors'"),
)

# strip() method
checker.check(
    "Schema has strip method",
    file_contains("app/Schemas/CodeReviewSchema.php", "function strip"),
)
checker.check(
    "strip uses array_intersect_key for field filtering",
    file_contains("app/Schemas/CodeReviewSchema.php", "array_intersect_key"),
)

# validateAndStrip() convenience method
checker.check(
    "Schema has validateAndStrip method",
    file_contains("app/Schemas/CodeReviewSchema.php", "function validateAndStrip"),
)

# rules() method — schema field validation
checker.check(
    "Schema has rules method",
    file_contains("app/Schemas/CodeReviewSchema.php", "function rules"),
)
checker.check(
    "Rules validate version field",
    file_contains("app/Schemas/CodeReviewSchema.php", "'version'"),
)
checker.check(
    "Rules validate summary as required array",
    file_contains("app/Schemas/CodeReviewSchema.php", "'summary'"),
)
checker.check(
    "Rules validate summary.risk_level",
    file_contains("app/Schemas/CodeReviewSchema.php", "summary.risk_level"),
)
checker.check(
    "Rules validate summary.total_findings",
    file_contains("app/Schemas/CodeReviewSchema.php", "summary.total_findings"),
)
checker.check(
    "Rules validate summary.findings_by_severity",
    file_contains("app/Schemas/CodeReviewSchema.php", "summary.findings_by_severity"),
)
checker.check(
    "Rules validate summary.walkthrough entries",
    file_contains("app/Schemas/CodeReviewSchema.php", "summary.walkthrough.*.file"),
)
checker.check(
    "Rules validate findings array items",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.severity"),
)
checker.check(
    "Rules validate finding category",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.category"),
)
checker.check(
    "Rules validate finding file path",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.file"),
)
checker.check(
    "Rules validate finding line numbers",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.line"),
)
checker.check(
    "Rules validate finding title",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.title"),
)
checker.check(
    "Rules validate finding description",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.description"),
)
checker.check(
    "Rules validate finding suggestion",
    file_contains("app/Schemas/CodeReviewSchema.php", "findings.*.suggestion"),
)
checker.check(
    "Rules validate labels array",
    file_contains("app/Schemas/CodeReviewSchema.php", "'labels'"),
)
checker.check(
    "Rules validate commit_status",
    file_contains("app/Schemas/CodeReviewSchema.php", "'commit_status'"),
)
checker.check(
    "Rules use Rule::in for severity enum validation",
    file_contains("app/Schemas/CodeReviewSchema.php", "Rule::in"),
)

# Tests
checker.check(
    "CodeReviewSchema test file exists",
    file_exists("tests/Unit/Schemas/CodeReviewSchemaTest.php"),
)
checker.check(
    "Test covers valid complete result",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "validates a complete valid code review result",
    ),
)
checker.check(
    "Test covers missing summary fails",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "fails when summary is missing",
    ),
)
checker.check(
    "Test covers invalid severity fails",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "fails when severity has an invalid value",
    ),
)
checker.check(
    "Test covers extra fields stripped",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "strips unknown top-level fields",
    ),
)
checker.check(
    "Test covers extra fields stripped from findings",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "strips unknown fields from findings",
    ),
)
checker.check(
    "Test covers validateAndStrip valid path",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "returns stripped data when valid via validateAndStrip",
    ),
)
checker.check(
    "Test covers validateAndStrip invalid path",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "returns null data when invalid via validateAndStrip",
    ),
)

# ============================================================
#  T31: Structured output schema — feature dev + UI adjustment
# ============================================================
section("T31: Structured Output Schema — Feature Dev + UI Adjustment")

# FeatureDevSchema class
checker.check(
    "FeatureDevSchema class exists",
    file_exists("app/Schemas/FeatureDevSchema.php"),
)
checker.check(
    "FeatureDevSchema defines version constant",
    file_contains("app/Schemas/FeatureDevSchema.php", "VERSION"),
)
checker.check(
    "FeatureDevSchema defines file action constants",
    file_contains("app/Schemas/FeatureDevSchema.php", "FILE_ACTIONS"),
)

# validate() method
checker.check(
    "FeatureDevSchema has validate method",
    file_contains("app/Schemas/FeatureDevSchema.php", "function validate"),
)
checker.check(
    "FeatureDevSchema validate uses Laravel Validator",
    file_contains("app/Schemas/FeatureDevSchema.php", "Validator::make"),
)

# strip() method
checker.check(
    "FeatureDevSchema has strip method",
    file_contains("app/Schemas/FeatureDevSchema.php", "function strip"),
)

# validateAndStrip() convenience method
checker.check(
    "FeatureDevSchema has validateAndStrip method",
    file_contains("app/Schemas/FeatureDevSchema.php", "function validateAndStrip"),
)

# rules() method — schema field validation
checker.check(
    "FeatureDevSchema has rules method",
    file_contains("app/Schemas/FeatureDevSchema.php", "function rules"),
)
checker.check(
    "Rules validate branch field",
    file_contains("app/Schemas/FeatureDevSchema.php", "'branch'"),
)
checker.check(
    "Rules validate mr_title field",
    file_contains("app/Schemas/FeatureDevSchema.php", "'mr_title'"),
)
checker.check(
    "Rules validate mr_description field",
    file_contains("app/Schemas/FeatureDevSchema.php", "'mr_description'"),
)
checker.check(
    "Rules validate files_changed array",
    file_contains("app/Schemas/FeatureDevSchema.php", "'files_changed'"),
)
checker.check(
    "Rules validate files_changed entry path",
    file_contains("app/Schemas/FeatureDevSchema.php", "files_changed.*.path"),
)
checker.check(
    "Rules validate files_changed entry action",
    file_contains("app/Schemas/FeatureDevSchema.php", "files_changed.*.action"),
)
checker.check(
    "Rules validate files_changed entry summary",
    file_contains("app/Schemas/FeatureDevSchema.php", "files_changed.*.summary"),
)
checker.check(
    "Rules validate tests_added field",
    file_contains("app/Schemas/FeatureDevSchema.php", "'tests_added'"),
)
checker.check(
    "Rules validate notes field",
    file_contains("app/Schemas/FeatureDevSchema.php", "'notes'"),
)

# UiAdjustmentSchema class
checker.check(
    "UiAdjustmentSchema class exists",
    file_exists("app/Schemas/UiAdjustmentSchema.php"),
)
checker.check(
    "UiAdjustmentSchema extends FeatureDevSchema",
    file_contains("app/Schemas/UiAdjustmentSchema.php", "extends FeatureDevSchema"),
)
checker.check(
    "UiAdjustmentSchema validates screenshot field",
    file_contains("app/Schemas/UiAdjustmentSchema.php", "'screenshot'"),
)
checker.check(
    "UiAdjustmentSchema validates screenshot_mobile field",
    file_contains("app/Schemas/UiAdjustmentSchema.php", "'screenshot_mobile'"),
)
checker.check(
    "UiAdjustmentSchema allows nullable screenshots",
    file_contains("app/Schemas/UiAdjustmentSchema.php", "'nullable'"),
)

# Tests — FeatureDevSchema
checker.check(
    "FeatureDevSchema test file exists",
    file_exists("tests/Unit/Schemas/FeatureDevSchemaTest.php"),
)
checker.check(
    "Test covers valid complete feature dev result",
    file_contains(
        "tests/Unit/Schemas/FeatureDevSchemaTest.php",
        "validates a complete valid feature dev result",
    ),
)
checker.check(
    "Test covers missing branch fails",
    file_contains(
        "tests/Unit/Schemas/FeatureDevSchemaTest.php",
        "fails when branch is missing",
    ),
)
checker.check(
    "Test covers invalid file action fails",
    file_contains(
        "tests/Unit/Schemas/FeatureDevSchemaTest.php",
        "fails when file action has an invalid value",
    ),
)
checker.check(
    "Test covers extra fields stripped",
    file_contains(
        "tests/Unit/Schemas/FeatureDevSchemaTest.php",
        "strips unknown top-level fields",
    ),
)
checker.check(
    "Test covers validateAndStrip valid path",
    file_contains(
        "tests/Unit/Schemas/FeatureDevSchemaTest.php",
        "returns stripped data when valid via validateAndStrip",
    ),
)
checker.check(
    "Test covers validateAndStrip invalid path",
    file_contains(
        "tests/Unit/Schemas/FeatureDevSchemaTest.php",
        "returns null data when invalid via validateAndStrip",
    ),
)

# Tests — UiAdjustmentSchema
checker.check(
    "UiAdjustmentSchema test file exists",
    file_exists("tests/Unit/Schemas/UiAdjustmentSchemaTest.php"),
)
checker.check(
    "Test covers valid complete UI adjustment result",
    file_contains(
        "tests/Unit/Schemas/UiAdjustmentSchemaTest.php",
        "validates a complete valid UI adjustment result",
    ),
)
checker.check(
    "Test covers null screenshot (capture failed)",
    file_contains(
        "tests/Unit/Schemas/UiAdjustmentSchemaTest.php",
        "validates when screenshot is null",
    ),
)
checker.check(
    "Test covers missing screenshot field fails",
    file_contains(
        "tests/Unit/Schemas/UiAdjustmentSchemaTest.php",
        "fails when screenshot field is missing entirely",
    ),
)
checker.check(
    "Test covers extra fields stripped from UI adjustment",
    file_contains(
        "tests/Unit/Schemas/UiAdjustmentSchemaTest.php",
        "strips unknown top-level fields",
    ),
)
checker.check(
    "Test covers screenshot preservation through strip",
    file_contains(
        "tests/Unit/Schemas/UiAdjustmentSchemaTest.php",
        "preserves screenshot fields through strip",
    ),
)

# ============================================================
#  T32: Result Processor service
# ============================================================
section("T32: Result Processor Service")

checker.check(
    "ResultProcessor service exists",
    file_exists("app/Services/ResultProcessor.php"),
)
checker.check(
    "ResultProcessor has process method",
    file_contains("app/Services/ResultProcessor.php", "function process(Task"),
)
checker.check(
    "ResultProcessor has schemaFor method",
    file_contains("app/Services/ResultProcessor.php", "function schemaFor(TaskType"),
)
checker.check(
    "ResultProcessor maps code_review to CodeReviewSchema",
    file_contains("app/Services/ResultProcessor.php", "'code_review' => CodeReviewSchema::class"),
)
checker.check(
    "ResultProcessor maps security_audit to CodeReviewSchema",
    file_contains("app/Services/ResultProcessor.php", "'security_audit' => CodeReviewSchema::class"),
)
checker.check(
    "ResultProcessor maps feature_dev to FeatureDevSchema",
    file_contains("app/Services/ResultProcessor.php", "'feature_dev' => FeatureDevSchema::class"),
)
checker.check(
    "ResultProcessor maps ui_adjustment to UiAdjustmentSchema",
    file_contains("app/Services/ResultProcessor.php", "'ui_adjustment' => UiAdjustmentSchema::class"),
)
checker.check(
    "ResultProcessor transitions to Completed on success",
    file_contains("app/Services/ResultProcessor.php", "TaskStatus::Completed"),
)
checker.check(
    "ResultProcessor transitions to Failed on validation error",
    file_contains("app/Services/ResultProcessor.php", "TaskStatus::Failed"),
)
checker.check(
    "ResultProcessor stores sanitized result back on task",
    file_contains("app/Services/ResultProcessor.php", "$task->result = $validation['data']"),
)
checker.check(
    "ProcessTaskResult job exists",
    file_exists("app/Jobs/ProcessTaskResult.php"),
)
checker.check(
    "ProcessTaskResult implements ShouldQueue",
    file_contains("app/Jobs/ProcessTaskResult.php", "ShouldQueue"),
)
checker.check(
    "ProcessTaskResult uses vunnix-server queue",
    file_contains("app/Jobs/ProcessTaskResult.php", "QueueNames::SERVER"),
)
checker.check(
    "ProcessTaskResult calls ResultProcessor",
    file_contains("app/Jobs/ProcessTaskResult.php", "ResultProcessor"),
)
checker.check(
    "ProcessTaskResult checks task is still Running",
    file_contains("app/Jobs/ProcessTaskResult.php", "TaskStatus::Running"),
)
checker.check(
    "ResultProcessor test file exists",
    file_exists("tests/Feature/Services/ResultProcessorTest.php"),
)
checker.check(
    "Test covers valid code review processing",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "valid code review result",
    ),
)
checker.check(
    "Test covers invalid code review processing",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "invalid code review result",
    ),
)
checker.check(
    "Test covers security audit using CodeReviewSchema",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "security audit using CodeReviewSchema",
    ),
)
checker.check(
    "Test covers feature dev processing",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "valid feature dev result",
    ),
)
checker.check(
    "Test covers UI adjustment processing",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "valid UI adjustment result",
    ),
)
checker.check(
    "Test covers issue discussion passthrough",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "issue discussion results without schema validation",
    ),
)
checker.check(
    "Test covers PRD creation passthrough",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "PRD creation results without schema validation",
    ),
)
checker.check(
    "Test covers null result handling",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "result is null",
    ),
)
checker.check(
    "Test covers schema routing",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "maps task types to correct schemas",
    ),
)
checker.check(
    "Test covers sanitized result storage",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "sanitized result back on the task",
    ),
)
checker.check(
    "Test covers extra field stripping",
    file_contains(
        "tests/Feature/Services/ResultProcessorTest.php",
        "strips extra fields",
    ),
)
checker.check(
    "Integration test covers ResultProcessor via API",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "transitions task to completed via Result Processor",
    ),
)
checker.check(
    "Integration test covers ProcessTaskResult job dispatch",
    file_contains(
        "tests/Feature/TaskResultApiTest.php",
        "ProcessTaskResult",
    ),
)

# ============================================================
#  T33: Summary comment — Layer 1
# ============================================================
section("T33: Summary Comment — Layer 1")

# Formatter service
checker.check(
    "SummaryCommentFormatter service exists",
    file_exists("app/Services/SummaryCommentFormatter.php"),
)
checker.check(
    "SummaryCommentFormatter has format method",
    file_contains("app/Services/SummaryCommentFormatter.php", "function format(array"),
)
checker.check(
    "SummaryCommentFormatter has risk badge mapping",
    file_contains("app/Services/SummaryCommentFormatter.php", "RISK_BADGES"),
)
checker.check(
    "SummaryCommentFormatter has severity badge mapping",
    file_contains("app/Services/SummaryCommentFormatter.php", "SEVERITY_BADGES"),
)
checker.check(
    "SummaryCommentFormatter produces correct header",
    file_contains("app/Services/SummaryCommentFormatter.php", "AI Code Review"),
)
checker.check(
    "SummaryCommentFormatter produces collapsible walkthrough",
    file_contains("app/Services/SummaryCommentFormatter.php", "Walkthrough"),
)
checker.check(
    "SummaryCommentFormatter produces collapsible findings",
    file_contains("app/Services/SummaryCommentFormatter.php", "Findings Summary"),
)

# PostSummaryComment job
checker.check(
    "PostSummaryComment job exists",
    file_exists("app/Jobs/PostSummaryComment.php"),
)
checker.check(
    "PostSummaryComment implements ShouldQueue",
    file_contains("app/Jobs/PostSummaryComment.php", "ShouldQueue"),
)
checker.check(
    "PostSummaryComment uses vunnix-server queue",
    file_contains("app/Jobs/PostSummaryComment.php", "QueueNames::SERVER"),
)
checker.check(
    "PostSummaryComment calls createMergeRequestNote",
    file_contains("app/Jobs/PostSummaryComment.php", "createMergeRequestNote"),
)
checker.check(
    "PostSummaryComment stores comment_id on task",
    file_contains("app/Jobs/PostSummaryComment.php", "comment_id"),
)
checker.check(
    "PostSummaryComment uses SummaryCommentFormatter",
    file_contains("app/Jobs/PostSummaryComment.php", "SummaryCommentFormatter"),
)

# ProcessTaskResult dispatches PostSummaryComment
checker.check(
    "ProcessTaskResult dispatches PostSummaryComment",
    file_contains("app/Jobs/ProcessTaskResult.php", "PostSummaryComment"),
)
checker.check(
    "ProcessTaskResult checks task type for summary comment dispatch",
    file_contains("app/Jobs/ProcessTaskResult.php", "CodeReview"),
)

# Tests
checker.check(
    "SummaryCommentFormatter unit test exists",
    file_exists("tests/Unit/Services/SummaryCommentFormatterTest.php"),
)
checker.check(
    "Test covers mixed-severity formatting",
    file_contains(
        "tests/Unit/Services/SummaryCommentFormatterTest.php",
        "mixed-severity review",
    ),
)
checker.check(
    "Test covers zero-findings edge case",
    file_contains(
        "tests/Unit/Services/SummaryCommentFormatterTest.php",
        "zero-findings review",
    ),
)
checker.check(
    "Test covers all-critical edge case",
    file_contains(
        "tests/Unit/Services/SummaryCommentFormatterTest.php",
        "all-critical review",
    ),
)
checker.check(
    "PostSummaryComment feature test exists",
    file_exists("tests/Feature/Jobs/PostSummaryCommentTest.php"),
)
checker.check(
    "Test covers posting comment and storing note ID",
    file_contains(
        "tests/Feature/Jobs/PostSummaryCommentTest.php",
        "stores the note ID",
    ),
)
checker.check(
    "ProcessTaskResult dispatch test exists",
    file_exists("tests/Feature/Jobs/ProcessTaskResultDispatchTest.php"),
)
checker.check(
    "Test covers dispatch for code review",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "code review processing",
    ),
)
checker.check(
    "Test covers no dispatch for non-review types",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "non-review task types",
    ),
)
checker.check(
    "Test covers no dispatch on validation failure",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "validation fails",
    ),
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
