/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue';

    const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
    export default component;
}

interface ImportMetaEnv {
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}

declare module 'markdown-it' {
    interface Token {
        attrSet: (name: string, value: string) => void;
        [key: string]: unknown;
    }

    interface Renderer {
        rules: Record<string, RenderRule | undefined>;
        renderToken: (tokens: Token[], idx: number, options: Options) => string;
    }

    interface Options {
        html?: boolean;
        linkify?: boolean;
        typographer?: boolean;
        [key: string]: unknown;
    }

    type RenderRule = (tokens: Token[], idx: number, options: Options, env: unknown, self: Renderer) => string;

    class MarkdownIt {
        constructor(options?: Options);
        renderer: Renderer;
        use(plugin: (md: MarkdownIt) => void): this;
        render(src: string, env?: unknown): string;
    }

    export default MarkdownIt;
    export type { Options, Renderer, RenderRule, Token };
}

interface Window {
    axios: import('axios').AxiosStatic;
    Pusher: typeof import('pusher-js').default;
}
