<script setup lang="ts">
import {
    Camera,
    ChevronLeft,
    ChevronRight,
    ImagePlus,
    TriangleAlert,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
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

// Own type when moving a preview, so it never mixes with files
const TILE_DRAG_TYPE = 'application/x-photo-tile';

const items = ref<Item[]>([]);
const pending = ref(0);
const imageError = ref('');
const announcement = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const cameraInput = ref<HTMLInputElement | null>(null);
const grid = ref<HTMLElement | null>(null);
const isTouch = ref(false);

// Files over the area; a counter, because enter/leave also fire on child elements
const fileDragDepth = ref(0);
const draggingIndex = ref<number | null>(null);
const dropTarget = ref<{ index: number; side: 'before' | 'after' } | null>(
    null,
);

let nextId = 1;
// Resizing is async; afterwards the component may already be gone
let unmounted = false;

const preparing = computed(() => pending.value > 0);
const canAddMore = computed(() => items.value.length < props.maxImages);
const showDropOverlay = computed(
    () => fileDragDepth.value > 0 && draggingIndex.value === null,
);

watch(preparing, (busy) => emit('busy', busy));

function emitFiles() {
    emit(
        'update:modelValue',
        items.value.map((item) => item.file),
    );
}

// If the form resets the photos from outside, the previews disappear too
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

function isImage(file: File): boolean {
    // HEIC from the iPhone arrives without a type in some browsers
    return (
        file.type.startsWith('image/') ||
        (file.type === '' && /\.(heic|heif)$/i.test(file.name))
    );
}

/** The only way photos are added: file picker, camera, drop, paste. */
async function addFiles(files: File[]) {
    imageError.value = '';

    const images = files.filter(isImage);

    if (images.length < files.length) {
        imageError.value = 'Nur Fotos, keine anderen Dateien.';
    }

    if (images.length === 0) {
        return;
    }

    pending.value++;

    try {
        for (const file of images) {
            // Check per photo, because several sources can deliver photos at the same time
            if (items.value.length >= props.maxImages) {
                imageError.value = `Höchstens ${props.maxImages} Fotos pro Lernseite.`;
                break;
            }

            try {
                const resized = await resizeImage(file, props.maxEdge);

                // The form is gone (e.g. sent or left): no preview, no object URL to leak
                if (unmounted) {
                    break;
                }

                if (items.value.length >= props.maxImages) {
                    imageError.value = `Höchstens ${props.maxImages} Fotos pro Lernseite.`;
                    break;
                }

                const item: Item = {
                    id: nextId++,
                    file: resized,
                    url: URL.createObjectURL(resized),
                    quality: null,
                };

                items.value.push(item);
                emitFiles();

                // Runs in the background; the upload doesn't wait for it
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
    } finally {
        pending.value--;
    }
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

function moveItem(from: number, to: number) {
    if (from === to || to < 0 || to >= items.value.length) {
        return;
    }

    const [item] = items.value.splice(from, 1);
    items.value.splice(to, 0, item);
    emitFiles();
}

async function moveWithButton(index: number, direction: -1 | 1) {
    const item = items.value[index];
    const to = index + direction;

    moveItem(index, to);
    announcement.value = `Foto ${index + 1} nach ${direction < 0 ? 'vorne' : 'hinten'} verschoben, jetzt Foto ${to + 1}.`;

    // Focus stays on the moved photo, even if its button at the edge disappears
    await nextTick();
    const own = direction < 0 ? 'prev' : 'next';
    const other = direction < 0 ? 'next' : 'prev';
    const button =
        grid.value?.querySelector<HTMLButtonElement>(
            `[data-move="${item.id}-${own}"]`,
        ) ??
        grid.value?.querySelector<HTMLButtonElement>(
            `[data-move="${item.id}-${other}"]`,
        );
    button?.focus();
}

function isFileDrag(event: DragEvent): boolean {
    return event.dataTransfer?.types.includes('Files') ?? false;
}

// Dropping files on the whole area

function onZoneDragEnter(event: DragEvent) {
    if (draggingIndex.value !== null || !isFileDrag(event)) {
        return;
    }

    event.preventDefault();
    fileDragDepth.value++;
}

function onZoneDragOver(event: DragEvent) {
    if (draggingIndex.value !== null || !isFileDrag(event)) {
        return;
    }

    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
    }
}

function onZoneDragLeave(event: DragEvent) {
    if (draggingIndex.value !== null || !isFileDrag(event)) {
        return;
    }

    fileDragDepth.value = Math.max(0, fileDragDepth.value - 1);
}

function onZoneDrop(event: DragEvent) {
    if (draggingIndex.value !== null || !isFileDrag(event)) {
        return;
    }

    event.preventDefault();
    fileDragDepth.value = 0;
    void addFiles(Array.from(event.dataTransfer?.files ?? []));
}

// Moving a preview with the mouse

function onTileDragStart(event: DragEvent, index: number) {
    draggingIndex.value = index;

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        // Firefox only starts dragging with data
        event.dataTransfer.setData(TILE_DRAG_TYPE, String(index));
    }
}

function onTileDragOver(event: DragEvent, index: number) {
    if (draggingIndex.value === null) {
        return;
    }

    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const side =
        event.clientX < rect.left + rect.width / 2 ? 'before' : 'after';
    dropTarget.value = { index, side };
}

function onTileDrop(event: DragEvent) {
    if (draggingIndex.value === null || !dropTarget.value) {
        return;
    }

    event.preventDefault();

    const from = draggingIndex.value;
    let to =
        dropTarget.value.side === 'after'
            ? dropTarget.value.index + 1
            : dropTarget.value.index;

    if (from < to) {
        to--;
    }

    moveItem(from, to);
    onTileDragEnd();
}

function onTileDragEnd() {
    draggingIndex.value = null;
    dropTarget.value = null;
}

// The browser must not open files dropped next to the area
function onWindowDragOver(event: DragEvent) {
    if (isFileDrag(event)) {
        event.preventDefault();
    }
}

function onWindowDrop(event: DragEvent) {
    if (isFileDrag(event)) {
        event.preventDefault();
    }

    fileDragDepth.value = 0;
}

function isTextField(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLTextAreaElement ||
        target instanceof HTMLInputElement ||
        (target instanceof HTMLElement && target.isContentEditable)
    );
}

function onPaste(event: ClipboardEvent) {
    const files = Array.from(event.clipboardData?.files ?? []);

    if (files.length === 0) {
        return;
    }

    // Text from Word or Excel often brings an image along; in the text field the text wins
    if (
        isTextField(event.target) &&
        (event.clipboardData?.types.includes('text/plain') ||
            !files.some(isImage))
    ) {
        return;
    }

    event.preventDefault();
    void addFiles(files);
}

onMounted(() => {
    // Desktop browsers ignore «capture» and would otherwise show the same dialog twice
    isTouch.value = window.matchMedia('(pointer: coarse)').matches;

    window.addEventListener('dragover', onWindowDragOver);
    window.addEventListener('drop', onWindowDrop);
    window.addEventListener('paste', onPaste);
});

onBeforeUnmount(() => {
    unmounted = true;
    window.removeEventListener('dragover', onWindowDragOver);
    window.removeEventListener('drop', onWindowDrop);
    window.removeEventListener('paste', onPaste);
    items.value.forEach((item) => URL.revokeObjectURL(item.url));
});

function qualityText(quality: ImageQuality | null): string | null {
    if (quality?.dark && quality.blurry) {
        return 'Dunkel und unscharf';
    }

    if (quality?.dark) {
        return 'Zu dunkel';
    }

    if (quality?.blurry) {
        return 'Wirkt unscharf';
    }

    return null;
}
</script>

<template>
    <div class="grid gap-2">
        <Label for="images">Fotos (optional)</Label>

        <div
            class="relative grid gap-2"
            @dragenter="onZoneDragEnter"
            @dragover="onZoneDragOver"
            @dragleave="onZoneDragLeave"
            @drop="onZoneDrop"
        >
            <p id="images-hint" class="text-sm text-muted-foreground">
                Die Fotos geben den Rahmen vor: Stoff, Begriffe, Niveau. Gut
                lesbar, gerade von oben, ganze Seite im Bild. Sie werden nach
                der Erstellung gelöscht.
                <template v-if="!isTouch">
                    Du kannst Fotos auch hierher ziehen oder mit Cmd/Ctrl+V
                    einfügen.
                </template>
            </p>

            <div ref="grid" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div
                    v-for="(item, index) in items"
                    :key="item.id"
                    class="relative aspect-[3/4] cursor-grab overflow-hidden rounded-lg border"
                    :class="{ 'opacity-50': draggingIndex === index }"
                    draggable="true"
                    @dragstart="onTileDragStart($event, index)"
                    @dragover="onTileDragOver($event, index)"
                    @drop="onTileDrop"
                    @dragend="onTileDragEnd"
                >
                    <img
                        :src="item.url"
                        :alt="`Foto ${index + 1}`"
                        class="size-full object-cover"
                        draggable="false"
                    />

                    <span
                        v-if="dropTarget?.index === index"
                        class="absolute inset-y-0 w-1 bg-primary"
                        :class="
                            dropTarget.side === 'before' ? 'left-0' : 'right-0'
                        "
                        aria-hidden="true"
                    />

                    <span
                        class="absolute top-1.5 left-1.5 flex size-6 items-center justify-center rounded-full bg-background/90 text-xs font-medium tabular-nums shadow"
                        aria-hidden="true"
                    >
                        {{ index + 1 }}
                    </span>

                    <button
                        type="button"
                        class="absolute top-1.5 right-1.5 rounded-full bg-background/90 p-1 shadow focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="`Foto ${index + 1} entfernen`"
                        @click="removeImage(index)"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </button>

                    <div class="absolute inset-x-0 bottom-0 grid gap-1.5">
                        <div
                            v-if="items.length > 1"
                            class="flex justify-between px-1.5"
                        >
                            <button
                                v-if="index > 0"
                                type="button"
                                class="rounded-full bg-background/90 p-1 shadow focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :data-move="`${item.id}-prev`"
                                :aria-label="`Foto ${index + 1} nach vorne`"
                                @click="moveWithButton(index, -1)"
                            >
                                <ChevronLeft
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </button>
                            <span v-else />
                            <button
                                v-if="index < items.length - 1"
                                type="button"
                                class="rounded-full bg-background/90 p-1 shadow focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :data-move="`${item.id}-next`"
                                :aria-label="`Foto ${index + 1} nach hinten`"
                                @click="moveWithButton(index, 1)"
                            >
                                <ChevronRight
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>

                        <p
                            v-if="qualityText(item.quality)"
                            class="flex items-center gap-1.5 bg-amber-100 px-2 py-1 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                            title="Lieber nochmals aufnehmen: gerade von oben, gutes Licht."
                        >
                            <TriangleAlert
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {{ qualityText(item.quality) }}
                        </p>
                    </div>
                </div>

                <button
                    v-if="canAddMore"
                    type="button"
                    class="flex aspect-[3/4] flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed text-sm text-muted-foreground hover:border-foreground/40 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :disabled="preparing"
                    aria-describedby="images-hint"
                    @click="fileInput?.click()"
                >
                    <Spinner v-if="preparing" />
                    <ImagePlus v-else class="size-6" aria-hidden="true" />
                    {{ preparing ? 'Wird vorbereitet …' : 'Foto hinzufügen' }}
                </button>

                <button
                    v-if="canAddMore && isTouch && !preparing"
                    type="button"
                    class="flex aspect-[3/4] flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed text-sm text-muted-foreground hover:border-foreground/40 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-describedby="images-hint"
                    @click="cameraInput?.click()"
                >
                    <Camera class="size-6" aria-hidden="true" />
                    Foto aufnehmen
                </button>
            </div>

            <div
                v-if="showDropOverlay"
                class="pointer-events-none absolute -inset-2 flex items-center justify-center rounded-lg bg-background/85 text-sm font-medium outline-2 outline-primary outline-dashed"
            >
                Fotos hier ablegen
            </div>
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
        <input
            ref="cameraInput"
            type="file"
            accept="image/*"
            capture="environment"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            @change="onFileInput"
        />
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
        <InputError :message="imageError || error" />
    </div>
</template>
