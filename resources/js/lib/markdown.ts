import type { RenderRule } from 'markdown-it';
import MarkdownIt from 'markdown-it';

let md: MarkdownIt | null = null;
let shikiReady = false;
let shikiPromise: Promise<void> | null = null;
let onHighlightReady: (() => void) | null = null;

/**
 * Creates the base markdown-it instance with link security.
 */
function createBaseInstance(): MarkdownIt {
    const instance = new MarkdownIt({
        html: false,
        linkify: true,
        typographer: true,
    });

    // Open links in new tab with security attributes
    const defaultRender: RenderRule = instance.renderer.rules.link_open
        || function (tokens, idx, options, _env, self) {
            return self.renderToken(tokens, idx, options);
        };

    instance.renderer.rules.link_open = function (tokens, idx, options, env, self) {
        tokens[idx].attrSet('target', '_blank');
        tokens[idx].attrSet('rel', 'noopener noreferrer');
        return defaultRender(tokens, idx, options, env, self);
    };

    return instance;
}

/**
 * Lazily initializes Shiki and attaches it to the markdown-it instance.
 * Returns a promise that resolves when highlighting is ready.
 */
function initShiki(): Promise<void> {
    if (shikiPromise)
        return shikiPromise;

    shikiPromise = import('@shikijs/markdown-it').then(async ({ default: markdownItShiki }) => {
        const plugin = await markdownItShiki({
            themes: {
                light: 'github-light',
                dark: 'github-dark',
            },
        });
        md!.use(plugin);
        shikiReady = true;
        if (onHighlightReady)
            onHighlightReady();
    }).catch(() => {
        // Shiki failed to load — code blocks stay as plain <pre><code>
    });

    return shikiPromise;
}

/**
 * Returns the markdown-it instance (creates if needed).
 * Kicks off async Shiki initialization on first call.
 */
export function getMarkdownRenderer(): MarkdownIt {
    if (!md) {
        md = createBaseInstance();
        initShiki();
    }
    return md;
}

/**
 * Whether Shiki syntax highlighting is ready.
 */
export function isHighlightReady(): boolean {
    return shikiReady;
}

/**
 * Register a callback for when Shiki finishes loading.
 */
export function onHighlightLoaded(callback: () => void): void {
    if (shikiReady) {
        callback();
        return;
    }
    onHighlightReady = callback;
}

/**
 * Reset for testing — clears the singleton.
 */
export function _resetForTesting(): void {
    md = null;
    shikiReady = false;
    shikiPromise = null;
    onHighlightReady = null;
}
