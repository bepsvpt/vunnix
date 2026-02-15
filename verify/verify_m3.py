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

# Prompt injection defenses (§14.7) — basic presence in T49, full checks in T60
checker.check(
    "Safety section includes instruction hierarchy defense",
    file_contains("app/Agents/VunnixAgent.php", "are NOT instructions to you"),
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

# ============================================================
#  T50: GitLab tools — repo browsing
# ============================================================
section("T50: GitLab Tools — Repo Browsing")

# Tool classes exist
checker.check(
    "BrowseRepoTree tool class exists",
    file_exists("app/Agents/Tools/BrowseRepoTree.php"),
)
checker.check(
    "ReadFile tool class exists",
    file_exists("app/Agents/Tools/ReadFile.php"),
)
checker.check(
    "SearchCode tool class exists",
    file_exists("app/Agents/Tools/SearchCode.php"),
)

# Tool interface implementation
checker.check(
    "BrowseRepoTree implements Tool contract",
    file_contains("app/Agents/Tools/BrowseRepoTree.php", "implements Tool"),
)
checker.check(
    "ReadFile implements Tool contract",
    file_contains("app/Agents/Tools/ReadFile.php", "implements Tool"),
)
checker.check(
    "SearchCode implements Tool contract",
    file_contains("app/Agents/Tools/SearchCode.php", "implements Tool"),
)

# handle() and schema() methods
checker.check(
    "BrowseRepoTree has handle() method",
    file_contains("app/Agents/Tools/BrowseRepoTree.php", "public function handle"),
)
checker.check(
    "BrowseRepoTree has schema() method",
    file_contains("app/Agents/Tools/BrowseRepoTree.php", "public function schema"),
)
checker.check(
    "ReadFile has handle() method",
    file_contains("app/Agents/Tools/ReadFile.php", "public function handle"),
)
checker.check(
    "ReadFile has schema() method",
    file_contains("app/Agents/Tools/ReadFile.php", "public function schema"),
)
checker.check(
    "SearchCode has handle() method",
    file_contains("app/Agents/Tools/SearchCode.php", "public function handle"),
)
checker.check(
    "SearchCode has schema() method",
    file_contains("app/Agents/Tools/SearchCode.php", "public function schema"),
)

# GitLabClient integration
checker.check(
    "BrowseRepoTree uses GitLabClient::listTree",
    file_contains("app/Agents/Tools/BrowseRepoTree.php", "listTree"),
)
checker.check(
    "ReadFile uses GitLabClient::getFile",
    file_contains("app/Agents/Tools/ReadFile.php", "getFile"),
)
checker.check(
    "SearchCode uses GitLabClient::searchCode",
    file_contains("app/Agents/Tools/SearchCode.php", "searchCode"),
)
checker.check(
    "GitLabClient has searchCode method",
    file_contains("app/Services/GitLabClient.php", "public function searchCode"),
)

# Error handling — tools return messages, not exceptions
checker.check(
    "BrowseRepoTree catches GitLabApiException",
    file_contains("app/Agents/Tools/BrowseRepoTree.php", "catch (GitLabApiException"),
)
checker.check(
    "ReadFile catches GitLabApiException",
    file_contains("app/Agents/Tools/ReadFile.php", "catch (GitLabApiException"),
)
checker.check(
    "SearchCode catches GitLabApiException",
    file_contains("app/Agents/Tools/SearchCode.php", "catch (GitLabApiException"),
)

# ReadFile base64 decoding
checker.check(
    "ReadFile decodes base64 content",
    file_contains("app/Agents/Tools/ReadFile.php", "base64_decode"),
)

# ReadFile large file handling
checker.check(
    "ReadFile truncates large files",
    file_contains("app/Agents/Tools/ReadFile.php", "MAX_FILE_SIZE"),
)

# Tools registered in VunnixAgent
checker.check(
    "VunnixAgent registers BrowseRepoTree tool",
    file_contains("app/Agents/VunnixAgent.php", "BrowseRepoTree"),
)
checker.check(
    "VunnixAgent registers ReadFile tool",
    file_contains("app/Agents/VunnixAgent.php", "ReadFile"),
)
checker.check(
    "VunnixAgent registers SearchCode tool",
    file_contains("app/Agents/VunnixAgent.php", "SearchCode"),
)

# Unit tests
checker.check(
    "BrowseRepoTree unit tests exist",
    file_exists("tests/Unit/Agents/Tools/BrowseRepoTreeTest.php"),
)
checker.check(
    "ReadFile unit tests exist",
    file_exists("tests/Unit/Agents/Tools/ReadFileTest.php"),
)
checker.check(
    "SearchCode unit tests exist",
    file_exists("tests/Unit/Agents/Tools/SearchCodeTest.php"),
)

# Test coverage of key scenarios
checker.check(
    "BrowseRepoTree test covers error handling",
    file_contains("tests/Unit/Agents/Tools/BrowseRepoTreeTest.php", "Error browsing repository"),
)
checker.check(
    "ReadFile test covers large file truncation",
    file_contains("tests/Unit/Agents/Tools/ReadFileTest.php", "truncates files larger than 100KB"),
)
checker.check(
    "ReadFile test covers binary file handling",
    file_contains("tests/Unit/Agents/Tools/ReadFileTest.php", "binary file"),
)
checker.check(
    "SearchCode test covers snippet truncation",
    file_contains("tests/Unit/Agents/Tools/SearchCodeTest.php", "truncates long code snippets"),
)
checker.check(
    "VunnixAgent test verifies T50 tools",
    file_contains("tests/Unit/Agents/VunnixAgentTest.php", "T50 repo browsing tools"),
)

# ============================================================
#  T51: GitLab tools — Issues
# ============================================================
section("T51: GitLab Tools — Issues")

# Tool classes exist
checker.check(
    "ListIssues tool class exists",
    file_exists("app/Agents/Tools/ListIssues.php"),
)
checker.check(
    "ReadIssue tool class exists",
    file_exists("app/Agents/Tools/ReadIssue.php"),
)

# Tool interface implementation
checker.check(
    "ListIssues implements Tool contract",
    file_contains("app/Agents/Tools/ListIssues.php", "implements Tool"),
)
checker.check(
    "ReadIssue implements Tool contract",
    file_contains("app/Agents/Tools/ReadIssue.php", "implements Tool"),
)

# handle() and schema() methods
checker.check(
    "ListIssues has handle() method",
    file_contains("app/Agents/Tools/ListIssues.php", "public function handle"),
)
checker.check(
    "ListIssues has schema() method",
    file_contains("app/Agents/Tools/ListIssues.php", "public function schema"),
)
checker.check(
    "ReadIssue has handle() method",
    file_contains("app/Agents/Tools/ReadIssue.php", "public function handle"),
)
checker.check(
    "ReadIssue has schema() method",
    file_contains("app/Agents/Tools/ReadIssue.php", "public function schema"),
)

# GitLabClient integration
checker.check(
    "ListIssues uses GitLabClient::listIssues",
    file_contains("app/Agents/Tools/ListIssues.php", "listIssues"),
)
checker.check(
    "ReadIssue uses GitLabClient::getIssue",
    file_contains("app/Agents/Tools/ReadIssue.php", "getIssue"),
)

# Filter parameters in ListIssues schema
checker.check(
    "ListIssues schema has state filter",
    file_contains("app/Agents/Tools/ListIssues.php", "'state'"),
)
checker.check(
    "ListIssues schema has labels filter",
    file_contains("app/Agents/Tools/ListIssues.php", "'labels'"),
)
checker.check(
    "ListIssues schema has search filter",
    file_contains("app/Agents/Tools/ListIssues.php", "'search'"),
)
checker.check(
    "ListIssues schema has per_page parameter",
    file_contains("app/Agents/Tools/ListIssues.php", "'per_page'"),
)

# ReadIssue parameters
checker.check(
    "ReadIssue schema has issue_iid parameter",
    file_contains("app/Agents/Tools/ReadIssue.php", "'issue_iid'"),
)

# Error handling — tools return messages, not exceptions
checker.check(
    "ListIssues catches GitLabApiException",
    file_contains("app/Agents/Tools/ListIssues.php", "catch (GitLabApiException"),
)
checker.check(
    "ReadIssue catches GitLabApiException",
    file_contains("app/Agents/Tools/ReadIssue.php", "catch (GitLabApiException"),
)

# ReadIssue parses full details
checker.check(
    "ReadIssue includes description in output",
    file_contains("app/Agents/Tools/ReadIssue.php", "description"),
)
checker.check(
    "ReadIssue includes labels in output",
    file_contains("app/Agents/Tools/ReadIssue.php", "labels"),
)
checker.check(
    "ReadIssue includes assignees in output",
    file_contains("app/Agents/Tools/ReadIssue.php", "assignees"),
)

# Tools registered in VunnixAgent
checker.check(
    "VunnixAgent registers ListIssues tool",
    file_contains("app/Agents/VunnixAgent.php", "ListIssues"),
)
checker.check(
    "VunnixAgent registers ReadIssue tool",
    file_contains("app/Agents/VunnixAgent.php", "ReadIssue"),
)

# Unit tests
checker.check(
    "ListIssues unit tests exist",
    file_exists("tests/Unit/Agents/Tools/ListIssuesTest.php"),
)
checker.check(
    "ReadIssue unit tests exist",
    file_exists("tests/Unit/Agents/Tools/ReadIssueTest.php"),
)

# Test coverage of key scenarios
checker.check(
    "ListIssues test covers filter parameters",
    file_contains("tests/Unit/Agents/Tools/ListIssuesTest.php", "passes state filter"),
)
checker.check(
    "ListIssues test covers error handling",
    file_contains("tests/Unit/Agents/Tools/ListIssuesTest.php", "Error listing issues"),
)
checker.check(
    "ReadIssue test covers full details parsing",
    file_contains("tests/Unit/Agents/Tools/ReadIssueTest.php", "formatted issue details"),
)
checker.check(
    "ReadIssue test covers error handling",
    file_contains("tests/Unit/Agents/Tools/ReadIssueTest.php", "Error reading issue"),
)
checker.check(
    "VunnixAgent test verifies T51 tools",
    file_contains("tests/Unit/Agents/VunnixAgentTest.php", "T51 issue tools"),
)

# ============================================================
#  T52: GitLab tools — Merge Requests
# ============================================================
section("T52: GitLab Tools — Merge Requests")

# Tool classes exist
checker.check(
    "ListMergeRequests tool class exists",
    file_exists("app/Agents/Tools/ListMergeRequests.php"),
)
checker.check(
    "ReadMergeRequest tool class exists",
    file_exists("app/Agents/Tools/ReadMergeRequest.php"),
)
checker.check(
    "ReadMRDiff tool class exists",
    file_exists("app/Agents/Tools/ReadMRDiff.php"),
)

# Tool interface implementation
checker.check(
    "ListMergeRequests implements Tool contract",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "implements Tool"),
)
checker.check(
    "ReadMergeRequest implements Tool contract",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "implements Tool"),
)
checker.check(
    "ReadMRDiff implements Tool contract",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "implements Tool"),
)

# handle() and schema() methods
checker.check(
    "ListMergeRequests has handle() method",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "public function handle"),
)
checker.check(
    "ListMergeRequests has schema() method",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "public function schema"),
)
checker.check(
    "ReadMergeRequest has handle() method",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "public function handle"),
)
checker.check(
    "ReadMergeRequest has schema() method",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "public function schema"),
)
checker.check(
    "ReadMRDiff has handle() method",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "public function handle"),
)
checker.check(
    "ReadMRDiff has schema() method",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "public function schema"),
)

# GitLabClient integration
checker.check(
    "ListMergeRequests uses GitLabClient::listMergeRequests",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "listMergeRequests"),
)
checker.check(
    "ReadMergeRequest uses GitLabClient::getMergeRequest",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "getMergeRequest"),
)
checker.check(
    "ReadMRDiff uses GitLabClient::getMergeRequestChanges",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "getMergeRequestChanges"),
)

# Filter parameters in ListMergeRequests schema
checker.check(
    "ListMergeRequests schema has state filter",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "'state'"),
)
checker.check(
    "ListMergeRequests schema has labels filter",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "'labels'"),
)
checker.check(
    "ListMergeRequests schema has search filter",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "'search'"),
)
checker.check(
    "ListMergeRequests schema has per_page parameter",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "'per_page'"),
)

# ReadMergeRequest parameters
checker.check(
    "ReadMergeRequest schema has mr_iid parameter",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "'mr_iid'"),
)

# ReadMRDiff parameters
checker.check(
    "ReadMRDiff schema has mr_iid parameter",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "'mr_iid'"),
)

# Error handling — tools return messages, not exceptions
checker.check(
    "ListMergeRequests catches GitLabApiException",
    file_contains("app/Agents/Tools/ListMergeRequests.php", "catch (GitLabApiException"),
)
checker.check(
    "ReadMergeRequest catches GitLabApiException",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "catch (GitLabApiException"),
)
checker.check(
    "ReadMRDiff catches GitLabApiException",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "catch (GitLabApiException"),
)

# ReadMergeRequest parses MR-specific fields
checker.check(
    "ReadMergeRequest includes source_branch",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "source_branch"),
)
checker.check(
    "ReadMergeRequest includes target_branch",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "target_branch"),
)
checker.check(
    "ReadMergeRequest includes merge_status",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "merge_status"),
)
checker.check(
    "ReadMergeRequest includes description",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "description"),
)
checker.check(
    "ReadMergeRequest includes assignees",
    file_contains("app/Agents/Tools/ReadMergeRequest.php", "assignees"),
)

# ReadMRDiff parses diff hunks
checker.check(
    "ReadMRDiff parses changes array",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "changes"),
)
checker.check(
    "ReadMRDiff handles new files",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "new_file"),
)
checker.check(
    "ReadMRDiff handles deleted files",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "deleted_file"),
)
checker.check(
    "ReadMRDiff handles renamed files",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "renamed_file"),
)
checker.check(
    "ReadMRDiff truncates large diffs",
    file_contains("app/Agents/Tools/ReadMRDiff.php", "MAX_OUTPUT_SIZE"),
)

# Tools registered in VunnixAgent
checker.check(
    "VunnixAgent registers ListMergeRequests tool",
    file_contains("app/Agents/VunnixAgent.php", "ListMergeRequests"),
)
checker.check(
    "VunnixAgent registers ReadMergeRequest tool",
    file_contains("app/Agents/VunnixAgent.php", "ReadMergeRequest"),
)
checker.check(
    "VunnixAgent registers ReadMRDiff tool",
    file_contains("app/Agents/VunnixAgent.php", "ReadMRDiff"),
)

# Unit tests
checker.check(
    "ListMergeRequests unit tests exist",
    file_exists("tests/Unit/Agents/Tools/ListMergeRequestsTest.php"),
)
checker.check(
    "ReadMergeRequest unit tests exist",
    file_exists("tests/Unit/Agents/Tools/ReadMergeRequestTest.php"),
)
checker.check(
    "ReadMRDiff unit tests exist",
    file_exists("tests/Unit/Agents/Tools/ReadMRDiffTest.php"),
)

# Test coverage of key scenarios
checker.check(
    "ListMergeRequests test covers filter parameters",
    file_contains("tests/Unit/Agents/Tools/ListMergeRequestsTest.php", "passes state filter"),
)
checker.check(
    "ListMergeRequests test covers error handling",
    file_contains("tests/Unit/Agents/Tools/ListMergeRequestsTest.php", "Error listing merge requests"),
)
checker.check(
    "ReadMergeRequest test covers full details parsing",
    file_contains("tests/Unit/Agents/Tools/ReadMergeRequestTest.php", "formatted merge request details"),
)
checker.check(
    "ReadMergeRequest test covers error handling",
    file_contains("tests/Unit/Agents/Tools/ReadMergeRequestTest.php", "Error reading merge request"),
)
checker.check(
    "ReadMRDiff test covers diff parsing",
    file_contains("tests/Unit/Agents/Tools/ReadMRDiffTest.php", "formatted diff with file headers"),
)
checker.check(
    "ReadMRDiff test covers large diff truncation",
    file_contains("tests/Unit/Agents/Tools/ReadMRDiffTest.php", "truncates large diffs"),
)
checker.check(
    "ReadMRDiff test covers error handling",
    file_contains("tests/Unit/Agents/Tools/ReadMRDiffTest.php", "Error reading merge request diff"),
)
checker.check(
    "VunnixAgent test verifies T52 tools",
    file_contains("tests/Unit/Agents/VunnixAgentTest.php", "T52 merge request tools"),
)

# ============================================================
#  T53: Cross-project tool-use access check
# ============================================================
section("T53: Cross-Project Tool-Use Access Check")

# ProjectAccessChecker service
checker.check(
    "ProjectAccessChecker service exists",
    file_exists("app/Services/ProjectAccessChecker.php"),
)
checker.check(
    "ProjectAccessChecker has check method",
    file_contains("app/Services/ProjectAccessChecker.php", "public function check"),
)

# All tools use ProjectAccessChecker
for tool_name in [
    "BrowseRepoTree",
    "ReadFile",
    "SearchCode",
    "ListIssues",
    "ReadIssue",
    "ListMergeRequests",
    "ReadMergeRequest",
    "ReadMRDiff",
]:
    checker.check(
        f"{tool_name} uses ProjectAccessChecker",
        file_contains(f"app/Agents/Tools/{tool_name}.php", "ProjectAccessChecker"),
    )

# Tests
checker.check(
    "ProjectAccessChecker tests exist",
    file_exists("tests/Feature/Services/ProjectAccessCheckerTest.php"),
)

# ============================================================
#  T54: Quality gate behavior
# ============================================================
section("T54: Quality Gate Behavior")

# Enhanced quality gate section in system prompt
checker.check(
    "Quality gate uses challenge → justify → accept pattern",
    file_contains("app/Agents/VunnixAgent.php", "challenge → justify → accept"),
)
checker.check(
    "Quality gate has PM-specific challenge patterns",
    file_contains("app/Agents/VunnixAgent.php", "For Product Managers"),
)
checker.check(
    "Quality gate has Designer-specific challenge patterns",
    file_contains("app/Agents/VunnixAgent.php", "For Designers"),
)
checker.check(
    "Quality gate references citing code context",
    file_contains("app/Agents/VunnixAgent.php", "cite specific files"),
)
checker.check(
    "Quality gate mentions design tokens",
    file_contains("app/Agents/VunnixAgent.php", "design tokens"),
)
checker.check(
    "Quality gate collaborative tone",
    file_contains("app/Agents/VunnixAgent.php", "collaborative, not adversarial"),
)

# Tests — quality gate prompt content
checker.check(
    "Feature test verifies PM challenge patterns",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "PM-specific challenge patterns"),
)
checker.check(
    "Feature test verifies Designer challenge patterns",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "Designer-specific challenge patterns"),
)
checker.check(
    "Feature test verifies quality gate general rules",
    file_contains("tests/Feature/Agents/VunnixAgentTest.php", "quality gate general rules"),
)

# Integration test — conversation flow
checker.check(
    "Quality gate integration test exists",
    file_contains(
        "tests/Feature/Agents/VunnixAgentTest.php",
        "quality gate conversation flow",
    ),
)
checker.check(
    "Integration test covers challenge → accept pattern",
    file_contains(
        "tests/Feature/Agents/VunnixAgentTest.php",
        "vague request → challenge → clarify → accept",
    ),
)

# ============================================================
#  T55: Action dispatch from conversation
# ============================================================
section("T55: Action Dispatch from Conversation")

# DispatchAction tool class
checker.check(
    "DispatchAction tool class exists",
    file_exists("app/Agents/Tools/DispatchAction.php"),
)
checker.check(
    "DispatchAction implements Tool contract",
    file_contains("app/Agents/Tools/DispatchAction.php", "implements Tool"),
)
checker.check(
    "DispatchAction has handle method",
    file_contains("app/Agents/Tools/DispatchAction.php", "public function handle"),
)
checker.check(
    "DispatchAction has schema method",
    file_contains("app/Agents/Tools/DispatchAction.php", "public function schema"),
)

# Permission check
checker.check(
    "DispatchAction checks chat.dispatch_task permission",
    file_contains("app/Agents/Tools/DispatchAction.php", "chat.dispatch_task"),
)

# Task creation with conversation origin
checker.check(
    "DispatchAction uses TaskOrigin::Conversation",
    file_contains("app/Agents/Tools/DispatchAction.php", "TaskOrigin::Conversation"),
)
checker.check(
    "DispatchAction sets conversation_id on task",
    file_contains("app/Agents/Tools/DispatchAction.php", "conversation_id"),
)

# Action type mapping
checker.check(
    "DispatchAction supports create_issue",
    file_contains("app/Agents/Tools/DispatchAction.php", "create_issue"),
)
checker.check(
    "DispatchAction supports implement_feature",
    file_contains("app/Agents/Tools/DispatchAction.php", "implement_feature"),
)
checker.check(
    "DispatchAction supports ui_adjustment",
    file_contains("app/Agents/Tools/DispatchAction.php", "ui_adjustment"),
)
checker.check(
    "DispatchAction supports create_mr",
    file_contains("app/Agents/Tools/DispatchAction.php", "create_mr"),
)
checker.check(
    "DispatchAction supports deep_analysis",
    file_contains("app/Agents/Tools/DispatchAction.php", "deep_analysis"),
)

# Deep analysis D132
checker.check(
    "DeepAnalysis task type exists",
    file_contains("app/Enums/TaskType.php", "DeepAnalysis"),
)

# VunnixAgent registers DispatchAction
checker.check(
    "VunnixAgent registers DispatchAction tool",
    file_contains("app/Agents/VunnixAgent.php", "DispatchAction"),
)

# System prompt updates
checker.check(
    "System prompt lists supported action types",
    file_contains("app/Agents/VunnixAgent.php", "create_issue"),
)
checker.check(
    "System prompt mentions permission handling",
    file_contains("app/Agents/VunnixAgent.php", "chat.dispatch_task"),
)
checker.check(
    "System prompt describes deep analysis D132",
    file_contains("app/Agents/VunnixAgent.php", "deep analysis"),
)

# Uses TaskDispatcher
checker.check(
    "DispatchAction uses TaskDispatcher",
    file_contains("app/Agents/Tools/DispatchAction.php", "TaskDispatcher"),
)
checker.check(
    "DispatchAction uses ProjectAccessChecker",
    file_contains("app/Agents/Tools/DispatchAction.php", "ProjectAccessChecker"),
)

# Unit tests
checker.check(
    "DispatchAction unit tests exist",
    file_exists("tests/Unit/Agents/Tools/DispatchActionTest.php"),
)

# Feature tests
checker.check(
    "DispatchAction feature tests exist",
    file_exists("tests/Feature/Agents/Tools/DispatchActionFeatureTest.php"),
)
checker.check(
    "Feature test covers permission denial",
    file_contains(
        "tests/Feature/Agents/Tools/DispatchActionFeatureTest.php",
        "lacks chat.dispatch_task",
    ),
)
checker.check(
    "Feature test covers task creation with conversation origin",
    file_contains(
        "tests/Feature/Agents/Tools/DispatchActionFeatureTest.php",
        "conversation origin",
    ),
)
checker.check(
    "Feature test covers deep_analysis action type",
    file_contains(
        "tests/Feature/Agents/Tools/DispatchActionFeatureTest.php",
        "deep_analysis",
    ),
)

# ============================================================
#  T56: Server-side execution mode (create Issue bypass)
# ============================================================
section("T56: Server-Side Execution Mode")

# CreateGitLabIssue job
checker.check(
    "CreateGitLabIssue job exists",
    file_exists("app/Jobs/CreateGitLabIssue.php"),
)
checker.check(
    "CreateGitLabIssue implements ShouldQueue",
    file_contains("app/Jobs/CreateGitLabIssue.php", "implements ShouldQueue"),
)
checker.check(
    "CreateGitLabIssue uses vunnix-server queue",
    file_contains("app/Jobs/CreateGitLabIssue.php", "QueueNames::SERVER"),
)
checker.check(
    "CreateGitLabIssue uses RetryWithBackoff middleware",
    file_contains("app/Jobs/CreateGitLabIssue.php", "RetryWithBackoff"),
)
checker.check(
    "CreateGitLabIssue calls GitLabClient::createIssue",
    file_contains("app/Jobs/CreateGitLabIssue.php", "createIssue"),
)
checker.check(
    "CreateGitLabIssue stores issue_iid on task",
    file_contains("app/Jobs/CreateGitLabIssue.php", "issue_iid"),
)
checker.check(
    "CreateGitLabIssue sets PM as assignee via assignee_ids",
    file_contains("app/Jobs/CreateGitLabIssue.php", "assignee_ids"),
)
checker.check(
    "CreateGitLabIssue stores gitlab_issue_url in result",
    file_contains("app/Jobs/CreateGitLabIssue.php", "gitlab_issue_url"),
)
checker.check(
    "CreateGitLabIssue handles labels",
    file_contains("app/Jobs/CreateGitLabIssue.php", "labels"),
)
checker.check(
    "CreateGitLabIssue rethrows errors for retry",
    file_contains("app/Jobs/CreateGitLabIssue.php", "throw $e"),
)

# ProcessTaskResult wiring — dispatches CreateGitLabIssue for PrdCreation
checker.check(
    "ProcessTaskResult dispatches CreateGitLabIssue",
    file_contains("app/Jobs/ProcessTaskResult.php", "CreateGitLabIssue::dispatch"),
)
checker.check(
    "ProcessTaskResult has shouldCreateGitLabIssue method",
    file_contains("app/Jobs/ProcessTaskResult.php", "shouldCreateGitLabIssue"),
)
checker.check(
    "shouldCreateGitLabIssue checks PrdCreation type",
    file_contains("app/Jobs/ProcessTaskResult.php", "TaskType::PrdCreation"),
)

# TaskDispatcher wiring — dispatches ProcessTaskResult for server-side tasks
checker.check(
    "TaskDispatcher dispatches ProcessTaskResult for server-side tasks",
    file_contains("app/Services/TaskDispatcher.php", "ProcessTaskResult::dispatch"),
)

# Tests
checker.check(
    "CreateGitLabIssue feature tests exist",
    file_exists("tests/Feature/Jobs/CreateGitLabIssueTest.php"),
)
checker.check(
    "CreateGitLabIssue test covers happy path with assignee and labels",
    file_contains(
        "tests/Feature/Jobs/CreateGitLabIssueTest.php",
        "creates a GitLab Issue via bot PAT and stores issue_iid on task",
    ),
)
checker.check(
    "CreateGitLabIssue test covers missing assignee",
    file_contains(
        "tests/Feature/Jobs/CreateGitLabIssueTest.php",
        "without assignee when assignee_id is not provided",
    ),
)
checker.check(
    "CreateGitLabIssue test covers error rethrow for retry",
    file_contains(
        "tests/Feature/Jobs/CreateGitLabIssueTest.php",
        "rethrows GitLab API errors for retry",
    ),
)
checker.check(
    "ProcessTaskResult dispatch test covers CreateGitLabIssue",
    file_contains(
        "tests/Feature/Jobs/ProcessTaskResultDispatchTest.php",
        "CreateGitLabIssue after successful PrdCreation",
    ),
)
checker.check(
    "TaskDispatcher test covers ProcessTaskResult dispatch",
    file_contains(
        "tests/Feature/Services/TaskDispatcherTest.php",
        "dispatches ProcessTaskResult for server-side PrdCreation",
    ),
)

# Integration test — full pipeline
checker.check(
    "Server-side integration test exists",
    file_exists("tests/Feature/Integration/ServerSideExecutionTest.php"),
)
checker.check(
    "Integration test covers full pipeline: dispatch → process → create Issue",
    file_contains(
        "tests/Feature/Integration/ServerSideExecutionTest.php",
        "full server-side pipeline",
    ),
)
checker.check(
    "Integration test verifies no CI pipeline triggered",
    file_contains(
        "tests/Feature/Integration/ServerSideExecutionTest.php",
        "does not trigger a CI pipeline",
    ),
)

# ============================================================
#  T57: Structured output schema — action dispatch
# ============================================================
section("T57: Structured Output Schema — Action Dispatch")

# Schema class exists
checker.check(
    "ActionDispatchSchema class exists",
    file_exists("app/Schemas/ActionDispatchSchema.php"),
)
checker.check(
    "ActionDispatchSchema has VERSION constant",
    file_contains("app/Schemas/ActionDispatchSchema.php", "VERSION"),
)
checker.check(
    "ActionDispatchSchema has ACTION_TYPES constant",
    file_contains("app/Schemas/ActionDispatchSchema.php", "ACTION_TYPES"),
)

# Core methods (validate, strip, validateAndStrip, rules)
checker.check(
    "ActionDispatchSchema has validate method",
    file_contains("app/Schemas/ActionDispatchSchema.php", "public static function validate"),
)
checker.check(
    "ActionDispatchSchema has strip method",
    file_contains("app/Schemas/ActionDispatchSchema.php", "public static function strip"),
)
checker.check(
    "ActionDispatchSchema has validateAndStrip method",
    file_contains("app/Schemas/ActionDispatchSchema.php", "public static function validateAndStrip"),
)
checker.check(
    "ActionDispatchSchema has rules method",
    file_contains("app/Schemas/ActionDispatchSchema.php", "public static function rules"),
)

# Required schema fields (§14.4)
checker.check(
    "Schema validates action_type field",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'action_type'"),
)
checker.check(
    "Schema validates project_id field",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'project_id'"),
)
checker.check(
    "Schema validates title field",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'title'"),
)
checker.check(
    "Schema validates description field",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'description'"),
)

# Optional fields
checker.check(
    "Schema has branch_name field (nullable)",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'branch_name'"),
)
checker.check(
    "Schema has target_branch field (nullable)",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'target_branch'"),
)
checker.check(
    "Schema has assignee_id field (nullable)",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'assignee_id'"),
)
checker.check(
    "Schema has labels array field",
    file_contains("app/Schemas/ActionDispatchSchema.php", "'labels'"),
)

# Action type enum validation
checker.check(
    "Schema validates action_type via Rule::in",
    file_contains("app/Schemas/ActionDispatchSchema.php", "Rule::in(self::ACTION_TYPES)"),
)
checker.check(
    "Schema includes all 5 action types",
    file_contains("app/Schemas/ActionDispatchSchema.php", "deep_analysis"),
)

# Unit tests
checker.check(
    "ActionDispatchSchema test file exists",
    file_exists("tests/Unit/Schemas/ActionDispatchSchemaTest.php"),
)
checker.check(
    "Test covers valid action dispatch",
    file_contains(
        "tests/Unit/Schemas/ActionDispatchSchemaTest.php",
        "validates a complete valid action dispatch",
    ),
)
checker.check(
    "Test covers all action types",
    file_contains(
        "tests/Unit/Schemas/ActionDispatchSchemaTest.php",
        "validates all action types",
    ),
)
checker.check(
    "Test covers invalid action type rejection",
    file_contains(
        "tests/Unit/Schemas/ActionDispatchSchemaTest.php",
        "fails when action_type has an invalid value",
    ),
)
checker.check(
    "Test covers field stripping",
    file_contains(
        "tests/Unit/Schemas/ActionDispatchSchemaTest.php",
        "strips unknown top-level fields",
    ),
)
checker.check(
    "Test covers validateAndStrip",
    file_contains(
        "tests/Unit/Schemas/ActionDispatchSchemaTest.php",
        "returns stripped data when valid via validateAndStrip",
    ),
)
checker.check(
    "Test cross-validates against DispatchAction tool types",
    file_contains(
        "tests/Unit/Schemas/ActionDispatchSchemaTest.php",
        "action types match DispatchAction tool mapping",
    ),
)

# ============================================================
#  T58: Conversation pruning middleware (>20 turns)
# ============================================================
section("T58: Conversation Pruning Middleware")

# Middleware class
checker.check(
    "PruneConversationHistory middleware exists",
    file_exists("app/Agents/Middleware/PruneConversationHistory.php"),
)
checker.check(
    "Middleware has handle method",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "public function handle",
    ),
)
checker.check(
    "Middleware has TURN_THRESHOLD constant set to 20",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "TURN_THRESHOLD = 20",
    ),
)
checker.check(
    "Middleware has RECENT_TURNS_TO_KEEP constant set to 10",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "RECENT_TURNS_TO_KEEP = 10",
    ),
)

# Turn counting
checker.check(
    "Middleware counts turns by user messages",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "countTurns",
    ),
)

# Summarization
checker.check(
    "Middleware summarizes older messages",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "summarize",
    ),
)
checker.check(
    "Middleware uses cheapestTextModel for summarization",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "cheapestTextModel",
    ),
)
checker.check(
    "Summarizer retains user intent and decisions",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "intent",
    ),
)

# Pruned message injection
checker.check(
    "Middleware calls setPrunedMessages on VunnixAgent",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "setPrunedMessages",
    ),
)
checker.check(
    "Summary injected as UserMessage with conversation summary header",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "Conversation Summary",
    ),
)

# Graceful degradation
checker.check(
    "Middleware catches Throwable for graceful failure",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "catch (Throwable)",
    ),
)

# Non-VunnixAgent passthrough
checker.check(
    "Middleware checks agent instanceof VunnixAgent",
    file_contains(
        "app/Agents/Middleware/PruneConversationHistory.php",
        "instanceof VunnixAgent",
    ),
)

# VunnixAgent integration
checker.check(
    "VunnixAgent has setPrunedMessages method",
    file_contains("app/Agents/VunnixAgent.php", "public function setPrunedMessages"),
)
checker.check(
    "VunnixAgent has prunedMessages property",
    file_contains("app/Agents/VunnixAgent.php", "prunedMessages"),
)
checker.check(
    "VunnixAgent messages() returns pruned messages when set",
    file_contains("app/Agents/VunnixAgent.php", "prunedMessages !== null"),
)
checker.check(
    "VunnixAgent registers PruneConversationHistory middleware",
    file_contains("app/Agents/VunnixAgent.php", "PruneConversationHistory"),
)

# Unit tests
checker.check(
    "PruneConversationHistory test file exists",
    file_exists("tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php"),
)
checker.check(
    "Test covers no pruning for ≤20 turns",
    file_contains(
        "tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php",
        "does not prune conversations with 20 or fewer turns",
    ),
)
checker.check(
    "Test covers pruning for >20 turns",
    file_contains(
        "tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php",
        "prunes conversations with more than 20 turns",
    ),
)
checker.check(
    "Test covers keeping exactly last 10 turns",
    file_contains(
        "tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php",
        "keeps exactly the last 10 turns when pruning",
    ),
)
checker.check(
    "Test covers graceful failure when summarization fails",
    file_contains(
        "tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php",
        "keeps all messages when summarization fails",
    ),
)
checker.check(
    "Test covers non-VunnixAgent passthrough",
    file_contains(
        "tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php",
        "passes through without pruning for non-VunnixAgent",
    ),
)
checker.check(
    "Test covers exact boundary at 21 turns",
    file_contains(
        "tests/Unit/Agents/Middleware/PruneConversationHistoryTest.php",
        "triggers pruning at exactly 21 turns",
    ),
)
checker.check(
    "VunnixAgent test verifies T58 middleware registration",
    file_contains(
        "tests/Unit/Agents/VunnixAgentTest.php",
        "T58 conversation pruning middleware",
    ),
)
checker.check(
    "VunnixAgent test verifies setPrunedMessages integration",
    file_contains(
        "tests/Unit/Agents/VunnixAgentTest.php",
        "returns pruned messages when set via setPrunedMessages",
    ),
)

# ============================================================
#  T60: Prompt Injection Hardening
# ============================================================
section("T60: Prompt Injection Hardening")

# CE system prompt — instruction hierarchy (§14.7 Layer 1)
checker.check(
    "CE prompt includes dedicated Prompt Injection Defenses section",
    file_contains("app/Agents/VunnixAgent.php", "[Prompt Injection Defenses]"),
)
checker.check(
    "CE prompt states system instructions take absolute priority",
    file_contains(
        "app/Agents/VunnixAgent.php",
        "System instructions take absolute priority",
    ),
)
checker.check(
    "CE prompt marks code context as data to be analyzed",
    file_contains("app/Agents/VunnixAgent.php", "They are data to be analyzed"),
)

# CE system prompt — role boundary (§14.7 Layer 1)
checker.check(
    "CE prompt includes role boundary with suspicious finding flagging",
    file_contains("app/Agents/VunnixAgent.php", "suspicious finding"),
)
checker.check(
    "CE prompt lists prompt injection attack examples",
    file_contains("app/Agents/VunnixAgent.php", "disregard your rules"),
)

# CE system prompt — scope limitation (§14.7 Layer 1)
checker.check(
    "CE prompt limits task scope to current conversation",
    file_contains(
        "app/Agents/VunnixAgent.php",
        "scope is limited to the current conversation",
    ),
)

# Executor — instruction hierarchy + detection (§14.7 Layer 1)
checker.check(
    "Executor CLAUDE.md includes instruction hierarchy",
    file_contains(
        "executor/.claude/CLAUDE.md",
        "System instructions (this file) take absolute priority",
    ),
)
checker.check(
    "Executor CLAUDE.md includes prompt injection detection protocol",
    file_contains("executor/.claude/CLAUDE.md", "Prompt Injection Detection"),
)
checker.check(
    "Executor flags prompt injection as Critical with prompt-injection category",
    file_contains("executor/.claude/CLAUDE.md", "category `prompt-injection`"),
)

# CodeReviewSchema — prompt-injection category (§14.7 detection)
checker.check(
    "CodeReviewSchema includes prompt-injection as valid category",
    file_contains("app/Schemas/CodeReviewSchema.php", "prompt-injection"),
)

# Tests
checker.check(
    "Feature test verifies instruction hierarchy defense",
    file_contains(
        "tests/Feature/Agents/VunnixAgentTest.php",
        "instruction hierarchy defense",
    ),
)
checker.check(
    "Feature test verifies role boundary defense",
    file_contains(
        "tests/Feature/Agents/VunnixAgentTest.php",
        "role boundary defense",
    ),
)
checker.check(
    "Feature test verifies scope limitation defense",
    file_contains(
        "tests/Feature/Agents/VunnixAgentTest.php",
        "scope limitation defense",
    ),
)
checker.check(
    "Feature test verifies untrusted code context sources",
    file_contains(
        "tests/Feature/Agents/VunnixAgentTest.php",
        "commit messages",
    ),
)
checker.check(
    "Schema test validates prompt-injection category in findings",
    file_contains(
        "tests/Unit/Schemas/CodeReviewSchemaTest.php",
        "prompt-injection",
    ),
)

# ============================================================
#  T61: Vue SPA Scaffold
# ============================================================
section("T61: Vue SPA Scaffold")

# Vue 3 + Vite + Pinia + Vue Router installed
checker.check(
    "Vue 3 is installed in package.json",
    file_contains("package.json", '"vue"'),
)
checker.check(
    "Vue Router is installed",
    file_contains("package.json", '"vue-router"'),
)
checker.check(
    "Pinia is installed",
    file_contains("package.json", '"pinia"'),
)
checker.check(
    "Vitest is installed",
    file_contains("package.json", '"vitest"'),
)
checker.check(
    "@vue/test-utils is installed",
    file_contains("package.json", '"@vue/test-utils"'),
)
checker.check(
    "@vitejs/plugin-vue is installed",
    file_contains("package.json", '"@vitejs/plugin-vue"'),
)
checker.check(
    "markdown-it is installed",
    file_contains("package.json", '"markdown-it"'),
)

# Vite config includes Vue plugin
checker.check(
    "Vite config imports Vue plugin",
    file_contains("vite.config.js", "@vitejs/plugin-vue"),
)
checker.check(
    "Vite config uses Vue plugin",
    file_contains("vite.config.js", "vue("),
)

# Vitest config exists
checker.check(
    "Vitest config file exists",
    file_exists("vitest.config.js"),
)
checker.check(
    "Vitest uses jsdom environment",
    file_contains("vitest.config.js", "jsdom"),
)

# SPA Blade template
checker.check(
    "SPA Blade template exists",
    file_exists("resources/views/app.blade.php"),
)
checker.check(
    "SPA template has Vue mount point",
    file_contains("resources/views/app.blade.php", 'id="app"'),
)
checker.check(
    "SPA template uses Vite directive",
    file_contains("resources/views/app.blade.php", "@vite"),
)

# Catch-all route for history mode
checker.check(
    "Web routes include SPA catch-all",
    file_contains("routes/web.php", "{any}"),
)
checker.check(
    "Catch-all returns app view",
    file_contains("routes/web.php", "view('app')"),
)

# Vue app entry point
checker.check(
    "App entry point mounts Vue app",
    file_contains("resources/js/app.js", "createApp"),
)
checker.check(
    "App entry point uses Pinia",
    file_contains("resources/js/app.js", "createPinia"),
)
checker.check(
    "App entry point uses router",
    file_contains("resources/js/app.js", "app.use(router)"),
)

# Root App.vue component
checker.check(
    "Root App.vue exists",
    file_exists("resources/js/App.vue"),
)
checker.check(
    "App.vue includes router-view",
    file_contains("resources/js/App.vue", "router-view"),
)
checker.check(
    "App.vue imports AppNavigation",
    file_contains("resources/js/App.vue", "AppNavigation"),
)

# Router with history mode
checker.check(
    "Router config exists",
    file_exists("resources/js/router/index.js"),
)
checker.check(
    "Router uses createWebHistory (history mode)",
    file_contains("resources/js/router/index.js", "createWebHistory"),
)
checker.check(
    "Router has chat route",
    file_contains("resources/js/router/index.js", "'/chat'"),
)
checker.check(
    "Router has dashboard route",
    file_contains("resources/js/router/index.js", "'/dashboard'"),
)
checker.check(
    "Router has admin route",
    file_contains("resources/js/router/index.js", "'/admin'"),
)
checker.check(
    "Root path redirects to /chat",
    file_contains("resources/js/router/index.js", "redirect"),
)

# Three page components
checker.check(
    "ChatPage component exists",
    file_exists("resources/js/pages/ChatPage.vue"),
)
checker.check(
    "DashboardPage component exists",
    file_exists("resources/js/pages/DashboardPage.vue"),
)
checker.check(
    "AdminPage component exists",
    file_exists("resources/js/pages/AdminPage.vue"),
)

# Navigation component
checker.check(
    "AppNavigation component exists",
    file_exists("resources/js/components/AppNavigation.vue"),
)
checker.check(
    "Navigation uses script setup",
    file_contains("resources/js/components/AppNavigation.vue", "<script setup>"),
)
checker.check(
    "Navigation includes mobile hamburger menu",
    file_contains(
        "resources/js/components/AppNavigation.vue", "mobileMenuOpen"
    ),
)
checker.check(
    "Navigation uses responsive breakpoint (md:hidden or md:flex)",
    file_contains("resources/js/components/AppNavigation.vue", "md:"),
)

# Auth store placeholder
checker.check(
    "Auth Pinia store exists",
    file_exists("resources/js/stores/auth.js"),
)
checker.check(
    "Auth store uses defineStore",
    file_contains("resources/js/stores/auth.js", "defineStore"),
)

# Tailwind scans Vue files
checker.check(
    "Tailwind CSS scans Vue files",
    file_contains("resources/css/app.css", "*.vue"),
)

# Vitest tests exist
checker.check(
    "Router tests exist",
    file_exists("resources/js/router/index.test.js"),
)
checker.check(
    "App component tests exist",
    file_exists("resources/js/App.test.js"),
)
checker.check(
    "Navigation component tests exist",
    file_exists("resources/js/components/AppNavigation.test.js"),
)

# ============================================================
#  T62: Auth State Management (Pinia)
# ============================================================
section("T62: Auth State Management (Pinia)")

# Auth store exists and has required methods
checker.check(
    "Auth store uses defineStore composable pattern",
    file_contains("resources/js/stores/auth.js", "defineStore('auth'"),
)
checker.check(
    "Auth store has fetchUser method",
    file_contains("resources/js/stores/auth.js", "async function fetchUser"),
)
checker.check(
    "Auth store calls /api/v1/user",
    file_contains("resources/js/stores/auth.js", "/api/v1/user"),
)
checker.check(
    "Auth store has login method (redirect to OAuth)",
    file_contains("resources/js/stores/auth.js", "function login"),
)
checker.check(
    "Auth store has logout method",
    file_contains("resources/js/stores/auth.js", "async function logout"),
)
checker.check(
    "Auth store has hasPermission method",
    file_contains("resources/js/stores/auth.js", "function hasPermission"),
)
checker.check(
    "Auth store has hasProjectPermission method",
    file_contains("resources/js/stores/auth.js", "function hasProjectPermission"),
)
checker.check(
    "Auth store has projects computed",
    file_contains("resources/js/stores/auth.js", "const projects = computed"),
)

# Router auth guard
checker.check(
    "Router has auth guard using beforeEach",
    file_contains("resources/js/router/index.js", "router.beforeEach"),
)
checker.check(
    "Router guard calls fetchUser on first nav",
    file_contains("resources/js/router/index.js", "auth.fetchUser"),
)

# User API endpoint
checker.check(
    "User API route registered",
    file_contains("routes/api.php", "api.user"),
)

# Tests
checker.check(
    "Auth store tests exist",
    file_exists("resources/js/stores/auth.test.js"),
)

# ============================================================
#  T63: Chat Page — Conversation List
# ============================================================
section("T63: Chat Page — Conversation List")

# Conversations Pinia store
checker.check(
    "Conversations store exists",
    file_exists("resources/js/stores/conversations.js"),
)
checker.check(
    "Conversations store uses defineStore",
    file_contains("resources/js/stores/conversations.js", "defineStore('conversations'"),
)
checker.check(
    "Conversations store has fetchConversations method",
    file_contains("resources/js/stores/conversations.js", "async function fetchConversations"),
)
checker.check(
    "Conversations store calls /api/v1/conversations",
    file_contains("resources/js/stores/conversations.js", "/api/v1/conversations"),
)
checker.check(
    "Conversations store has loadMore method (cursor pagination)",
    file_contains("resources/js/stores/conversations.js", "async function loadMore"),
)
checker.check(
    "Conversations store has toggleArchive method",
    file_contains("resources/js/stores/conversations.js", "async function toggleArchive"),
)
checker.check(
    "Conversations store tracks projectFilter state",
    file_contains("resources/js/stores/conversations.js", "const projectFilter"),
)
checker.check(
    "Conversations store tracks searchQuery state",
    file_contains("resources/js/stores/conversations.js", "const searchQuery"),
)
checker.check(
    "Conversations store tracks showArchived state",
    file_contains("resources/js/stores/conversations.js", "const showArchived"),
)
checker.check(
    "Conversations store has selectConversation method",
    file_contains("resources/js/stores/conversations.js", "function selectConversation"),
)

# ConversationListItem component
checker.check(
    "ConversationListItem component exists",
    file_exists("resources/js/components/ConversationListItem.vue"),
)
checker.check(
    "ConversationListItem uses script setup",
    file_contains("resources/js/components/ConversationListItem.vue", "<script setup>"),
)
checker.check(
    "ConversationListItem shows conversation title",
    file_contains("resources/js/components/ConversationListItem.vue", "conversation.title"),
)
checker.check(
    "ConversationListItem shows last message preview",
    file_contains("resources/js/components/ConversationListItem.vue", "lastMessagePreview"),
)
checker.check(
    "ConversationListItem shows relative time",
    file_contains("resources/js/components/ConversationListItem.vue", "relativeTime"),
)
checker.check(
    "ConversationListItem emits select and archive events",
    file_contains("resources/js/components/ConversationListItem.vue", "defineEmits"),
)

# ConversationList component
checker.check(
    "ConversationList component exists",
    file_exists("resources/js/components/ConversationList.vue"),
)
checker.check(
    "ConversationList uses script setup",
    file_contains("resources/js/components/ConversationList.vue", "<script setup>"),
)
checker.check(
    "ConversationList has search input",
    file_contains("resources/js/components/ConversationList.vue", "Search conversations"),
)
checker.check(
    "ConversationList has project filter dropdown",
    file_contains("resources/js/components/ConversationList.vue", "All projects"),
)
checker.check(
    "ConversationList has archive toggle",
    file_contains("resources/js/components/ConversationList.vue", "Archived"),
)
checker.check(
    "ConversationList renders ConversationListItem",
    file_contains("resources/js/components/ConversationList.vue", "ConversationListItem"),
)
checker.check(
    "ConversationList has load more button",
    file_contains("resources/js/components/ConversationList.vue", "Load more"),
)
checker.check(
    "ConversationList fetches on mount",
    file_contains("resources/js/components/ConversationList.vue", "onMounted"),
)

# ChatPage layout
checker.check(
    "ChatPage uses ConversationList",
    file_contains("resources/js/pages/ChatPage.vue", "ConversationList"),
)
checker.check(
    "ChatPage has sidebar layout",
    file_contains("resources/js/pages/ChatPage.vue", "aside"),
)

# Tests
checker.check(
    "Conversations store tests exist",
    file_exists("resources/js/stores/conversations.test.js"),
)
checker.check(
    "ConversationListItem tests exist",
    file_exists("resources/js/components/ConversationListItem.test.js"),
)
checker.check(
    "ConversationList tests exist",
    file_exists("resources/js/components/ConversationList.test.js"),
)

# ============================================================
#  T64: Chat Page — New Conversation Flow
# ============================================================
section("T64: Chat Page — New Conversation Flow")

# Backend: conversation_projects pivot migration
checker.check(
    "conversation_projects pivot migration exists",
    file_exists(
        "database/migrations/2024_01_01_000021_create_conversation_projects_table.php"
    ),
)

# Conversation model — projects relationship
checker.check(
    "Conversation model has projects relationship",
    file_contains("app/Models/Conversation.php", "public function projects"),
)
checker.check(
    "Conversation model references conversation_projects pivot table",
    file_contains("app/Models/Conversation.php", "conversation_projects"),
)

# ConversationPolicy — addProject method
checker.check(
    "ConversationPolicy has addProject method",
    file_contains("app/Policies/ConversationPolicy.php", "public function addProject"),
)

# API endpoint: POST /conversations/{id}/projects
checker.check(
    "ConversationController has addProject action",
    file_contains(
        "app/Http/Controllers/Api/ConversationController.php",
        "public function addProject",
    ),
)
checker.check(
    "Route for adding project to conversation exists",
    file_contains("routes/api.php", "api.conversations.projects.store"),
)

# ConversationResource includes projects via whenLoaded
checker.check(
    "ConversationResource includes projects via whenLoaded",
    file_contains(
        "app/Http/Resources/ConversationResource.php",
        "whenLoaded('projects'",
    ),
)

# NewConversationDialog component
checker.check(
    "NewConversationDialog component exists",
    file_exists("resources/js/components/NewConversationDialog.vue"),
)
checker.check(
    "NewConversationDialog uses script setup",
    file_contains("resources/js/components/NewConversationDialog.vue", "<script setup>"),
)
checker.check(
    "NewConversationDialog filters by chat.access permission",
    file_contains("resources/js/components/NewConversationDialog.vue", "chat.access"),
)
checker.check(
    "NewConversationDialog emits create event",
    file_contains("resources/js/components/NewConversationDialog.vue", "'create'"),
)

# CrossProjectWarningDialog component (D128)
checker.check(
    "CrossProjectWarningDialog component exists",
    file_exists("resources/js/components/CrossProjectWarningDialog.vue"),
)
checker.check(
    "CrossProjectWarningDialog uses script setup",
    file_contains("resources/js/components/CrossProjectWarningDialog.vue", "<script setup>"),
)
checker.check(
    "CrossProjectWarningDialog shows visibility warning",
    file_contains(
        "resources/js/components/CrossProjectWarningDialog.vue",
        "Cross-Project Visibility Warning",
    ),
)
checker.check(
    "CrossProjectWarningDialog warns about irreversibility",
    file_contains(
        "resources/js/components/CrossProjectWarningDialog.vue",
        "cannot be undone",
    ),
)
checker.check(
    "CrossProjectWarningDialog accepts existingProjectName prop",
    file_contains(
        "resources/js/components/CrossProjectWarningDialog.vue",
        "existingProjectName",
    ),
)
checker.check(
    "CrossProjectWarningDialog accepts newProjectName prop",
    file_contains(
        "resources/js/components/CrossProjectWarningDialog.vue",
        "newProjectName",
    ),
)

# Store: createConversation and addProjectToConversation
checker.check(
    "Conversations store has createConversation action",
    file_contains(
        "resources/js/stores/conversations.js",
        "async function createConversation",
    ),
)
checker.check(
    "Conversations store has addProjectToConversation action",
    file_contains(
        "resources/js/stores/conversations.js",
        "async function addProjectToConversation",
    ),
)
checker.check(
    "createConversation POSTs to /api/v1/conversations",
    file_contains(
        "resources/js/stores/conversations.js",
        "axios.post('/api/v1/conversations'",
    ),
)
checker.check(
    "addProjectToConversation POSTs to conversations/{id}/projects",
    file_contains(
        "resources/js/stores/conversations.js",
        "/projects",
    ),
)

# ConversationList integration
checker.check(
    "ConversationList imports NewConversationDialog",
    file_contains(
        "resources/js/components/ConversationList.vue",
        "import NewConversationDialog",
    ),
)
checker.check(
    "ConversationList has New Conversation button",
    file_contains(
        "resources/js/components/ConversationList.vue",
        "New Conversation",
    ),
)
checker.check(
    "ConversationList renders NewConversationDialog with v-if",
    file_contains(
        "resources/js/components/ConversationList.vue",
        "showNewDialog",
    ),
)

# Tests
checker.check(
    "NewConversationDialog tests exist",
    file_exists("resources/js/components/NewConversationDialog.test.js"),
)
checker.check(
    "CrossProjectWarningDialog tests exist",
    file_exists("resources/js/components/CrossProjectWarningDialog.test.js"),
)
checker.check(
    "Store tests cover createConversation",
    file_contains(
        "resources/js/stores/conversations.test.js",
        "createConversation",
    ),
)
checker.check(
    "Store tests cover addProjectToConversation",
    file_contains(
        "resources/js/stores/conversations.test.js",
        "addProjectToConversation",
    ),
)

# ============================================================
#  T65: Chat Page — Message Thread + Markdown Rendering
# ============================================================
section("T65: Chat Page — Message Thread + Markdown Rendering")

# MarkdownContent component
checker.check(
    "MarkdownContent component exists",
    file_exists("resources/js/components/MarkdownContent.vue"),
)
checker.check(
    "MarkdownContent imports markdown renderer",
    file_contains("resources/js/components/MarkdownContent.vue", "getMarkdownRenderer")
    or file_contains("resources/js/components/MarkdownContent.vue", "markdown-it"),
)
checker.check(
    "MarkdownContent uses v-html for rendered output",
    file_contains("resources/js/components/MarkdownContent.vue", "v-html"),
)
checker.check(
    "MarkdownContent test exists",
    file_exists("resources/js/components/MarkdownContent.test.js"),
)

# Markdown singleton module
checker.check(
    "Markdown lib module exists",
    file_exists("resources/js/lib/markdown.js"),
)
checker.check(
    "Markdown lib imports markdown-it",
    file_contains("resources/js/lib/markdown.js", "markdown-it"),
)
checker.check(
    "Markdown lib imports shiki plugin",
    file_contains("resources/js/lib/markdown.js", "@shikijs/markdown-it"),
)
checker.check(
    "Markdown lib sets link target _blank",
    file_contains("resources/js/lib/markdown.js", "_blank"),
)

# MessageBubble component
checker.check(
    "MessageBubble component exists",
    file_exists("resources/js/components/MessageBubble.vue"),
)
checker.check(
    "MessageBubble has role-based styling",
    file_contains("resources/js/components/MessageBubble.vue", "data-role"),
)
checker.check(
    "MessageBubble renders MarkdownContent for assistant",
    file_contains("resources/js/components/MessageBubble.vue", "MarkdownContent"),
)
checker.check(
    "MessageBubble test exists",
    file_exists("resources/js/components/MessageBubble.test.js"),
)

# MessageComposer component
checker.check(
    "MessageComposer component exists",
    file_exists("resources/js/components/MessageComposer.vue"),
)
checker.check(
    "MessageComposer has textarea",
    file_contains("resources/js/components/MessageComposer.vue", "textarea"),
)
checker.check(
    "MessageComposer emits send event",
    file_contains("resources/js/components/MessageComposer.vue", "emit('send'")
    or file_contains("resources/js/components/MessageComposer.vue", 'emit("send"'),
)
checker.check(
    "MessageComposer test exists",
    file_exists("resources/js/components/MessageComposer.test.js"),
)

# MessageThread component
checker.check(
    "MessageThread component exists",
    file_exists("resources/js/components/MessageThread.vue"),
)
checker.check(
    "MessageThread renders MessageBubble",
    file_contains("resources/js/components/MessageThread.vue", "MessageBubble"),
)
checker.check(
    "MessageThread renders MessageComposer",
    file_contains("resources/js/components/MessageThread.vue", "MessageComposer"),
)
checker.check(
    "MessageThread has scroll container",
    file_contains("resources/js/components/MessageThread.vue", "overflow-y-auto"),
)
checker.check(
    "MessageThread test exists",
    file_exists("resources/js/components/MessageThread.test.js"),
)

# Store integration
checker.check(
    "Conversations store has messages state",
    file_contains("resources/js/stores/conversations.js", "messages"),
)
checker.check(
    "Conversations store has fetchMessages action",
    file_contains("resources/js/stores/conversations.js", "fetchMessages"),
)
checker.check(
    "Conversations store has sendMessage action",
    file_contains("resources/js/stores/conversations.js", "sendMessage"),
)

# ChatPage integration
checker.check(
    "ChatPage imports MessageThread",
    file_contains("resources/js/pages/ChatPage.vue", "MessageThread"),
)
checker.check(
    "ChatPage no longer has T65 placeholder",
    not file_contains("resources/js/pages/ChatPage.vue", "Message thread coming in T65"),
)

# Markdown typography styles
checker.check(
    "CSS has markdown-content styles",
    file_contains("resources/css/app.css", ".markdown-content"),
)

# Shiki is installed
checker.check(
    "@shikijs/markdown-it is installed",
    file_contains("package.json", '"@shikijs/markdown-it"'),
)
checker.check(
    "shiki is installed",
    file_contains("package.json", '"shiki"'),
)

# ============================================================
#  T66: Chat Page — SSE Streaming (Connection Resilience)
# ============================================================
section("T66: Chat Page — SSE Streaming (Connection Resilience)")

# SSE library
checker.check(
    "SSE library module exists",
    file_exists("resources/js/lib/sse.js"),
)
checker.check(
    "SSE library exports streamSSE function",
    file_contains("resources/js/lib/sse.js", "export async function streamSSE"),
)
checker.check(
    "SSE library uses ReadableStream reader",
    file_contains("resources/js/lib/sse.js", "getReader()"),
)
checker.check(
    "SSE library handles [DONE] marker",
    file_contains("resources/js/lib/sse.js", "[DONE]"),
)
checker.check(
    "SSE library has onEvent callback",
    file_contains("resources/js/lib/sse.js", "onEvent"),
)
checker.check(
    "SSE library has onDone callback",
    file_contains("resources/js/lib/sse.js", "onDone"),
)
checker.check(
    "SSE library has onError callback",
    file_contains("resources/js/lib/sse.js", "onError"),
)
checker.check(
    "SSE library handles chunked delivery (buffer splitting)",
    file_contains("resources/js/lib/sse.js", "buffer"),
)
checker.check(
    "SSE library test file exists",
    file_exists("resources/js/lib/sse.test.js"),
)

# Store: streamMessage action
checker.check(
    "Conversations store has streamMessage action",
    file_contains("resources/js/stores/conversations.js", "async function streamMessage"),
)
checker.check(
    "streamMessage uses fetch with POST method",
    file_contains("resources/js/stores/conversations.js", "method: 'POST'"),
)
checker.check(
    "streamMessage sends to /stream endpoint",
    file_contains("resources/js/stores/conversations.js", "/stream"),
)
checker.check(
    "streamMessage uses streamSSE for parsing",
    file_contains("resources/js/stores/conversations.js", "streamSSE"),
)
checker.check(
    "streamMessage accumulates text_delta events",
    file_contains("resources/js/stores/conversations.js", "text_delta"),
)
checker.check(
    "streamMessage adds optimistic user message",
    file_contains("resources/js/stores/conversations.js", "role: 'user'"),
)
checker.check(
    "streamMessage includes CSRF token in headers",
    file_contains("resources/js/stores/conversations.js", "X-CSRF-TOKEN"),
)

# Store: streaming state
checker.check(
    "Conversations store has streaming ref",
    file_contains("resources/js/stores/conversations.js", "const streaming = ref("),
)
checker.check(
    "Conversations store has streamingContent ref",
    file_contains("resources/js/stores/conversations.js", "const streamingContent = ref("),
)
checker.check(
    "Conversations store exports streaming state",
    file_contains("resources/js/stores/conversations.js", "streaming,"),
)
checker.check(
    "Conversations store exports streamingContent state",
    file_contains("resources/js/stores/conversations.js", "streamingContent,"),
)

# Connection resilience: re-fetch on error
checker.check(
    "streamMessage re-fetches messages on stream error",
    file_contains("resources/js/stores/conversations.js", "fetchMessages(selectedId.value)"),
)

# Store tests for streaming
checker.check(
    "Store tests cover streamMessage describe block",
    file_contains("resources/js/stores/conversations.test.js", "describe('streamMessage'"),
)
checker.check(
    "Store tests cover optimistic user message",
    file_contains("resources/js/stores/conversations.test.js", "adds optimistic user message"),
)
checker.check(
    "Store tests cover text_delta accumulation",
    file_contains("resources/js/stores/conversations.test.js", "accumulates text_delta"),
)
checker.check(
    "Store tests cover streaming flag",
    file_contains("resources/js/stores/conversations.test.js", "sets streaming flag"),
)
checker.check(
    "Store tests cover connection resilience",
    file_contains("resources/js/stores/conversations.test.js", "re-fetches messages on stream error"),
)
checker.check(
    "Store tests cover CSRF token and credentials",
    file_contains("resources/js/stores/conversations.test.js", "CSRF token and credentials"),
)

# MessageThread streaming display
checker.check(
    "MessageThread uses streamMessage instead of sendMessage",
    file_contains("resources/js/components/MessageThread.vue", "store.streamMessage"),
)
checker.check(
    "MessageThread shows streaming bubble",
    file_contains("resources/js/components/MessageThread.vue", "streaming-bubble"),
)
checker.check(
    "MessageThread disables composer during streaming",
    file_contains("resources/js/components/MessageThread.vue", "store.streaming"),
)
checker.check(
    "MessageThread auto-scrolls on streaming content",
    file_contains("resources/js/components/MessageThread.vue", "store.streamingContent"),
)

# TypingIndicator component
checker.check(
    "TypingIndicator component exists",
    file_exists("resources/js/components/TypingIndicator.vue"),
)
checker.check(
    "TypingIndicator has data-testid for testing",
    file_contains("resources/js/components/TypingIndicator.vue", 'data-testid="typing-indicator"'),
)
checker.check(
    "TypingIndicator has animated dots",
    file_contains("resources/js/components/TypingIndicator.vue", "animate-bounce"),
)
checker.check(
    "TypingIndicator test file exists",
    file_exists("resources/js/components/TypingIndicator.test.js"),
)

# MessageThread streaming tests
checker.check(
    "MessageThread tests cover typing indicator",
    file_contains("resources/js/components/MessageThread.test.js", "typing indicator"),
)
checker.check(
    "MessageThread tests cover streaming bubble",
    file_contains("resources/js/components/MessageThread.test.js", "streaming bubble"),
)
checker.check(
    "MessageThread tests cover streamMessage call",
    file_contains("resources/js/components/MessageThread.test.js", "streamMessage"),
)
checker.check(
    "MessageThread tests cover composer disabled during streaming",
    file_contains("resources/js/components/MessageThread.test.js", "disables composer while streaming"),
)

# ─── Summary ──────────────────────────────────────────────────

checker.summary()
