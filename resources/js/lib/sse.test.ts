import { beforeEach, describe, expect, it, vi } from 'vitest';
import { streamSSE } from './sse';

/**
 * Helper: create a mock fetch Response with a ReadableStream body
 * that emits the given SSE lines.
 */
function mockSSEResponse(lines: string[]) {
    const text = `${lines.join('\n')}\n`;
    const encoder = new TextEncoder();
    const stream = new ReadableStream({
        start(controller) {
            controller.enqueue(encoder.encode(text));
            controller.close();
        },
    });
    return new Response(stream, {
        status: 200,
        headers: { 'Content-Type': 'text/event-stream' },
    });
}

/**
 * Helper: create a mock fetch Response that emits SSE data in multiple chunks,
 * simulating real-world streaming where events arrive incrementally.
 */
function mockChunkedSSEResponse(chunks: string[]) {
    const encoder = new TextEncoder();
    const stream = new ReadableStream({
        start(controller) {
            for (const chunk of chunks) {
                controller.enqueue(encoder.encode(chunk));
            }
            controller.close();
        },
    });
    return new Response(stream, {
        status: 200,
        headers: { 'Content-Type': 'text/event-stream' },
    });
}

describe('streamSSE', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('calls onEvent for each SSE data line with parsed JSON', async () => {
        const events: unknown[] = [];
        const response = mockSSEResponse([
            'data: {"type":"stream_start"}',
            '',
            'data: {"type":"text_start"}',
            '',
            'data: {"type":"text_delta","delta":"Hello"}',
            '',
            'data: {"type":"text_end"}',
            '',
            'data: {"type":"stream_end"}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event) });

        expect(events).toEqual([
            { type: 'stream_start' },
            { type: 'text_start' },
            { type: 'text_delta', delta: 'Hello' },
            { type: 'text_end' },
            { type: 'stream_end' },
        ]);
    });

    it('calls onDone when [DONE] marker is received', async () => {
        const onDone = vi.fn();
        const response = mockSSEResponse([
            'data: {"type":"stream_start"}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, { onEvent: () => {}, onDone });

        expect(onDone).toHaveBeenCalledOnce();
    });

    it('handles chunked delivery where events span multiple chunks', async () => {
        const events: unknown[] = [];
        const response = mockChunkedSSEResponse([
            'data: {"type":"stream_start"}\n\n',
            'data: {"type":"text_del',
            'ta","delta":"Hi"}\n\ndata: {"type":"text_end"}\n\n',
            'data: [DONE]\n\n',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event) });

        expect(events).toHaveLength(3);
        expect(events[0]).toEqual({ type: 'stream_start' });
        expect(events[1]).toEqual({ type: 'text_delta', delta: 'Hi' });
        expect(events[2]).toEqual({ type: 'text_end' });
    });

    it('calls onError when response is not ok', async () => {
        const onError = vi.fn();
        const response = new Response('Forbidden', { status: 403 });

        await streamSSE(response, { onEvent: () => {}, onError });

        expect(onError).toHaveBeenCalledOnce();
        expect(onError.mock.calls[0][0]).toBeInstanceOf(Error);
        expect(onError.mock.calls[0][0].message).toContain('403');
    });

    it('calls onError when stream read fails', async () => {
        const onError = vi.fn();
        const stream = new ReadableStream({
            start(controller) {
                controller.error(new Error('Network interrupted'));
            },
        });
        const response = new Response(stream, {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
        });

        await streamSSE(response, { onEvent: () => {}, onError });

        expect(onError).toHaveBeenCalledOnce();
        expect(onError.mock.calls[0][0].message).toContain('Network interrupted');
    });

    it('ignores non-data SSE lines (comments, empty, event names)', async () => {
        const events: unknown[] = [];
        const response = mockSSEResponse([
            ': this is a comment',
            'event: custom',
            'data: {"type":"stream_start"}',
            '',
            'id: 123',
            'retry: 5000',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event) });

        expect(events).toEqual([{ type: 'stream_start' }]);
    });

    it('ignores malformed JSON in data lines', async () => {
        const events: unknown[] = [];
        const onError = vi.fn();
        const response = mockSSEResponse([
            'data: {"type":"stream_start"}',
            '',
            'data: {bad json',
            '',
            'data: {"type":"text_delta","delta":"ok"}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
            onError,
        });

        // Should skip bad JSON and continue parsing
        expect(events).toHaveLength(2);
        expect(events[0]).toEqual({ type: 'stream_start' });
        expect(events[1]).toEqual({ type: 'text_delta', delta: 'ok' });
    });

    // ─── D187: SSE error event parsing ───────────────────────────

    it('calls onStreamError for error type events instead of onEvent', async () => {
        const events: unknown[] = [];
        const onStreamError = vi.fn();
        const response = mockSSEResponse([
            'data: {"type":"stream_start"}',
            '',
            'data: {"type":"text_delta","delta":"partial"}',
            '',
            'data: {"type":"error","error":{"message":"AI service busy","code":"rate_limited","retryable":true}}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
            onStreamError,
        });

        // Normal events should still be received
        expect(events).toHaveLength(2);
        expect(events[0]).toEqual({ type: 'stream_start' });
        expect(events[1]).toEqual({ type: 'text_delta', delta: 'partial' });

        // Error event should be routed to onStreamError
        expect(onStreamError).toHaveBeenCalledOnce();
        expect(onStreamError).toHaveBeenCalledWith({
            message: 'AI service busy',
            code: 'rate_limited',
            retryable: true,
        });
    });

    it('falls through to onEvent when onStreamError is not provided', async () => {
        const events: unknown[] = [];
        const response = mockSSEResponse([
            'data: {"type":"error","error":{"message":"test","code":"ai_error","retryable":false}}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
        });

        // Without onStreamError, error event should go to onEvent for backward compat
        expect(events).toHaveLength(1);
        expect(events[0]).toEqual({
            type: 'error',
            error: { message: 'test', code: 'ai_error', retryable: false },
        });
    });

    it('handles non-retryable error events', async () => {
        const onStreamError = vi.fn();
        const response = mockSSEResponse([
            'data: {"type":"error","error":{"message":"Generic AI error","code":"ai_error","retryable":false}}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, {
            onEvent: () => {},
            onStreamError,
        });

        expect(onStreamError).toHaveBeenCalledWith({
            message: 'Generic AI error',
            code: 'ai_error',
            retryable: false,
        });
    });

    it('handles error events in remaining buffer content', async () => {
        const onStreamError = vi.fn();
        // Single chunk without trailing double-newline — goes through buffer processing
        const encoder = new TextEncoder();
        const stream = new ReadableStream({
            start(controller) {
                controller.enqueue(encoder.encode(
                    'data: {"type":"error","error":{"message":"busy","code":"overloaded","retryable":true}}\n\ndata: [DONE]\n\n',
                ));
                controller.close();
            },
        });
        const response = new Response(stream, {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
        });

        await streamSSE(response, {
            onEvent: () => {},
            onStreamError,
        });

        expect(onStreamError).toHaveBeenCalledOnce();
        expect(onStreamError).toHaveBeenCalledWith({
            message: 'busy',
            code: 'overloaded',
            retryable: true,
        });
    });

    // ─── Empty stream / immediate close ─────────────────────────

    it('handles empty stream with immediate close', async () => {
        const events: unknown[] = [];
        const onDone = vi.fn();
        const stream = new ReadableStream({
            start(controller) {
                controller.close();
            },
        });
        const response = new Response(stream, {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
        });

        await streamSSE(response, { onEvent: event => events.push(event), onDone });

        expect(events).toHaveLength(0);
        expect(onDone).not.toHaveBeenCalled();
    });

    // ─── Buffer flushing edge cases ─────────────────────────────

    it('processes remaining buffer content after stream ends (no trailing double-newline)', async () => {
        const events: unknown[] = [];
        const onDone = vi.fn();
        // Data without trailing \n\n — stays in buffer until stream ends
        const response = mockChunkedSSEResponse([
            'data: {"type":"text_delta","delta":"partial"}\n\ndata: {"type":"final"}',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event), onDone });

        // First event goes through main loop, second through buffer flush
        expect(events).toHaveLength(2);
        expect(events[0]).toEqual({ type: 'text_delta', delta: 'partial' });
        expect(events[1]).toEqual({ type: 'final' });
    });

    it('handles [DONE] in remaining buffer', async () => {
        const onDone = vi.fn();
        // [DONE] without trailing \n\n so it stays in the buffer
        const response = mockChunkedSSEResponse([
            'data: {"type":"stream_start"}\n\ndata: [DONE]',
        ]);

        await streamSSE(response, { onEvent: () => {}, onDone });

        expect(onDone).toHaveBeenCalledOnce();
    });

    it('ignores malformed JSON in remaining buffer', async () => {
        const events: unknown[] = [];
        const onError = vi.fn();
        // Malformed JSON without trailing \n\n stays in buffer
        const response = mockChunkedSSEResponse([
            'data: {"type":"ok"}\n\ndata: {broken',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
            onError,
        });

        // First event parsed normally, malformed one in buffer is skipped
        expect(events).toHaveLength(1);
        expect(events[0]).toEqual({ type: 'ok' });
        // onError should NOT be called for malformed JSON (silently skipped)
        expect(onError).not.toHaveBeenCalled();
    });

    it('ignores non-data lines in remaining buffer', async () => {
        const events: unknown[] = [];
        // Comment and event lines in remaining buffer should be skipped
        const response = mockChunkedSSEResponse([
            'data: {"type":"first"}\n\n: comment\nevent: ping\nid: 42',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event) });

        expect(events).toHaveLength(1);
        expect(events[0]).toEqual({ type: 'first' });
    });

    it('skips empty/whitespace-only remaining buffer', async () => {
        const events: unknown[] = [];
        const onDone = vi.fn();
        // Trailing whitespace after final \n\n should not trigger buffer processing
        const response = mockChunkedSSEResponse([
            'data: {"type":"ok"}\n\ndata: [DONE]\n\n   \n  ',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event), onDone });

        expect(events).toHaveLength(1);
        expect(onDone).toHaveBeenCalledOnce();
    });

    // ─── Missing callback edge cases ────────────────────────────

    it('does not throw when onDone is not provided', async () => {
        const events: unknown[] = [];
        const response = mockSSEResponse([
            'data: {"type":"stream_start"}',
            '',
            'data: [DONE]',
            '',
        ]);

        // No onDone callback — should complete without error
        await expect(
            streamSSE(response, { onEvent: event => events.push(event) }),
        ).resolves.toBeUndefined();

        expect(events).toHaveLength(1);
    });

    it('does not throw when onError is not provided and response is not ok', async () => {
        const response = new Response('Server Error', { status: 500 });

        // No onError callback — should complete without throwing
        await expect(
            streamSSE(response, { onEvent: () => {} }),
        ).resolves.toBeUndefined();
    });

    it('does not throw when onError is not provided and stream read fails', async () => {
        const stream = new ReadableStream({
            start(controller) {
                controller.error(new Error('Connection lost'));
            },
        });
        const response = new Response(stream, {
            status: 200,
            headers: { 'Content-Type': 'text/event-stream' },
        });

        // No onError callback — should complete without throwing
        await expect(
            streamSSE(response, { onEvent: () => {} }),
        ).resolves.toBeUndefined();
    });

    // ─── Stream error in buffer without onStreamError ───────────

    it('silently drops error events in remaining buffer when onStreamError is absent', async () => {
        const events: unknown[] = [];
        // Error event without trailing \n\n stays in buffer; no onStreamError provided
        const response = mockChunkedSSEResponse([
            'data: {"type":"error","error":{"message":"overloaded","code":"rate_limited","retryable":true}}',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
        });

        // In the remaining buffer path, isStreamError is true. Without onStreamError,
        // onStreamError?.() is a no-op and the else branch (onEvent) is not reached.
        // This differs from the main loop where !onStreamError falls through to onEvent.
        expect(events).toHaveLength(0);
    });

    // ─── isStreamError type guard edge cases ────────────────────

    it('does not treat non-object data as stream errors', async () => {
        const events: unknown[] = [];
        const onStreamError = vi.fn();
        const response = mockSSEResponse([
            'data: "just a string"',
            '',
            'data: 42',
            '',
            'data: null',
            '',
            'data: true',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
            onStreamError,
        });

        expect(events).toEqual(['just a string', 42, null, true]);
        expect(onStreamError).not.toHaveBeenCalled();
    });

    it('does not treat objects without type="error" as stream errors', async () => {
        const events: unknown[] = [];
        const onStreamError = vi.fn();
        const response = mockSSEResponse([
            'data: {"type":"warning","error":{"message":"not a real error","code":"test","retryable":false}}',
            '',
            'data: {"error":{"message":"missing type field","code":"test","retryable":false}}',
            '',
            'data: {"type":"error"}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, {
            onEvent: event => events.push(event),
            onStreamError,
        });

        // type="warning" is not type="error", so goes to onEvent
        // missing type field goes to onEvent
        // type="error" without error object: isStreamError checks error is an object
        expect(events).toHaveLength(3);
        expect(onStreamError).not.toHaveBeenCalled();
    });

    // ─── Multiple events in a single chunk ──────────────────────

    it('processes multiple data lines within a single SSE event block', async () => {
        const events: unknown[] = [];
        // Two data: lines in the same event block (between double-newlines)
        // SSE spec says each data: line is separate, so both should be processed
        const response = mockSSEResponse([
            'data: {"type":"first"}',
            'data: {"type":"second"}',
            '',
            'data: [DONE]',
            '',
        ]);

        await streamSSE(response, { onEvent: event => events.push(event) });

        expect(events).toHaveLength(2);
        expect(events[0]).toEqual({ type: 'first' });
        expect(events[1]).toEqual({ type: 'second' });
    });
});
