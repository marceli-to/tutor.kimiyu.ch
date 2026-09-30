<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard } from '@/routes';
import { Button } from '@/components/ui/button';
import { create, show } from '@/routes/lessons';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Übersicht',
                href: dashboard(),
            },
        ],
    },
});

defineProps<{
    lessons: {
        id: number;
        title: string | null;
        subject: string;
        child: string;
        emoji: string | null;
        status: string;
    }[];
}>();
</script>

<template>
    <Head title="Übersicht" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Lernseiten</h1>
            <Button as-child>
                <Link :href="create()">Neue Lernseite</Link>
            </Button>
        </div>
        <ul class="grid gap-3 md:grid-cols-2">
            <li v-for="lesson in lessons" :key="lesson.id">
                <Link
                    :href="show(lesson.id)"
                    class="flex items-center gap-3 rounded-xl border p-4 hover:bg-accent"
                >
                    <span class="text-2xl" aria-hidden="true">{{
                        lesson.emoji
                    }}</span>
                    <span>
                        <span class="block font-medium">{{
                            lesson.title
                        }}</span>
                        <span class="text-sm text-muted-foreground">
                            {{ lesson.subject }} · {{ lesson.child }} ·
                            {{ lesson.status }}
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
