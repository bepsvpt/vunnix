<script setup lang="ts">
import { computed } from 'vue';

interface ToolCall {
    id: string;
    tool: string;
    input: Record<string, unknown>;
}

interface Props {
    toolCalls: ToolCall[];
}

const props = defineProps<Props>();

interface ToolDisplayInfo {
    emoji: string;
    verb: string;
}

const TOOL_DISPLAY: Record<string, ToolDisplayInfo> = {
    BrowseRepoTree: { emoji: '🔍', verb: 'Browsing' },
    ReadFile: { emoji: '📄', verb: 'Reading' },
    SearchCode: { emoji: '🔎', verb: 'Searching for' },
    ListIssues: { emoji: '📋', verb: 'Listing issues' },
    ReadIssue: { emoji: '📋', verb: 'Reading issue' },
    ListMergeRequests: { emoji: '🔀', verb: 'Listing merge requests' },
    ReadMergeRequest: { emoji: '🔀', verb: 'Reading merge request' },
    ReadMRDiff: { emoji: '🔀', verb: 'Reading MR diff' },
    DispatchAction: { emoji: '🚀', verb: 'Dispatching action' },
};

const indicators = computed(() =>
    props.toolCalls.map((tc) => {
        const display = TOOL_DISPLAY[tc.tool] || { emoji: '⚙️', verb: tc.tool };
        const context = formatContext(tc.tool, tc.input);
        return {
            id: tc.id,
            emoji: display.emoji,
            text: context ? `${display.verb} ${context}` : `${display.verb}…`,
        };
    }),
);

function formatContext(tool: string, input: Record<string, unknown> | undefined): string {
    if (!input)
        return '';
    switch (tool) {
        case 'BrowseRepoTree':
            return input.path ? `${input.path}…` : '';
        case 'ReadFile':
            return input.file_path ? `${input.file_path.split('/').pop()}…` : '';
        case 'SearchCode':
            return input.query ? `"${input.query}" across repo…` : '';
        case 'ListIssues':
            return input.state ? `(${input.state})…` : '';
        case 'ReadIssue':
            return input.issue_iid ? `#${input.issue_iid}…` : '';
        case 'ReadMergeRequest':
            return input.merge_request_iid ? `!${input.merge_request_iid}…` : '';
        case 'ReadMRDiff':
            return input.merge_request_iid ? `!${input.merge_request_iid}…` : '';
        default:
            return '';
    }
}
</script>

<template>
    <div
        v-if="indicators.length > 0"
        data-testid="tool-use-indicators"
        class="flex w-full justify-start"
    >
        <div class="max-w-[80%] space-y-1">
            <div
                v-for="indicator in indicators"
                :key="indicator.id"
                class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50 animate-pulse"
            >
                <span class="shrink-0">{{ indicator.emoji }}</span>
                <span class="truncate">{{ indicator.text }}</span>
            </div>
        </div>
    </div>
</template>
