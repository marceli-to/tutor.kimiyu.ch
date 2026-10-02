<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, store } from '@/routes/lessons';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Neue Lernseite', href: create() }],
    },
});

const props = defineProps<{
    children: { id: number; name: string; level: string | null }[];
    maxImages: number;
    maxEdge: number;
}>();

const subjects = [
    'Natur und Technik',
    'Biologie',
    'Chemie',
    'Physik',
    'Mathematik',
    'Deutsch',
    'Französisch',
    'Englisch',
    'Räume, Zeiten, Gesellschaften',
    'Geschichte',
    'Geografie',
    'Wirtschaft, Arbeit, Haushalt',
    'Ethik, Religionen, Gemeinschaft',
    'Informatik',
];

const levels = [
    '1. Sek',
    '2. Sek',
    '3. Sek',
    '5. Klasse',
    '6. Klasse',
    'Gymnasium',
];

const form = useForm<{
    prompt: string;
    child_id: number | null;
    child_name: string;
    subject: string;
    level: string;
    with_hero: boolean;
    images: File[];
}>({
    prompt: '',
    child_id: props.children[0]?.id ?? null,
    child_name: '',
    subject: '',
    level: props.children[0]?.level ?? '',
    with_hero: true,
    images: [],
});

const preparing = ref(false);
const promptInput = ref<HTMLTextAreaElement | null>(null);

// Bausteine für den Auftrag, die «…» füllen die Eltern selbst aus
const promptChips = [
    'Prüfung am …',
    'Nur die Fachbegriffe',
    'Mit Beispielen aus dem Alltag',
    'Auch das Thema … ergänzen',
];

const hasSource = computed(
    () => form.images.length > 0 || form.prompt.trim() !== '',
);

const PROMPT_MAX = 1000;

function withChip(text: string): string {
    const current = form.prompt.trimEnd();

    if (current === '') {
        return text;
    }

    return /[.!?]$/.test(current)
        ? `${current} ${text}`
        : `${current}. ${text}`;
}

// Ein Baustein darf den Auftrag nicht über die erlaubte Länge schieben
function chipFits(text: string): boolean {
    return withChip(text).length <= PROMPT_MAX;
}

function addChip(text: string) {
    if (chipFits(text)) {
        form.prompt = withChip(text);
    }

    promptInput.value?.focus();
}

// Stufe vom gewählten Kind übernehmen, solange nichts anderes eingetragen ist
watch(
    () => form.child_id,
    (id) => {
        const child = props.children.find((c) => c.id === id);

        if (child?.level) {
            form.level = child.level;
        }
    },
);

function imageErrors(): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.images ??
        Object.entries(errors).find(([key]) => key.startsWith('images.'))?.[1]
    );
}

function submit() {
    form.transform((data) => ({
        ...data,
        child_id: props.children.length ? data.child_id : null,
        child_name: props.children.length ? '' : data.child_name,
    })).post(store().url, { forceFormData: true });
}
</script>

<template>
    <Head title="Neue Lernseite" />

    <div class="mx-auto w-full max-w-2xl p-4 md:p-6">
        <Heading
            title="Neue Lernseite"
            description="Aus Fotos vom Schulbuch, einem Auftrag oder beidem entsteht eine Lernseite mit Grafik, Quiz und Übungen."
        />

        <form class="space-y-6" @submit.prevent="submit">
            <PhotoPicker
                v-model="form.images"
                :max-images="maxImages"
                :max-edge="maxEdge"
                :error="imageErrors()"
                @busy="preparing = $event"
            />

            <div class="grid gap-2">
                <Label for="prompt">Auftrag (optional)</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="chip in promptChips"
                        :key="chip"
                        type="button"
                        class="rounded-full border px-3 py-1 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                        :disabled="!chipFits(chip)"
                        @click="addChip(chip)"
                    >
                        {{ chip }}
                    </button>
                </div>
                <textarea
                    id="prompt"
                    ref="promptInput"
                    v-model="form.prompt"
                    rows="4"
                    :maxlength="PROMPT_MAX"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                    placeholder="z. B. Prüfung am Freitag, vor allem die Begriffe auf Seite 2. Auch die Zellatmung, die kommt auch dran."
                    aria-describedby="prompt-hint"
                />
                <div class="flex items-start justify-between gap-4">
                    <p id="prompt-hint" class="text-sm text-muted-foreground">
                        Ohne Fotos schreibt die KI aus ihrem Fachwissen. Mit
                        Fotos bleibt sie beim Stoff der Fotos und ergänzt nur,
                        was fehlt. Ergänzungen sind für dich markiert. Bitte
                        keine Namen oder persönlichen Angaben.
                    </p>
                    <span
                        class="shrink-0 text-sm text-muted-foreground tabular-nums"
                    >
                        {{ form.prompt.length }}/{{ PROMPT_MAX }}
                    </span>
                </div>
                <InputError :message="form.errors.prompt" />
            </div>

            <div v-if="children.length" class="grid gap-2">
                <Label for="child_id">Für wen?</Label>
                <select
                    id="child_id"
                    v-model="form.child_id"
                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                >
                    <option
                        v-for="child in children"
                        :key="child.id"
                        :value="child.id"
                    >
                        {{ child.name }}
                    </option>
                </select>
                <InputError :message="form.errors.child_id" />
            </div>

            <div v-else class="grid gap-2">
                <Label for="child_name">Vorname des Kindes</Label>
                <Input
                    id="child_name"
                    v-model="form.child_name"
                    autocomplete="off"
                    placeholder="z. B. Mia"
                />
                <p class="text-sm text-muted-foreground">
                    Der Name bleibt in der App und wird nicht an die KI
                    geschickt.
                </p>
                <InputError :message="form.errors.child_name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="subject">Fach</Label>
                    <Input
                        id="subject"
                        v-model="form.subject"
                        list="subjects"
                        autocomplete="off"
                        placeholder="z. B. Biologie"
                    />
                    <datalist id="subjects">
                        <option v-for="s in subjects" :key="s" :value="s" />
                    </datalist>
                    <InputError :message="form.errors.subject" />
                </div>

                <div class="grid gap-2">
                    <Label for="level">Stufe</Label>
                    <Input
                        id="level"
                        v-model="form.level"
                        list="levels"
                        autocomplete="off"
                        placeholder="z. B. 2. Sek"
                    />
                    <datalist id="levels">
                        <option v-for="l in levels" :key="l" :value="l" />
                    </datalist>
                    <InputError :message="form.errors.level" />
                </div>
            </div>

            <div class="flex items-start gap-3">
                <input
                    id="with_hero"
                    v-model="form.with_hero"
                    type="checkbox"
                    class="mt-0.5 size-4 accent-primary"
                    aria-describedby="with_hero_hint"
                />
                <div class="grid gap-1">
                    <Label for="with_hero">Interaktive Grafik erstellen</Label>
                    <p
                        id="with_hero_hint"
                        class="text-sm text-muted-foreground"
                    >
                        Ohne Grafik ist die Seite schneller fertig und
                        günstiger. Sinnvoll z. B. bei Rechenverfahren,
                        Grammatikregeln oder Vokabeln. Passt keine Grafik zum
                        Stoff, lässt die KI sie auch von sich aus weg.
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="form.processing || preparing || !hasSource"
                >
                    <Spinner v-if="form.processing" />
                    Lernseite erstellen
                </Button>
                <span
                    v-if="form.progress"
                    class="text-sm text-muted-foreground"
                >
                    Hochladen … {{ form.progress.percentage }} %
                </span>
            </div>
        </form>
    </div>
</template>
