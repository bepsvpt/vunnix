export interface StreamError {
    message: string;
    code: string;
    retryable: boolean;
}

interface SSECallbacks {
    onEvent: (data: unknown) => void;
    onDone?: () => void;
    onError?: (error: unknown) => Promise<void> | void;
    onStreamError?: (error: StreamError) => Promise<void> | void;
}

/**
 * SSE stream client for POST endpoints.
 *
 * Native EventSource only supports GET — this uses fetch() Response objects
 * with ReadableStream to parse Server-Sent Events from any HTTP method.
 */
export async function streamSSE(response: Response, { onEvent, onDone, onError, onStreamError }: SSECallbacks): Promise<void> {
    if (!response.ok) {
        const err = new Error(`SSE request failed with status ${response.status}`);
        await onError?.(err);
        return;
    }

    const reader = response.body!.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done)
                break;

            buffer += decoder.decode(value, { stream: true });

            // SSE events are separated by double newlines
            const parts = buffer.split('\n\n');
            // Last part may be incomplete — keep it in the buffer
            buffer = parts.pop()!;

            for (const part of parts) {
                const lines = part.split('\n');
                for (const line of lines) {
                    if (!line.startsWith('data: '))
                        continue;

                    const payload = line.slice(6);

                    if (payload === '[DONE]') {
                        onDone?.();
                        continue;
                    }

                    try {
                        const parsed: unknown = JSON.parse(payload);
                        if (isStreamError(parsed) && onStreamError) {
                            await onStreamError(parsed.error);
                        } else {
                            onEvent(parsed);
                        }
                    } catch {
                        // Skip malformed JSON
                    }
                }
            }
        }

        // Process any remaining buffer content
        if (buffer.trim()) {
            const lines = buffer.split('\n');
            for (const line of lines) {
                if (!line.startsWith('data: '))
                    continue;

                const payload = line.slice(6);

                if (payload === '[DONE]') {
                    onDone?.();
                    continue;
                }

                try {
                    const parsed: unknown = JSON.parse(payload);
                    if (isStreamError(parsed)) {
                        await onStreamError?.(parsed.error);
                    } else {
                        onEvent(parsed);
                    }
                } catch {
                    // Skip malformed JSON
                }
            }
        }
    } catch (err) {
        await onError?.(err);
    }
}

/**
 * Type guard for structured SSE error events emitted by ResilientStreamResponse (D187).
 */
function isStreamError(data: unknown): data is { type: 'error'; error: StreamError } {
    return (
        typeof data === 'object'
        && data !== null
        && 'type' in data
        && (data as Record<string, unknown>).type === 'error'
        && 'error' in data
        && typeof (data as Record<string, unknown>).error === 'object'
    );
}
