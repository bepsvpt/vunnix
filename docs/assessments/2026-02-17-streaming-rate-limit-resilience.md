# Assessment: Streaming Rate Limit Resilience

**Date:** 2026-02-17
**Requested by:** Kevin (triggered by production `RateLimitedException`)
**Trigger:** `RateLimitedException` crashed the SSE chat stream mid-conversation after 3 successful tool calls. The Anthropic API returned a rate limit (429) during the 4th AI SDK call within a single streaming session. No retry, no graceful degradation — the stream died with an unhandled exception.

## What

Add rate limit resilience to the AI chat streaming pipeline. Currently, the synchronous streaming endpoint (`POST /api/v1/conversations/{id}/stream`) has zero error handling for Anthropic API rate limits. Background jobs have exponential backoff via `RetryWithBackoff` middleware (D32), but the chat streaming path — which is the primary user-facing interaction — propagates `RateLimitedException` as an unhandled HTTP error. This extension adds: (1) server-side retry with exponential backoff for transient errors during streaming, (2) a structured SSE `error` event so the frontend can display user-friendly messages instead of a broken stream, and (3) frontend retry logic with backoff for recoverable failures.

## Classification

**Tier:** 2 (Feature-scoped)
**Rationale:** New resilience capability within the existing SSE streaming architecture. Affects 6-8 files across backend (controller/service layer, SSE error events) and frontend (SSE client, conversation store). Requires 1-2 new decisions (chat retry strategy, SSE error event format). Does not change system shape — the streaming pipeline, SSE protocol, and `RemembersConversations` persistence all remain intact.

**Modifiers:**
- [ ] `breaking` — Changes public API, DB schema, or external contracts
- [ ] `multi-repo` — Affects more than one repository
- [ ] `spike-required` — Feasibility uncertain, needs research first
- [ ] `deprecation` — Removes or sunsets existing capability
- [ ] `migration` — Requires data migration or rollout coordination

## Impact Analysis

### Components Affected

| Component | Impact | Files (est.) |
|---|---|---|
| `ConversationController` | Wrap `streamResponse()` in retry logic or catch `RateLimitedException` and emit SSE error event | 1 |
| `ConversationService` | Add retry-aware streaming (catch transient errors, retry with backoff, re-stream from last position) | 1 |
| SSE error event format | Define a structured `error` event type in the SSE protocol (type, message, retryable, retry_after) | 0 (convention) |
| `resources/js/lib/sse.ts` | Parse `error` event type, distinguish retryable vs terminal errors | 1 |
| `resources/js/stores/conversations.ts` | Add retry logic with exponential backoff for retryable SSE errors, show "retrying..." UI state | 1 |
| `MessageThread.vue` | Display retry indicator and rate limit user message | 1 |
| Tests (PHP) | ConversationController/Service rate limit retry tests | 1-2 |
| Tests (JS) | SSE error parsing, store retry logic, MessageThread retry indicator | 1-2 |

### Relevant Decisions

| Decision | Summary | Relationship |
|---|---|---|
| D32 | Auto-retry with exponential backoff (30s→2m→8m, 3 attempts) for transient errors | **Pattern to follow** — same strategy, adapted for synchronous streaming |
| D43 | AI explains errors conversationally with alternatives | **Constrains** — rate limit errors during streaming should degrade gracefully, not show raw error |
| D59 | Tool-use failure: AI silently handles, user-friendly message | **Constrains** — tool-level 429s (GitLab) already handled by tools returning error strings; this extension covers AI provider 429s |
| D14 | SSE for chat streaming, Reverb for dashboard | **Enables** — SSE protocol can carry structured error events alongside text/tool events |
| D108 | API outage: queue with 2h expiry + latest-wins | **Pattern reference** — outage handling for background jobs; chat streaming needs its own strategy |
| D118 | No per-project rate limits; runner capacity is throttle | **Context** — rate limits come from Anthropic, not from Vunnix's own throttling |

### Dependencies

- **Requires first:** Nothing — all extension points exist (SSE protocol, controller layer, frontend store)
- **Unblocks:** Better UX during high-traffic periods; foundation for future per-user rate limiting (D118 follow-up)

## Risk Factors

- **Laravel AI SDK encapsulation:** `StreamableAgentResponse` is a vendor class. Retry logic must wrap around it (in the controller/service), not inside it. If the SDK changes its exception hierarchy, the catch logic may need updating.
- **Partial stream state:** When retrying after a rate limit mid-stream, the AI SDK's `RemembersConversations` has already persisted partial messages. The retry must either continue from the last position or the frontend must handle duplicate content.
- **Backoff timing in synchronous context:** Unlike background jobs where backoff is queue-native, synchronous HTTP requests hold the connection open during backoff. Long waits (2m, 8m) are inappropriate for SSE — need shorter, chat-appropriate intervals (e.g., 1s→3s→10s).
- **Anthropic `Retry-After` header:** The AI SDK's `RateLimitedException` may or may not expose the `Retry-After` header from Anthropic's response. Need to verify if the header is accessible for server-informed backoff.

## Recommendation

**Proceed to planning-extensions.** This is a well-scoped Tier 2 extension with clear boundaries. The existing `RetryWithBackoff` job middleware provides a proven pattern to adapt. The main design decisions are: (1) appropriate backoff intervals for synchronous streaming, (2) SSE error event schema, and (3) whether retry happens server-side (hold connection, retry transparently) or client-side (emit retryable error, frontend reconnects).
