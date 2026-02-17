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
});
