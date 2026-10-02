<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { show } from '@/routes/shared';

defineProps<{
    token: string;
    childName: string;
    subjects: {
        name: string;
        lessons: {
            id: number;
            title: string;
            emoji: string | null;
            key_idea: string | null;
            progress: { mastered: number; total: number };
        }[];
    }[];
}>();
</script>

<template>
    <Head :title="`Lernseiten von ${childName}`">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="lesson min-h-screen"
        style="
            --p-accent-light: #134e5e;
            --p-accent-dark: #9ed3e0;
            --p-accent-bg-light: #d5e9ee;
            --p-accent-bg-dark: #18363f;
        "
    >
        <main class="mx-auto max-w-[780px] px-5 pt-10 pb-16">
            <h1
                class="text-[clamp(2rem,6vw,3rem)] font-bold tracking-[-0.02em] text-ls-accent"
            >
                Hallo {{ childName }}!
            </h1>
            <p class="mt-3 max-w-[66ch] text-[1.2rem] text-ls-muted">
                Hier sind deine Lernseiten. Such dir eine aus und leg los.
            </p>

            <p
                v-if="!subjects.length"
                class="mt-10 rounded-2xl border border-ls-line bg-ls-card px-5 py-4"
            >
                Noch keine Lernseiten. Sobald eine bereit ist, erscheint sie
                hier.
            </p>

            <section v-for="subject in subjects" :key="subject.name">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    {{ subject.name }}
                </h2>
                <ul class="grid gap-3">
                    <li v-for="lesson in subject.lessons" :key="lesson.id">
                        <Link
                            :href="show([token, lesson.id])"
                            class="flex items-start gap-4 rounded-2xl border border-ls-line bg-ls-card px-5 py-4 hover:border-ls-accent focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ls-focus"
                        >
                            <span class="text-3xl" aria-hidden="true">{{
                                lesson.emoji
                            }}</span>
                            <span>
                                <span
                                    class="block font-display text-[1.2rem] font-bold"
                                >
                                    {{ lesson.title }}
                                </span>
                                <span
                                    v-if="lesson.key_idea"
                                    class="mt-1 block text-base text-ls-muted"
                                >
                                    {{ lesson.key_idea }}
                                </span>
                                <span
                                    v-if="lesson.progress.total"
                                    class="mt-2 flex items-center gap-3 text-[0.95rem] text-ls-muted"
                                >
                                    <span
                                        class="h-2 w-24 overflow-hidden rounded-full bg-ls-line"
                                        aria-hidden="true"
                                    >
                                        <span
                                            class="block h-full rounded-full bg-ls-ok"
                                            :style="{
                                                width: `${(lesson.progress.mastered / lesson.progress.total) * 100}%`,
                                            }"
                                        />
                                    </span>
                                    {{ lesson.progress.mastered }} von
                                    {{ lesson.progress.total }} Aufgaben sitzen
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </main>
    </div>
</template>
