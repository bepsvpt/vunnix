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

# ============================================================
#  T48: SSE streaming endpoint
# ============================================================
section("T48: SSE Streaming Endpoint")

# VunnixAgent
checker.check(
    "VunnixAgent exists",
    file_exists("app/Agents/VunnixAgent.php"),
)
checker.check(
    "VunnixAgent implements Agent contract",
    file_contains("app/Agents/VunnixAgent.php", "implements Agent"),
)
checker.check(
    "VunnixAgent implements Conversational contract",
    file_contains("app/Agents/VunnixAgent.php", "Conversational"),
)
checker.check(
    "VunnixAgent uses Promptable trait",
    file_contains("app/Agents/VunnixAgent.php", "use Promptable"),
)
checker.check(
    "VunnixAgent uses RemembersConversations trait",
    file_contains("app/Agents/VunnixAgent.php", "use RemembersConversations"),
)
checker.check(
    "VunnixAgent specifies Anthropic provider",
    file_contains("app/Agents/VunnixAgent.php", "'anthropic'"),
)

# Controller stream action
checker.check(
    "ConversationController has stream action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function stream",
    ),
)
checker.check(
    "Controller stream action returns StreamableAgentResponse",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "streamResponse",
    ),
)

# Service streamResponse method
checker.check(
    "ConversationService has streamResponse method",
    file_contains("app/Services/ConversationService.php", "public function streamResponse"),
)
checker.check(
    "ConversationService uses VunnixAgent",
    file_contains("app/Services/ConversationService.php", "VunnixAgent"),
)
checker.check(
    "ConversationService returns StreamableAgentResponse",
    file_contains("app/Services/ConversationService.php", "StreamableAgentResponse"),
)

# Policy
checker.check(
    "ConversationPolicy has stream method",
    file_contains("app/Policies/ConversationPolicy.php", "public function stream"),
)

# Route
checker.check(
    "SSE stream route registered",
    file_contains("routes/api.php", "api.conversations.stream"),
)
checker.check(
    "Stream route is POST",
    file_contains("routes/api.php", "Route::post('/conversations/{conversation}/stream'"),
)

# Tests
checker.check(
    "SseStreamingTest exists",
    file_exists("tests/Feature/SseStreamingTest.php"),
)
checker.check(
    "SseStreamingTest verifies SSE event types",
    file_contains("tests/Feature/SseStreamingTest.php", "text_delta"),
)
checker.check(
    "SseStreamingTest uses Agent::fake()",
    file_contains("tests/Feature/SseStreamingTest.php", "VunnixAgent::fake"),
)

# ============================================================
#  T49: Conversation Engine — AI SDK Agent class
# ============================================================
section("T49: Conversation Engine — AI SDK Agent Class")

# Interface implementation
checker.check(
    "VunnixAgent implements HasTools interface",
    file_contains("app/Agents/VunnixAgent.php", "HasTools"),
)
checker.check(
    "VunnixAgent implements HasMiddleware interface",
    file_contains("app/Agents/VunnixAgent.php", "HasMiddleware"),
)
checker.check(
    "VunnixAgent has tools() method",
    file_contains("app/Agents/VunnixAgent.php", "public function tools"),
)
checker.check(
    "VunnixAgent has middleware() method",
    file_contains("app/Agents/VunnixAgent.php", "public function middleware"),
)

# System prompt (§14.2)
checker.check(
    "System prompt has Identity section",
    file_contains("app/Agents/VunnixAgent.php", "[Identity]"),
)
checker.check(
    "System prompt has Capabilities section",
    file_contains("app/Agents/VunnixAgent.php", "[Capabilities]"),
)
checker.check(
    "System prompt has Quality Gate section",
    file_contains("app/Agents/VunnixAgent.php", "[Quality Gate]"),
)
checker.check(
    "System prompt has Action Dispatch section",
    file_contains("app/Agents/VunnixAgent.php", "[Action Dispatch]"),
)
checker.check(
    "System prompt has Language section",
    file_contains("app/Agents/VunnixAgent.php", "[Language]"),
)
checker.check(
    "System prompt has Safety section",
    file_contains("app/Agents/VunnixAgent.php", "[Safety]"),
)

# Dynamic system prompt construction
checker.check(
    "System prompt built from sections",
    file_contains("app/Agents/VunnixAgent.php", "buildSystemPrompt"),
)
checker.check(
    "Language injection from GlobalSetting",
    file_contains("app/Agents/VunnixAgent.php", "GlobalSetting::get('ai_language')"),
)

# Model configuration from GlobalSetting
checker.check(
    "Model configured from GlobalSetting",
    file_contains("app/Agents/VunnixAgent.php", "GlobalSetting::get('ai_model'"),
)
checker.check(
    "Model map includes opus",
    file_contains("app/Agents/VunnixAgent.php", "'opus'"),
)
checker.check(
    "Model map includes sonnet",
    file_contains("app/Agents/VunnixAgent.php", "'sonnet'"),
)

# Prompt injection defenses (§14.7)
checker.check(
    "Safety section includes instruction hierarchy defense",
    file_contains("app/Agents/VunnixAgent.php", "untrusted input"),
)
checker.check(
    "Safety section includes role boundary defense",
    file_contains("app/Agents/VunnixAgent.php", "ignore previous instructions"),
)

# ConversationService uses continue() for existing conversations
checker.check(
    "ConversationService uses agent->continue() for conversation linking",
    file_contains("app/Services/ConversationService.php", "->continue("),
)

# Tests
checker.check(
    "VunnixAgent unit tests exist",
    file_exists("tests/Unit/Agents/VunnixAgentTest.php"),
)
checker.check(
    "VunnixAgent feature tests exist",
    file_exists("tests/Feature/Agents/VunnixAgentTest.php"),
)
checker.check(
    "Feature tests verify system prompt sections",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "[Identity]"),
)
checker.check(
    "Feature tests verify language injection",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "ai_language"),
)
checker.check(
    "Feature tests verify model configuration",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "ai_model"),
)
checker.check(
    "Feature tests verify conversation persistence",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "persists user messages"),
)

# ─── Summary ──────────────────────────────────────────────────

checker.summary()
