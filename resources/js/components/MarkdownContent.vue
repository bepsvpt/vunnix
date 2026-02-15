<script setup>
import { computed } from 'vue';
import MarkdownIt from 'markdown-it';

const props = defineProps({
    content: { type: String, default: '' },
});

const md = new MarkdownIt({
    html: false,
    linkify: true,
    typographer: true,
});

// Open links in new tab with security attributes
const defaultRender = md.renderer.rules.link_open ||
    function (tokens, idx, options, env, self) {
        return self.renderToken(tokens, idx, options);
    };

md.renderer.rules.link_open = function (tokens, idx, options, env, self) {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return defaultRender(tokens, idx, options, env, self);
};

const rendered = computed(() => md.render(props.content || ''));
</script>

<template>
  <div class="markdown-content" v-html="rendered" />
</template>
