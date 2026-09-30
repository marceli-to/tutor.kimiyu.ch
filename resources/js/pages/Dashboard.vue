<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import CopyLink from '@/components/CopyLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { progress } from '@/routes/children';
import { create, show } from '@/routes/lessons';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Übersicht', href: dashboard() }],
    },
});

type LessonItem = {
    id: number;
    title: string;
    emoji: string | null;
    status: 'draft' | 'generating' | 'review' | 'published' | 'failed';
    statusLabel: string;
    date: string | null;
};

defineProps<{
    children: {
        id: number;
        name: string;
        level: string | null;
        shareUrl: string;
        subjects: { name: string; lessons: LessonItem[] }[];
    }[];
}>();

const badgeVariant = {
    draft: 'secondary',
    generating: 'secondary',
    review: 'default',
    published: 'outline',
    failed: 'destructive',
} as const;
</script>

<template>
    <Head title="Übersicht" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Lernseiten</h1>
            <Button as-child>
                <Link :href="create()">
                    <ImagePlus class="size-4" aria-hidden="true" />
                    Neue Lernseite
                </Link>
            </Button>
        </div>

        <div
            v-if="!children.length"
            class="rounded-xl border border-dashed p-8 text-center"
        >
            <p class="font-medium">Noch keine Lernseiten</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Fotografiere eine Seite aus dem Schulbuch oder gib ein Thema
                ein. Daraus entsteht die erste Lernseite.
            </p>
            <Button class="mt-4" as-child>
                <Link :href="create()">Erste Lernseite erstellen</Link>
            </Button>
        </div>

        <section
            v-for="child in children"
            :key="child.id"
            :aria-labelledby="`child-${child.id}`"
            class="space-y-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 :id="`child-${child.id}`" class="text-lg font-semibold">
                    {{ child.name }}
                    <span
                        v-if="child.level"
                        class="text-sm font-normal text-muted-foreground"
                    >
                        · {{ child.level }}
                    </span>
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="progress(child.id)">Lernstand</Link>
                    </Button>
                    <CopyLink
                        :url="child.shareUrl"
                        :label="`Link für ${child.name} kopieren`"
                    />
                </div>
            </div>

            <p
                v-if="!child.subjects.length"
                class="text-sm text-muted-foreground"
            >
                Noch keine Lernseiten für {{ child.name }}.
            </p>

            <div v-for="subject in child.subjects" :key="subject.name">
                <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                    {{ subject.name }}
                </h3>
                <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="lesson in subject.lessons" :key="lesson.id">
                        <Link
                            :href="show(lesson.id)"
                            class="flex h-full items-start gap-3 rounded-xl border p-4 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span class="text-2xl" aria-hidden="true">
                                {{ lesson.emoji ?? '📄' }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">
                                    {{ lesson.title }}
                                </span>
                                <span
                                    class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                                >
                                    <Badge
                                        :variant="badgeVariant[lesson.status]"
                                    >
                                        {{ lesson.statusLabel }}
                                    </Badge>
                                    {{ lesson.date }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
