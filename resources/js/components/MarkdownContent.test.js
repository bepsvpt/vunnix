import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MarkdownIt from 'markdown-it';
import MarkdownContent from './MarkdownContent.vue';

// Mock the markdown module to avoid async Shiki loading in tests
const testMd = new MarkdownIt({ html: false, linkify: true, typographer: true });

// Add link security rules (same as production)
const defaultRender = testMd.renderer.rules.link_open ||
    function (tokens, idx, options, env, self) {
        return self.renderToken(tokens, idx, options);
    };
testMd.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return defaultRender(tokens, idx, options, env, self);
};

vi.mock('@/lib/markdown', () => ({
    getMarkdownRenderer: () => testMd,
    isHighlightReady: () => false,
    onHighlightLoaded: vi.fn(),
}));

describe('MarkdownContent', () => {
    function mountContent(content) {
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
});
