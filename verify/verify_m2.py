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
    file_contains("app/Jobs/PostHelpResponse.php", "vunnix-server"),
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
