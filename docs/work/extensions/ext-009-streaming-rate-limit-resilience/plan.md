## Extension 009: Streaming Rate Limit Resilience

**Status: ✅ Implemented** — `5580280`

### Trigger

Production `RateLimitedException` crashed the SSE chat stream mid-conversation after 3 successful tool calls. The Anthropic API returned 429 during the 4th AI SDK call. No graceful degradation — the stream died with an unhandled exception propagating through `StreamableAgentResponse` → FrankenPHP → broken HTTP response.

### Scope

What it does:
- Catches AI provider exceptions (`RateLimitedException`, `ProviderOverloadedException`) during SSE stream iteration and emits structured error events instead of crashing
- Frontend parses error events and displays user-friendly messages with recovery guidance
- Existing REST API refetch mechanism (already in `onError`) provides data recovery since `RemembersConversations` persists server-side

What it does NOT do:
- No server-side transparent retry (conversation state is persisted by AI SDK during `stream()` — re-calling `stream()` would duplicate the user message; proper retry requires SDK-level support)
- No per-user or per-conversation rate limiting on the Vunnix side (D118 — runner capacity is the throttle)
- No proactive rate limit probing or Anthropic quota monitoring
- No changes to background job retry logic (D32 `RetryWithBackoff` already handles this correctly)

### Architecture Fit

- **Components affected:** `ConversationController` (return type change), new `ResilientStreamResponse` wrapper, `sse.ts` (error event parsing), `conversations.ts` store (error recovery), `MessageThread.vue` (error banner)
- **Extension points used:** SSE event protocol (adding `error` event type alongside existing `text_delta`, `tool_call`, `tool_result`), Laravel `StreamedResponse` (wrapping vendor `StreamableAgentResponse`)
- **New tables/endpoints/services:** None. One new response class: `app/Http/Responses/ResilientStreamResponse.php`

### New Decisions

- **D187:** Structured SSE error event for AI provider failures during streaming — When an AI provider exception occurs mid-stream (after HTTP headers are already sent), emit `data: {"type":"error","error":{"message":"...","code":"rate_limited|overloaded|ai_error","retryable":true}}` followed by `data: [DONE]`. This extends the existing SSE protocol (D14, D37) without breaking backward compatibility — frontends that don't handle `error` events simply ignore them via the existing `onEvent` handler. Rationale: HTTP status codes can't be changed mid-stream; SSE error events are the only channel for structured error reporting after headers are flushed.
- **D188:** Client-side recovery for streaming errors — On `error` event with `retryable: true`, the frontend shows a transient error banner ("AI service is temporarily busy") and automatically refetches messages from the REST API (existing `fetchMessages()` path). The user can re-send their message manually. No automatic re-send to avoid duplicate messages when conversation state is ambiguous. Rationale: The AI SDK's `RemembersConversations` may or may not have persisted partial state — automatic re-send risks duplicates. Manual retry is safer and gives the user control.

### Dependencies

- **Requires:** Nothing — all extension points exist
- **Unblocks:** Better UX during Anthropic rate limit periods; foundation for future streaming resilience (server-side retry, automatic backoff)

### Tasks

#### T180: Create ResilientStreamResponse wrapper class
**File(s):** `app/Http/Responses/ResilientStreamResponse.php`
**Action:** Create a response wrapper that takes a `StreamableAgentResponse`, iterates its events inside a `StreamedResponse` closure, catches `RateLimitedException` and `ProviderOverloadedException`, and emits structured SSE error events. The wrapper reproduces the SDK's SSE formatting (`data: {json}\n\n` + `data: [DONE]\n\n`) while adding exception handling around the iteration loop.

Key implementation details:
- Iterate `StreamableAgentResponse` (it implements `IteratorAggregate`) in a try/catch
- On `RateLimitedException`: emit `{"type":"error","error":{"message":"The AI service is temporarily busy. Please try again in a moment.","code":"rate_limited","retryable":true}}`
- On `ProviderOverloadedException`: emit `{"type":"error","error":{"message":"The AI service is currently overloaded. Please try again shortly.","code":"overloaded","retryable":true}}`
- On other `AiException`: emit `{"type":"error","error":{"message":"An error occurred while generating the response.","code":"ai_error","retryable":false}}`
- Always emit `data: [DONE]\n\n` after the error event so the frontend's stream parser terminates cleanly
- Set response headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`
- Log the exception with full context (conversation ID, user ID, exception class, message)

**Verification:** PHPStan passes. Class can be instantiated with a mock `StreamableAgentResponse`.

#### T181: Update ConversationController to use ResilientStreamResponse
**File(s):** `app/Http/Controllers/Api/ConversationController.php`
**Action:** Change the `stream()` method to wrap the service result:
- Change return type from `StreamableAgentResponse` to `StreamedResponse`
- Wrap: `return ResilientStreamResponse::from($this->conversationService->streamResponse(...))`
- Import `ResilientStreamResponse` and `StreamedResponse`
- `ConversationService::streamResponse()` remains unchanged

**Verification:** `php artisan test --filter=ConversationControllerTest` passes. PHPStan passes.

#### T182: Add SSE error event parsing to frontend SSE library
**File(s):** `resources/js/lib/sse.ts`
**Action:** Extend `SSECallbacks` interface with an `onStreamError` callback:
```typescript
onStreamError?: (error: { message: string; code: string; retryable: boolean }) => Promise<void> | void;
```
In the event parsing loop, after `JSON.parse`, check if `parsed.type === 'error'` and call `onStreamError?.(parsed.error)` instead of the generic `onEvent`. This separates error events from data events, letting consumers handle them differently.

**Verification:** `npm test -- --testPathPattern=sse.test` passes with new test cases.

#### T183: Add error recovery logic to conversations store
**File(s):** `resources/js/stores/conversations.ts`
**Action:** In `streamMessage()`, pass the new `onStreamError` callback to `streamSSE()`:
- On retryable errors (`error.retryable === true`): set `messagesError.value` to user-friendly message (e.g., "The AI service is temporarily busy. Please resend your message."), then call `fetchMessages()` to recover persisted state
- On non-retryable errors: set `messagesError.value` to error message, call `fetchMessages()`
- In both cases, reset `streaming`, `streamingContent`, and `activeToolCalls` state
- Add a new reactive ref `streamRetryable` (boolean) to signal to the UI that the error is transient

**Verification:** `npm test -- --testPathPattern=conversations.test` passes with new test cases.

#### T184: Update MessageThread.vue error display for retryable errors
**File(s):** `resources/js/components/MessageThread.vue`
**Action:** Enhance the error display (currently lines 86-93) to distinguish retryable vs terminal errors:
- When `store.streamRetryable` is true: show amber/yellow banner with retry icon and message like "AI service is temporarily busy. You can resend your message."
- When `store.streamRetryable` is false (existing behavior): show red error text
- The banner should be dismissible (clicking clears `messagesError`)

**Verification:** `npm test -- --testPathPattern=MessageThread.test` passes. Visual check: error banner renders correctly.

#### T185: PHP tests for rate limit handling in streaming pipeline
**File(s):** `tests/Feature/Http/Controllers/Api/ConversationControllerStreamTest.php` (new or existing)
**Action:** Add test cases:
1. **Rate limit mid-stream**: Mock `StreamableAgentResponse` to throw `RateLimitedException` during iteration → assert response contains SSE error event with `code: "rate_limited"` and `retryable: true`, followed by `[DONE]`
2. **Provider overloaded**: Same as above with `ProviderOverloadedException` → `code: "overloaded"`, `retryable: true`
3. **Generic AI error**: Mock throws `AiException` → `code: "ai_error"`, `retryable: false`
4. **Happy path unchanged**: Normal stream still works (regression test)

**Verification:** `php artisan test --filter=ConversationControllerStreamTest` — all pass.

#### T186: JS tests for SSE error parsing and store retry logic
**File(s):** `resources/js/lib/sse.test.ts`, `resources/js/stores/conversations.test.ts`
**Action:** Add test cases:
- `sse.test.ts`: SSE stream containing `{"type":"error","error":{...}}` event calls `onStreamError` (not `onEvent`), followed by `[DONE]` calling `onDone`
- `sse.test.ts`: Error event without `onStreamError` callback falls through to `onEvent` (backward compat)
- `conversations.test.ts`: Stream with retryable error sets `streamRetryable = true`, shows error message, refetches messages
- `conversations.test.ts`: Stream with non-retryable error sets `streamRetryable = false`

**Verification:** `npm test` — all pass.

#### T187: Run full verification
**File(s):** N/A
**Action:** Run `composer analyse && composer test && npm test && npm run typecheck && npm run lint` to verify no regressions.

**Verification:** All commands pass with zero errors.

#### T188: Update decisions index
**File(s):** `docs/reference/spec/decisions-index.md`
**Action:** Append D187 and D188 entries to the decisions index table.

**Verification:** File contains D187 and D188 entries with correct format.

### Verification

- [ ] `RateLimitedException` during SSE streaming emits a structured error event (not an HTTP error)
- [ ] `ProviderOverloadedException` during SSE streaming emits a structured error event
- [ ] Frontend parses error events and displays user-friendly messages
- [ ] Retryable errors show amber/yellow banner, non-retryable show red
- [ ] `data: [DONE]` is always emitted after error events (stream terminates cleanly)
- [ ] Normal streaming (no errors) works identically to before (regression)
- [ ] `composer analyse` passes (PHPStan level 8)
- [ ] `php artisan test --parallel` passes
- [ ] `npm test` passes
- [ ] `npm run typecheck` passes
- [ ] `npm run lint` passes
