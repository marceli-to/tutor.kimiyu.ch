<script setup lang="ts">
import { ImagePlus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { checkImageQuality } from '@/lib/imageQuality';
import type { ImageQuality } from '@/lib/imageQuality';
import { resizeImage } from '@/lib/resizeImage';

const props = defineProps<{
    modelValue: File[];
    maxImages: number;
    maxEdge: number;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [files: File[]];
    busy: [busy: boolean];
}>();

type Item = {
    id: number;
    file: File;
    url: string;
    quality: ImageQuality | null;
};

const items = ref<Item[]>([]);
const preparing = ref(false);
const imageError = ref('');
const fileInput = ref<HTMLInputElement | null>(null);

let nextId = 1;

const canAddMore = computed(() => items.value.length < props.maxImages);

function emitFiles() {
    emit(
        'update:modelValue',
        items.value.map((item) => item.file),
    );
}

// Setzt das Formular die Fotos von aussen zurück, verschwinden die Vorschauen mit
watch(
    () => props.modelValue,
    (files) => {
        const kept = items.value.filter((item) => files.includes(item.file));

        items.value
            .filter((item) => !kept.includes(item))
            .forEach((item) => URL.revokeObjectURL(item.url));

        if (kept.length !== items.value.length) {
            items.value = kept;
        }
    },
);

function setBusy(busy: boolean) {
    preparing.value = busy;
    emit('busy', busy);
}

/** Einziger Weg, wie Fotos dazukommen. */
async function addFiles(files: File[]) {
    imageError.value = '';

    // HEIC vom iPhone kommt in manchen Browsern ohne Typ an
    const images = files.filter(
        (file) =>
            file.type.startsWith('image/') ||
            (file.type === '' && /\.(heic|heif)$/i.test(file.name)),
    );

    if (images.length < files.length) {
        imageError.value = 'Nur Fotos, keine anderen Dateien.';
    }

    const room = props.maxImages - items.value.length;

    if (images.length > room) {
        imageError.value = `Höchstens ${props.maxImages} Fotos pro Lernseite.`;
    }

    if (room <= 0 || images.length === 0) {
        return;
    }

    setBusy(true);

    for (const file of images.slice(0, room)) {
        try {
            const resized = await resizeImage(file, props.maxEdge);
            const item: Item = {
                id: nextId++,
                file: resized,
                url: URL.createObjectURL(resized),
                quality: null,
            };

            items.value.push(item);
            emitFiles();

            // Läuft im Hintergrund, das Hochladen wartet nicht darauf
            void checkImageQuality(resized).then((quality) => {
                const current = items.value.find((i) => i.id === item.id);

                if (current) {
                    current.quality = quality;
                }
            });
        } catch (e) {
            imageError.value = (e as Error).message;
        }
    }

    setBusy(false);
}

function onFileInput(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    void addFiles(files);
}

function removeImage(index: number) {
    const [item] = items.value.splice(index, 1);
    URL.revokeObjectURL(item.url);
    emitFiles();
}

onBeforeUnmount(() =>
    items.value.forEach((item) => URL.revokeObjectURL(item.url)),
);
</script>

<template>
    <div class="grid gap-2">
        <Label for="images">Fotos (optional)</Label>
        <p id="images-hint" class="text-sm text-muted-foreground">
            Die Fotos geben den Rahmen vor: Stoff, Begriffe, Niveau. Gut lesbar,
            gerade von oben, ganze Seite im Bild. Sie werden nach der Erstellung
            gelöscht.
        </p>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
                v-for="(item, index) in items"
                :key="item.id"
                class="relative aspect-[3/4] overflow-hidden rounded-lg border"
            >
                <img
                    :src="item.url"
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
                {{ preparing ? 'Wird vorbereitet …' : 'Foto hinzufügen' }}
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
            @change="onFileInput"
        />
        <InputError :message="imageError || error" />
    </div>
</template>
