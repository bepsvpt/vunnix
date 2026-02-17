<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { getMarkdownRenderer, isHighlightReady, onHighlightLoaded } from '@/lib/markdown';

interface Props {
    content?: string;
}

const props = withDefaults(defineProps<Props>(), {
    content: '',
});

const highlightVersion = ref(0);

function handleHighlightReady() {
    highlightVersion.value++;
}

onMounted(() => {
    if (!isHighlightReady()) {
        onHighlightLoaded(handleHighlightReady);
    }
});

const rendered = computed(() => {
    // Access highlightVersion to trigger re-compute when Shiki loads
    void highlightVersion.value;
    const md = getMarkdownRenderer();
    return md.render(props.content || '');
});
</script>

<template>
    <div class="markdown-content" v-html="rendered" />
</template>
