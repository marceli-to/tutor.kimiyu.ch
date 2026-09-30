<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import LessonPage from '@/components/lesson/LessonPage.vue';
import type { LessonContent, LessonHero, Palette } from '@/types';

defineProps<{
    lesson: {
        id: number;
        subject: string;
        level: string;
        content: LessonContent;
        palette: Palette;
        hero: LessonHero | null;
    };
}>();
</script>

<template>
    <Head :title="lesson.content.meta.titel" />

    <LessonPage
        :content="lesson.content"
        :palette="lesson.palette"
        :hero="lesson.hero"
        :subject="lesson.subject"
        :level="lesson.level"
    />
</template>
