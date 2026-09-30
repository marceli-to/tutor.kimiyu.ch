<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ImagePlus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { resizeImage } from '@/lib/resizeImage';
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
    child_id: number | null;
    child_name: string;
    subject: string;
    level: string;
    notes: string;
    images: File[];
}>({
    child_id: props.children[0]?.id ?? null,
    child_name: '',
    subject: '',
    level: props.children[0]?.level ?? '',
    notes: '',
    images: [],
});

const previews = ref<string[]>([]);
const preparing = ref(false);
const imageError = ref('');
const fileInput = ref<HTMLInputElement | null>(null);

const canAddMore = computed(() => form.images.length < props.maxImages);

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

async function addImages(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    imageError.value = '';

    const room = props.maxImages - form.images.length;

    if (files.length > room) {
        imageError.value = `Höchstens ${props.maxImages} Fotos pro Lernseite.`;
    }

    preparing.value = true;

    for (const file of files.slice(0, room)) {
        try {
            const resized = await resizeImage(file, props.maxEdge);
            form.images.push(resized);
            previews.value.push(URL.createObjectURL(resized));
        } catch (e) {
            imageError.value = (e as Error).message;
        }
    }

    preparing.value = false;
}

function removeImage(index: number) {
    URL.revokeObjectURL(previews.value[index]);
    previews.value.splice(index, 1);
    form.images.splice(index, 1);
}

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

onBeforeUnmount(() =>
    previews.value.forEach((url) => URL.revokeObjectURL(url)),
);
</script>

<template>
    <Head title="Neue Lernseite" />

    <div class="mx-auto w-full max-w-2xl p-4 md:p-6">
        <Heading
            title="Neue Lernseite"
            description="Fotografiere 1 bis 4 Seiten aus dem Schulbuch oder Heft. Daraus entsteht eine Lernseite mit Grafik, Quiz und Übungen."
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="images">Fotos</Label>
                <p id="images-hint" class="text-sm text-muted-foreground">
                    Gut lesbar, gerade von oben, ganze Seite im Bild. Die Fotos
                    werden nach der Erstellung gelöscht.
                </p>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div
                        v-for="(url, index) in previews"
                        :key="url"
                        class="relative aspect-[3/4] overflow-hidden rounded-lg border"
                    >
                        <img
                            :src="url"
                            :alt="`Foto ${index + 1}`"
                            class="size-full object-cover"
                        />
                        <button
                            type="button"
                            class="absolute top-1.5 right-1.5 rounded-full bg-background/90 p-1 shadow"
                            :aria-label="`Foto ${index + 1} entfernen`"
                            @click="removeImage(index)"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </div>

                    <button
                        v-if="canAddMore"
                        type="button"
                        class="flex aspect-[3/4] flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed text-sm text-muted-foreground hover:border-foreground/40 hover:text-foreground"
                        :disabled="preparing"
                        aria-describedby="images-hint"
                        @click="fileInput?.click()"
                    >
                        <Spinner v-if="preparing" />
                        <ImagePlus v-else class="size-6" aria-hidden="true" />
                        {{
                            preparing ? 'Wird vorbereitet …' : 'Foto hinzufügen'
                        }}
                    </button>
                </div>

                <input
                    id="images"
                    ref="fileInput"
                    type="file"
                    accept="image/*"
                    multiple
                    class="sr-only"
                    tabindex="-1"
                    @change="addImages"
                />
                <InputError :message="imageError || imageErrors()" />
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

            <div class="grid gap-2">
                <Label for="notes">Hinweise (optional)</Label>
                <textarea
                    id="notes"
                    v-model="form.notes"
                    rows="3"
                    maxlength="500"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                    placeholder="z. B. Prüfung am Freitag, vor allem die Begriffe auf Seite 2"
                />
                <p class="text-sm text-muted-foreground">
                    Bitte keine Namen oder persönlichen Angaben.
                </p>
                <InputError :message="form.errors.notes" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="
                        form.processing || preparing || !form.images.length
                    "
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
