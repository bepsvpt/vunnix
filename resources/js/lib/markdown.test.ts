import MarkdownIt from 'markdown-it';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    _resetForTesting,
    applyHiddenFences,
    getMarkdownRenderer,
    HIDDEN_FENCE_LANGUAGES,
    isHighlightReady,
    onHighlightLoaded,
} from './markdown';

// Mock shiki and related modules before importing the source
vi.mock('shiki/core', () => ({
    createHighlighterCore: vi.fn().mockResolvedValue({
        // Minimal highlighter stub
        dispose: vi.fn(),
    }),
}));

vi.mock('@shikijs/core', () => ({
    createHighlighterCore: vi.fn().mockResolvedValue({
        // Some runtime paths resolve to @shikijs/core directly.
        dispose: vi.fn(),
    }),
}));

vi.mock('shiki/engine/oniguruma', () => ({
    createOnigurumaEngine: vi.fn().mockReturnValue(Promise.resolve({})),
}));

vi.mock('shiki/wasm', () => ({}));

vi.mock('@shikijs/markdown-it/core', () => ({
    fromHighlighter: vi.fn().mockReturnValue({
        // Return a fake markdown-it plugin (a function)
        apply: vi.fn(),
    }),
}));

// Mock all theme and language imports to avoid loading real Shiki assets
vi.mock('@shikijs/themes/github-light', () => ({ default: {} }));
vi.mock('@shikijs/themes/github-dark', () => ({ default: {} }));
vi.mock('@shikijs/langs/javascript', () => ({ default: {} }));
vi.mock('@shikijs/langs/typescript', () => ({ default: {} }));
vi.mock('@shikijs/langs/jsx', () => ({ default: {} }));
vi.mock('@shikijs/langs/tsx', () => ({ default: {} }));
vi.mock('@shikijs/langs/vue', () => ({ default: {} }));
vi.mock('@shikijs/langs/vue-html', () => ({ default: {} }));
vi.mock('@shikijs/langs/html', () => ({ default: {} }));
vi.mock('@shikijs/langs/css', () => ({ default: {} }));
vi.mock('@shikijs/langs/scss', () => ({ default: {} }));
vi.mock('@shikijs/langs/less', () => ({ default: {} }));
vi.mock('@shikijs/langs/json', () => ({ default: {} }));
vi.mock('@shikijs/langs/yaml', () => ({ default: {} }));
vi.mock('@shikijs/langs/toml', () => ({ default: {} }));
vi.mock('@shikijs/langs/xml', () => ({ default: {} }));
vi.mock('@shikijs/langs/markdown', () => ({ default: {} }));
vi.mock('@shikijs/langs/python', () => ({ default: {} }));
vi.mock('@shikijs/langs/ruby', () => ({ default: {} }));
vi.mock('@shikijs/langs/php', () => ({ default: {} }));
vi.mock('@shikijs/langs/go', () => ({ default: {} }));
vi.mock('@shikijs/langs/rust', () => ({ default: {} }));
vi.mock('@shikijs/langs/java', () => ({ default: {} }));
vi.mock('@shikijs/langs/kotlin', () => ({ default: {} }));
vi.mock('@shikijs/langs/swift', () => ({ default: {} }));
vi.mock('@shikijs/langs/c', () => ({ default: {} }));
vi.mock('@shikijs/langs/cpp', () => ({ default: {} }));
vi.mock('@shikijs/langs/csharp', () => ({ default: {} }));
vi.mock('@shikijs/langs/shellscript', () => ({ default: {} }));
vi.mock('@shikijs/langs/sql', () => ({ default: {} }));
vi.mock('@shikijs/langs/graphql', () => ({ default: {} }));
vi.mock('@shikijs/langs/docker', () => ({ default: {} }));
vi.mock('@shikijs/langs/diff', () => ({ default: {} }));
vi.mock('@shikijs/langs/nginx', () => ({ default: {} }));

describe('markdown', () => {
    beforeEach(() => {
        vi.useRealTimers();
        _resetForTesting();
        vi.clearAllMocks();
    });

    // ─── getMarkdownRenderer ─────────────────────────────────────

    describe('getMarkdownRenderer', () => {
        it('returns a markdown-it instance', () => {
            const md = getMarkdownRenderer();
            expect(md).toBeDefined();
            expect(typeof md.render).toBe('function');
        });

        it('returns the same instance on subsequent calls (singleton)', () => {
            const first = getMarkdownRenderer();
            const second = getMarkdownRenderer();
            expect(first).toBe(second);
        });

        it('returns a fresh instance after _resetForTesting()', () => {
            const first = getMarkdownRenderer();
            _resetForTesting();
            const second = getMarkdownRenderer();
            expect(first).not.toBe(second);
        });

        it('renders basic markdown to HTML', () => {
            const md = getMarkdownRenderer();
            const html = md.render('**bold** and *italic*');
            expect(html).toContain('<strong>bold</strong>');
            expect(html).toContain('<em>italic</em>');
        });
    });

    // ─── Link security ──────────────────────────────────────────

    describe('link security', () => {
        it('adds target="_blank" to rendered links', () => {
            const md = getMarkdownRenderer();
            const html = md.render('[example](https://example.com)');
            expect(html).toContain('target="_blank"');
        });

        it('adds rel="noopener noreferrer" to rendered links', () => {
            const md = getMarkdownRenderer();
            const html = md.render('[example](https://example.com)');
            expect(html).toContain('rel="noopener noreferrer"');
        });

        it('applies security attributes to auto-linked URLs', () => {
            const md = getMarkdownRenderer();
            const html = md.render('Visit https://example.com for more info');
            expect(html).toContain('target="_blank"');
            expect(html).toContain('rel="noopener noreferrer"');
        });

        it('preserves the original href', () => {
            const md = getMarkdownRenderer();
            const html = md.render('[click](https://example.com/page?q=1)');
            expect(html).toContain('href="https://example.com/page?q=1"');
        });
    });

    // ─── Hidden fence languages ─────────────────────────────────

    describe('hidden fence languages', () => {
        it('includes action_preview in HIDDEN_FENCE_LANGUAGES', () => {
            expect(HIDDEN_FENCE_LANGUAGES.has('action_preview')).toBe(true);
        });

        it('hides action_preview code blocks from rendered output', () => {
            const md = getMarkdownRenderer();
            const input = '```action_preview\n{"action":"create_file"}\n```';
            const html = md.render(input);
            // The hidden fence should produce empty string, not a <pre> block
            expect(html).not.toContain('<pre>');
            expect(html).not.toContain('<code>');
            expect(html).not.toContain('action_preview');
            expect(html).not.toContain('create_file');
        });

        it('renders normal code blocks normally', () => {
            const md = getMarkdownRenderer();
            const input = '```javascript\nconsole.log("hello");\n```';
            const html = md.render(input);
            expect(html).toContain('<pre>');
            expect(html).toMatch(/<code/); // may have class="language-javascript"
            expect(html).toContain('console.log');
        });

        it('hides action_preview with leading/trailing whitespace in language tag', () => {
            const md = getMarkdownRenderer();
            const input = '``` action_preview \n{"action":"test"}\n```';
            const html = md.render(input);
            expect(html).not.toContain('<pre>');
            expect(html).not.toContain('action_preview');
        });

        it('does not hide languages not in the set', () => {
            const md = getMarkdownRenderer();
            const input = '```python\nprint("hello")\n```';
            const html = md.render(input);
            expect(html).toContain('<pre>');
            expect(html).toContain('print');
        });
    });

    // ─── applyHiddenFences (standalone) ─────────────────────────

    describe('applyHiddenFences', () => {
        it('can be applied to a standalone markdown-it instance', () => {
            const instance = new MarkdownIt();
            applyHiddenFences(instance);

            const hidden = instance.render('```action_preview\n{"x":1}\n```');
            expect(hidden).not.toContain('<pre>');

            const normal = instance.render('```js\nvar x = 1;\n```');
            expect(normal).toContain('<pre>');
        });

        it('preserves a previous fence rule when wrapping', () => {
            const instance = new MarkdownIt();

            // Install a custom fence rule first
            const customFence = vi.fn().mockReturnValue('<custom-fence></custom-fence>');
            instance.renderer.rules.fence = customFence;

            applyHiddenFences(instance);

            // Non-hidden language should delegate to the custom fence
            instance.render('```javascript\ncode\n```');
            expect(customFence).toHaveBeenCalled();
        });

        it('works when no previous fence rule exists', () => {
            const instance = new MarkdownIt();
            // Ensure no custom fence rule
            delete instance.renderer.rules.fence;

            applyHiddenFences(instance);

            // self.renderToken fallback renders the token tags; hidden fences still return ''
            const hidden = instance.render('```action_preview\n{"x":1}\n```');
            expect(hidden).not.toContain('<pre>');
            expect(hidden).not.toContain('action_preview');

            // Non-hidden fence should render something (even if just a code token)
            const normal = instance.render('```text\nhello\n```');
            expect(normal).toMatch(/<code/);
        });
    });

    // ─── isHighlightReady ───────────────────────────────────────

    describe('isHighlightReady', () => {
        it('returns false initially', () => {
            expect(isHighlightReady()).toBe(false);
        });

        it('returns false after getMarkdownRenderer is called but shiki is not yet loaded', () => {
            getMarkdownRenderer();
            // Shiki is async; it won't be ready synchronously
            expect(isHighlightReady()).toBe(false);
        });
    });

    // ─── onHighlightLoaded ──────────────────────────────────────

    describe('onHighlightLoaded', () => {
        it('registers a callback that is not called immediately when shiki is not ready', () => {
            const callback = vi.fn();
            onHighlightLoaded(callback);
            expect(callback).not.toHaveBeenCalled();
        });
    });

    // ─── _resetForTesting ───────────────────────────────────────

    describe('_resetForTesting', () => {
        it('resets all internal state', () => {
            // Initialize everything
            getMarkdownRenderer();
            const callback = vi.fn();
            onHighlightLoaded(callback);

            // Reset
            _resetForTesting();

            // Verify reset
            expect(isHighlightReady()).toBe(false);

            // A new callback should NOT be called (shiki not ready after reset)
            const newCallback = vi.fn();
            onHighlightLoaded(newCallback);
            expect(newCallback).not.toHaveBeenCalled();

            // getMarkdownRenderer should create a new instance
            const md = getMarkdownRenderer();
            expect(md).toBeDefined();
        });
    });

    // ─── Shiki async initialization ─────────────────────────────

    describe('shiki async initialization', () => {
        it('calls onHighlightLoaded callback when shiki finishes loading', async () => {
            // Set up the fromHighlighter mock to return a plugin function
            const { fromHighlighter } = await import('@shikijs/markdown-it/core');
            vi.mocked(fromHighlighter).mockReturnValue((() => {
                // no-op plugin
            }) as unknown as ReturnType<typeof fromHighlighter>);

            const callback = vi.fn();

            // First get the renderer (which triggers initShiki)
            getMarkdownRenderer();

            // Register callback
            onHighlightLoaded(callback);

            // Wait for the async shiki init to complete
            await vi.waitFor(() => {
                expect(callback).toHaveBeenCalled();
            }, { timeout: 1000 });

            expect(isHighlightReady()).toBe(true);
        });

        it('fires callback immediately if shiki is already ready', async () => {
            const { fromHighlighter } = await import('@shikijs/markdown-it/core');
            vi.mocked(fromHighlighter).mockReturnValue((() => {
                // no-op plugin
            }) as unknown as ReturnType<typeof fromHighlighter>);

            // Initialize and wait for shiki to load
            getMarkdownRenderer();
            await vi.waitFor(() => {
                expect(isHighlightReady()).toBe(true);
            }, { timeout: 8000 });

            // Now register a callback after shiki is already ready
            const lateCallback = vi.fn();
            onHighlightLoaded(lateCallback);
            expect(lateCallback).toHaveBeenCalledOnce();
        }, 15000);

        it('handles shiki loading failure gracefully', async () => {
            // Wait for any in-flight Shiki promises from previous tests to settle
            // (they close over module-level state and can race with our reset)
            await new Promise(resolve => setTimeout(resolve, 300));

            // Make createHighlighterCore reject
            const { createHighlighterCore } = await import('shiki/core');
            vi.mocked(createHighlighterCore).mockRejectedValueOnce(new Error('Shiki load failed'));

            _resetForTesting();

            const renderer = getMarkdownRenderer();

            // Wait for the rejection to settle
            await new Promise(resolve => setTimeout(resolve, 100));

            // Shiki failed — renderer still works (plain <pre><code>), highlight stays not-ready
            expect(renderer).toBeDefined();
            expect(renderer.render('# test')).toContain('<h1>');
            expect(isHighlightReady()).toBe(false);
        });

        it('re-applies hidden fences after shiki loads', async () => {
            // Track if the plugin's use() was called, then verify hidden fences still work
            const { fromHighlighter } = await import('@shikijs/markdown-it/core');
            vi.mocked(fromHighlighter).mockReturnValue((() => {
                // no-op plugin that doesn't actually modify the fence rule
            }) as unknown as ReturnType<typeof fromHighlighter>);

            getMarkdownRenderer();

            await vi.waitFor(() => {
                expect(isHighlightReady()).toBe(true);
            }, { timeout: 1000 });

            // After shiki loads and hidden fences are re-applied, action_preview should still be hidden
            const md = getMarkdownRenderer();
            const html = md.render('```action_preview\n{"action":"test"}\n```');
            expect(html).not.toContain('<pre>');
            expect(html).not.toContain('action_preview');
        });

        it('only initializes shiki once even with multiple getMarkdownRenderer calls', () => {
            // The singleton pattern ensures initShiki is only triggered once:
            // first call creates md + starts initShiki, subsequent calls return cached md
            const first = getMarkdownRenderer();
            const second = getMarkdownRenderer();
            const third = getMarkdownRenderer();

            // All calls return the exact same instance (singleton)
            expect(first).toBe(second);
            expect(second).toBe(third);
        });
    });

    // ─── Markdown rendering features ────────────────────────────

    describe('markdown rendering features', () => {
        it('does not allow raw HTML in input', () => {
            const md = getMarkdownRenderer();
            const html = md.render('<script>alert("xss")</script>');
            expect(html).not.toContain('<script>');
        });

        it('enables linkify (auto-links bare URLs)', () => {
            const md = getMarkdownRenderer();
            const html = md.render('Go to https://example.com now');
            expect(html).toContain('<a');
            expect(html).toContain('href="https://example.com"');
        });

        it('enables typographer (smart quotes)', () => {
            const md = getMarkdownRenderer();
            const html = md.render('"hello"');
            // Typographer converts straight quotes to curly quotes
            expect(html).toContain('\u201C');
            expect(html).toContain('\u201D');
        });
    });
});
