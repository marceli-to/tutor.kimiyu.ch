<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useIsDark } from '@/composables/useIsDark';
import type { LessonHero } from '@/types';

const props = defineProps<{
    hero: LessonHero;
}>();

const emit = defineEmits<{
    error: [message: string];
}>();

const frame = ref<HTMLIFrameElement | null>(null);
const height = ref(420);
const failed = ref(false);
const isDark = useIsDark();

// Startwert per Hash, damit die Grafik nicht erst hell aufblitzt
const src = computed(
    () => `${props.hero.url}#${isDark.value ? 'dark' : 'light'}`,
);
const initialSrc = ref('');

type HeroMessage = {
    source?: string;
    type?: string;
    height?: number;
    message?: string;
};

function onMessage(event: MessageEvent<HeroMessage>) {
    // Das iframe hat keinen eigenen Origin, also am Absender-Fenster erkennen
    if (!frame.value || event.source !== frame.value.contentWindow) {
        return;
    }

    const data = event.data;

    if (data?.source !== 'lernseite-hero') {
        return;
    }

    if (data.type === 'height' && typeof data.height === 'number') {
        height.value = Math.min(Math.max(data.height, 120), 2400);
    }

    if (data.type === 'error') {
        failed.value = true;
        emit('error', String(data.message ?? ''));
    }
}

watch(isDark, (dark) => {
    frame.value?.contentWindow?.postMessage(
        { type: 'theme', theme: dark ? 'dark' : 'light' },
        '*',
    );
});

onMounted(() => {
    window.addEventListener('message', onMessage);
    initialSrc.value = src.value;
});

onBeforeUnmount(() => window.removeEventListener('message', onMessage));
</script>

<template>
    <div class="mt-7">
        <iframe
            v-if="initialSrc"
            ref="frame"
            :src="initialSrc"
            sandbox="allow-scripts"
            referrerpolicy="no-referrer"
            :title="`Interaktive Grafik: ${hero.description}`"
            class="block w-full border-0"
            :style="{ height: `${height}px` }"
        />
        <p
            v-if="failed"
            class="mt-2 rounded-xl bg-ls-bad-bg px-4 py-3 text-base text-ls-bad"
            role="status"
        >
            In der Grafik ist ein Fehler aufgetreten. Sie funktioniert
            vielleicht nicht vollständig.
        </p>
    </div>
</template>
