<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import GraphicsField from '@/components/GraphicsField.vue';
import type { GraphicsMode, GraphicWish } from '@/components/GraphicsField.vue';
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
    patterns: { value: string; label: string }[];
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
    graphics_mode: GraphicsMode;
    graphics: GraphicWish[];
    images: File[];
}>({
    prompt: '',
    child_id: props.children[0]?.id ?? null,
    child_name: '',
    subject: '',
    level: props.children[0]?.level ?? '',
    graphics_mode: 'auto',
    graphics: [],
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

async function addChip(text: string) {
    if (!chipFits(text)) {
        promptInput.value?.focus();

        return;
    }

    const next = withChip(text);
    // Der neue Baustein steht am Ende; seine «…» markieren, damit die Eltern sie gleich überschreiben
    const placeholder = next.indexOf('…', next.length - text.length);
    form.prompt = next;

    await nextTick();
    promptInput.value?.focus();

    if (placeholder !== -1) {
        promptInput.value?.setSelectionRange(placeholder, placeholder + 1);
    }
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
        // Wünsche nur bei «Selbst beschreiben» mitschicken
        graphics: data.graphics_mode === 'custom' ? data.graphics : [],
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

            <GraphicsField
                v-model:mode="form.graphics_mode"
                v-model:graphics="form.graphics"
                :patterns="patterns"
                :errors="form.errors"
            />

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="form.processing || preparing || !hasSource"
                >
                    <Spinner v-if="form.processing" />
                    Lernseite erstellen
                </Button>
                <span
                    v-if="!hasSource && !preparing"
                    class="text-sm text-muted-foreground"
                >
                    Lade ein Foto hoch oder schreib einen Auftrag.
                </span>
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
