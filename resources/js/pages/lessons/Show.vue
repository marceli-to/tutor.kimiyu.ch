<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationStatus from '@/components/lesson/GenerationStatus.vue';
import LessonPage from '@/components/lesson/LessonPage.vue';
import ParentToolbar from '@/components/lesson/ParentToolbar.vue';
import ReviewNotice from '@/components/lesson/ReviewNotice.vue';
import type {
    GraphicState,
    LessonContent,
    LessonGraphics,
    LessonHero,
    Palette,
} from '@/types';

const props = defineProps<{
    // Nur für die Eltern, nicht in der lokalen Vorschau
    parent: {
        childName: string;
        shareUrl: string | null;
        canPublish: boolean;
        canRegenerate: { quiz: boolean };
        graphics: GraphicState[];
        additions: string[];
    } | null;
    lesson: {
        id: number;
        status: 'draft' | 'generating' | 'review' | 'published' | 'failed';
        step: string | null;
        error: string | null;
        canRetry: boolean;
        fromTopic: boolean;
        // Positionen der Grafiken, die gebaut werden (für die Fortschrittsanzeige)
        plannedGraphics: number[];
        subject: string;
        level: string;
        content: LessonContent | null;
        palette: Palette | null;
        hero: LessonHero | null;
        graphics: LessonGraphics;
        checkNotes: { bereich: string; aenderung: string }[];
    };
}>();

// A regenerated part keeps the status (the child still sees the page), only the step shows it
const regenerating = computed(
    () => props.lesson.step?.startsWith('neu-') ?? false,
);

const generating = computed(
    () =>
        ['draft', 'generating'].includes(props.lesson.status) ||
        regenerating.value,
);

// Solange die Seite entsteht, alle 3 Sekunden den Stand abfragen
// (with the parent's actions and graphic errors, which change at the end as well)
const { start, stop } = usePoll(
    3000,
    { only: ['lesson', 'parent'] },
    { autoStart: generating.value },
);

watch(generating, (active) => (active ? start() : stop()));
</script>

<template>
    <Head :title="lesson.content?.meta.titel ?? 'Lernseite entsteht'" />

    <GenerationStatus
        v-if="
            generating ||
            lesson.status === 'failed' ||
            !lesson.content ||
            !lesson.palette
        "
        :lesson-id="lesson.id"
        :status="lesson.status"
        :step="lesson.step"
        :error="lesson.error"
        :can-retry="lesson.canRetry"
        :planned-graphics="lesson.plannedGraphics"
    />

    <template v-else>
        <ParentToolbar
            v-if="parent"
            :lesson-id="lesson.id"
            :status="lesson.status"
            :child-name="parent.childName"
            :share-url="parent.shareUrl"
            :can-publish="parent.canPublish"
            :can-regenerate="parent.canRegenerate"
            :graphics="parent.graphics"
        />

        <LessonPage
            :content="lesson.content"
            :palette="lesson.palette"
            :hero="lesson.hero"
            :graphics="lesson.graphics"
            :subject="lesson.subject"
            :level="lesson.level"
        >
            <template v-if="lesson.status === 'review' || lesson.error" #before>
                <ReviewNotice
                    :status="lesson.status"
                    :error="lesson.error"
                    :check-notes="lesson.checkNotes"
                    :graphics="parent?.graphics ?? []"
                    :from-topic="lesson.fromTopic"
                    :additions="parent?.additions ?? []"
                />
            </template>
        </LessonPage>
    </template>
</template>
