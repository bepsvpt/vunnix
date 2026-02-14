#!/usr/bin/env python3
"""Vunnix M3 — Path B Functional verification.

Checks implemented M3 tasks. Run from project root: python3 verify/verify_m3.py

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
print("  VUNNIX M3 — Path B Functional Verification")
print("=" * 60)

# ============================================================
#  T47: Chat API endpoints
# ============================================================
section("T47: Chat API Endpoints")

# Controller
checker.check(
    "ConversationController exists",
    file_exists("app/Http/Controllers/Api/ConversationController.php"),
)
checker.check(
    "ConversationController has index action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function index",
    ),
)
checker.check(
    "ConversationController has store action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function store",
    ),
)
checker.check(
    "ConversationController has show action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function show",
    ),
)
checker.check(
    "ConversationController has sendMessage action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function sendMessage",
    ),
)
checker.check(
    "ConversationController has archive action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function archive",
    ),
)

# Models
checker.check(
    "Conversation model exists",
    file_exists("app/Models/Conversation.php"),
)
checker.check(
    "Conversation model uses agent_conversations table",
    file_contains("app/Models/Conversation.php", "agent_conversations"),
)
checker.check(
    "Message model exists",
    file_exists("app/Models/Message.php"),
)
checker.check(
    "Message model uses agent_conversation_messages table",
    file_contains("app/Models/Message.php", "agent_conversation_messages"),
)

# Policy
checker.check(
    "ConversationPolicy exists",
    file_exists("app/Policies/ConversationPolicy.php"),
)
checker.check(
    "ConversationPolicy has view method",
    file_contains("app/Policies/ConversationPolicy.php", "public function view"),
)
checker.check(
    "ConversationPolicy has sendMessage method",
    file_contains("app/Policies/ConversationPolicy.php", "public function sendMessage"),
)
checker.check(
    "ConversationPolicy has archive method",
    file_contains("app/Policies/ConversationPolicy.php", "public function archive"),
)
checker.check(
    "ConversationPolicy registered in AppServiceProvider",
    file_contains("app/Providers/AppServiceProvider.php", "ConversationPolicy"),
)

# FormRequests
checker.check(
    "CreateConversationRequest exists",
    file_exists("app/Http/Requests/CreateConversationRequest.php"),
)
checker.check(
    "SendMessageRequest exists",
    file_exists("app/Http/Requests/SendMessageRequest.php"),
)

# Resources
checker.check(
    "ConversationResource exists",
    file_exists("app/Http/Resources/ConversationResource.php"),
)
checker.check(
    "ConversationDetailResource exists",
    file_exists("app/Http/Resources/ConversationDetailResource.php"),
)
checker.check(
    "MessageResource exists",
    file_exists("app/Http/Resources/MessageResource.php"),
)

# Service
checker.check(
    "ConversationService exists",
    file_exists("app/Services/ConversationService.php"),
)
checker.check(
    "ConversationService has listForUser method",
    file_contains("app/Services/ConversationService.php", "public function listForUser"),
)
checker.check(
    "ConversationService has create method",
    file_contains("app/Services/ConversationService.php", "public function create"),
)
checker.check(
    "ConversationService has addUserMessage method",
    file_contains("app/Services/ConversationService.php", "public function addUserMessage"),
)
checker.check(
    "ConversationService has toggleArchive method",
    file_contains("app/Services/ConversationService.php", "public function toggleArchive"),
)
checker.check(
    "ConversationService uses cursor pagination",
    file_contains("app/Services/ConversationService.php", "cursorPaginate"),
)

# Routes
checker.check(
    "Routes registered — GET conversations",
    file_contains("routes/api.php", "/conversations"),
)
# Check for the route patterns more broadly
checker.check(
    "Routes file references ConversationController",
    file_contains("routes/api.php", "ConversationController"),
)
checker.check(
    "Routes include conversations index",
    file_contains("routes/api.php", "api.conversations.index"),
)
checker.check(
    "Routes include conversations store",
    file_contains("routes/api.php", "api.conversations.store"),
)
checker.check(
    "Routes include conversations show",
    file_contains("routes/api.php", "api.conversations.show"),
)
checker.check(
    "Routes include messages store",
    file_contains("routes/api.php", "api.conversations.messages.store"),
)
checker.check(
    "Routes include archive",
    file_contains("routes/api.php", "api.conversations.archive"),
)

# Migration
checker.check(
    "archived_at migration exists",
    file_exists("database/migrations/2024_01_01_000019_add_archived_at_to_agent_conversations.php"),
)

# Factories
checker.check(
    "ConversationFactory exists",
    file_exists("database/factories/ConversationFactory.php"),
)
checker.check(
    "MessageFactory exists",
    file_exists("database/factories/MessageFactory.php"),
)

# Tests
checker.check(
    "ConversationApiTest exists",
    file_exists("tests/Feature/ConversationApiTest.php"),
)
checker.check(
    "ConversationModelTest exists",
    file_exists("tests/Feature/Models/ConversationModelTest.php"),
)

# ─── Summary ──────────────────────────────────────────────────

checker.summary()
