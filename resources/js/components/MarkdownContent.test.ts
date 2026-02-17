import { mount } from '@vue/test-utils';
import MarkdownIt from 'markdown-it';
import { describe, expect, it, vi } from 'vitest';
import { applyHiddenFences } from '@/lib/markdown';
import MarkdownContent from './MarkdownContent.vue';

// Mock the markdown module to avoid async Shiki loading in tests
const testMd = new MarkdownIt({ html: false, linkify: true, typographer: true });

// Add link security rules (same as production)
const defaultRender = testMd.renderer.rules.link_open
    || function (tokens, idx, options, env, self) {
        return self.renderToken(tokens, idx, options);
    };
testMd.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return defaultRender(tokens, idx, options, env, self);
};

// Apply hidden fence rules (same as production)
applyHiddenFences(testMd);

vi.mock('@/lib/markdown', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/markdown')>();
    return {
        ...actual,
        getMarkdownRenderer: () => testMd,
        isHighlightReady: (): boolean => false,
        onHighlightLoaded: vi.fn(),
    };
});

describe('markdownContent', () => {
    function mountContent(content: string) {
        return mount(MarkdownContent, {
            props: { content },
        });
    }

    it('renders heading as <h1>', () => {
        const wrapper = mountContent('# Hello World');
        expect(wrapper.find('h1').text()).toBe('Hello World');
    });

    it('renders bold text', () => {
        const wrapper = mountContent('**bold text**');
        expect(wrapper.find('strong').text()).toBe('bold text');
    });

    it('renders italic text', () => {
        const wrapper = mountContent('*italic text*');
        expect(wrapper.find('em').text()).toBe('italic text');
    });

    it('renders links with target _blank', () => {
        const wrapper = mountContent('[click](https://example.com)');
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('https://example.com');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
    });

    it('renders code blocks as pre > code', () => {
        const wrapper = mountContent('```js\nconst x = 1;\n```');
        expect(wrapper.find('pre').exists()).toBe(true);
        expect(wrapper.find('code').exists()).toBe(true);
    });

    it('renders inline code', () => {
        const wrapper = mountContent('Use `npm install` to install');
        expect(wrapper.find('code').text()).toBe('npm install');
    });

    it('renders unordered lists', () => {
        const wrapper = mountContent('- item one\n- item two');
        expect(wrapper.findAll('li')).toHaveLength(2);
    });

    it('renders tables', () => {
        const md = '| A | B |\n|---|---|\n| 1 | 2 |';
        const wrapper = mountContent(md);
        expect(wrapper.find('table').exists()).toBe(true);
        expect(wrapper.findAll('td')).toHaveLength(2);
    });

    it('renders empty string without errors', () => {
        const wrapper = mountContent('');
        expect(wrapper.find('.markdown-content').exists()).toBe(true);
    });

    it('applies markdown-content class to wrapper', () => {
        const wrapper = mountContent('hello');
        expect(wrapper.find('.markdown-content').exists()).toBe(true);
    });

    it('hides action_preview fenced blocks from rendered output', () => {
        const content = 'Before.\n\n```action_preview\n{"action_type":"create_issue","project_id":42}\n```\n\nAfter.';
        const wrapper = mountContent(content);
        const html = wrapper.html();
        expect(html).toContain('Before.');
        expect(html).toContain('After.');
        expect(html).not.toContain('action_preview');
        expect(html).not.toContain('create_issue');
        expect(html).not.toContain('project_id');
    });

    it('hides unclosed action_preview fenced blocks (streaming partial)', () => {
        const content = 'Before.\n\n```action_preview\n{"action_type":"deep_analysis"';
        const wrapper = mountContent(content);
        const html = wrapper.html();
        expect(html).toContain('Before.');
        expect(html).not.toContain('deep_analysis');
    });

    it('renders normal code blocks alongside hidden action_preview', () => {
        const content = '```js\nconst x = 1;\n```\n\n```action_preview\n{"hidden":true}\n```\n\nText.';
        const wrapper = mountContent(content);
        const html = wrapper.html();
        expect(html).toContain('const x = 1;');
        expect(html).toContain('Text.');
        expect(html).not.toContain('"hidden"');
    });
});
