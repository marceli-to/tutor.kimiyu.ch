<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import LessonPage from '@/components/lesson/LessonPage.vue';
import { index } from '@/routes/shared';
import type { LessonContent, LessonHero, Palette } from '@/types';

defineProps<{
    token: string;
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
    <Head :title="lesson.content.meta.titel">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <LessonPage
        :content="lesson.content"
        :palette="lesson.palette"
        :hero="lesson.hero"
        :subject="lesson.subject"
        :level="lesson.level"
    >
        <template #before>
            <Link
                :href="index(token)"
                class="mb-6 inline-flex items-center gap-2 text-base text-ls-muted hover:text-ls-ink"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                Alle Lernseiten
            </Link>
        </template>
    </LessonPage>
</template>
