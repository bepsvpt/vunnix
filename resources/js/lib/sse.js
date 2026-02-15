/**
 * SSE stream client for POST endpoints.
 *
 * Native EventSource only supports GET — this uses fetch() Response objects
 * with ReadableStream to parse Server-Sent Events from any HTTP method.
 *
 * @param {Response} response - A fetch Response with a readable body stream
 * @param {Object} callbacks
 * @param {function} callbacks.onEvent - Called with parsed JSON for each data line
 * @param {function} [callbacks.onDone] - Called when [DONE] marker is received
 * @param {function} [callbacks.onError] - Called with Error on failure
 */
export async function streamSSE(response, { onEvent, onDone, onError }) {
    if (!response.ok) {
        const err = new Error(`SSE request failed with status ${response.status}`);
        await onError?.(err);
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });

            // SSE events are separated by double newlines
            const parts = buffer.split('\n\n');
            // Last part may be incomplete — keep it in the buffer
            buffer = parts.pop();

            for (const part of parts) {
                const lines = part.split('\n');
                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;

                    const payload = line.slice(6);

                    if (payload === '[DONE]') {
                        onDone?.();
                        continue;
                    }

                    try {
                        const parsed = JSON.parse(payload);
                        onEvent(parsed);
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
                if (!line.startsWith('data: ')) continue;

                const payload = line.slice(6);

                if (payload === '[DONE]') {
                    onDone?.();
                    continue;
                }

                try {
                    const parsed = JSON.parse(payload);
                    onEvent(parsed);
                } catch {
                    // Skip malformed JSON
                }
            }
        }
    } catch (err) {
        await onError?.(err);
    }
}
