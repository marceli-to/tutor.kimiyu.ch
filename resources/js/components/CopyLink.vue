<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    url: string;
    label?: string;
}>();

const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Without clipboard access: select the link so it can be copied
        window.prompt('Link kopieren:', props.url);
    }
}
</script>

<template>
    <Button type="button" variant="outline" size="sm" @click="copy">
        <Check v-if="copied" class="size-4" aria-hidden="true" />
        <Copy v-else class="size-4" aria-hidden="true" />
        <span aria-live="polite">
            {{ copied ? 'Kopiert' : (label ?? 'Link kopieren') }}
        </span>
    </Button>
</template>
