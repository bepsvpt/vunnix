<script setup>
import { ref, computed, onMounted } from 'vue';
import { getMarkdownRenderer, isHighlightReady, onHighlightLoaded } from '@/lib/markdown';

const props = defineProps({
    content: { type: String, default: '' },
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
