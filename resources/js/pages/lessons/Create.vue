<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import GraphicsField from '@/components/GraphicsField.vue';
import type { GraphicsMode, GraphicWish } from '@/components/GraphicsField.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LessonOptions from '@/components/LessonOptions.vue';
import type {
    LessonModule,
    Purpose,
    Scope,
    ScopeInfo,
} from '@/components/LessonOptions.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import PresetPicker from '@/components/PresetPicker.vue';
import type { PresetChoice } from '@/components/PresetPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { matchPreset, presetSettings, sameSettings } from '@/lib/presets';
import type { LessonSettings } from '@/lib/presets';
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
    lastSettings: Record<string, RememberedSettings>;
    lastByChild: Record<string, LessonSettings>;
    scopeInfo: ScopeInfo;
}>();

type RememberedSettings = LessonSettings;

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
    purpose: Purpose;
    scope: Scope;
    modules: LessonModule[];
    images: File[];
}>({
    prompt: '',
    child_id: props.children[0]?.id ?? null,
    child_name: '',
    subject: '',
    level: props.children[0]?.level ?? '',
    graphics_mode: 'auto',
    graphics: [],
    purpose: 'new',
    scope: 'normal',
    modules: ['quiz', 'sorting', 'flashcards', 'cloze'],
    images: [],
});

const preparing = ref(false);
const promptInput = ref<HTMLTextAreaElement | null>(null);

// Building blocks for the request; the parents fill in the «…» themselves
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

// A building block must not push the request beyond the allowed length
function chipFits(text: string): boolean {
    return withChip(text).length <= PROMPT_MAX;
}

async function addChip(text: string) {
    if (!chipFits(text)) {
        promptInput.value?.focus();

        return;
    }

    const next = withChip(text);
    // The new building block is at the end; select its «…» so the parents overwrite it right away
    const placeholder = next.indexOf('…', next.length - text.length);
    form.prompt = next;

    await nextTick();
    promptInput.value?.focus();

    if (placeholder !== -1) {
        promptInput.value?.setSelectionRange(placeholder, placeholder + 1);
    }
}

type FormMode = 'simple' | 'advanced';

const MODE_KEY = 'lernseite.formMode';

// Remembered mode; without storage (private window, blocked) «simple» applies
function storedMode(): FormMode {
    try {
        const stored = localStorage.getItem(MODE_KEY);

        // 'erweitert' is the value stored before the rename
        return stored === 'advanced' || stored === 'erweitert'
            ? 'advanced'
            : 'simple';
    } catch {
        return 'simple';
    }
}

const mode = ref<FormMode>(storedMode());
const advanced = computed(() => mode.value === 'advanced');

const selectedChild = computed(() =>
    props.children.find((c) => c.id === form.child_id),
);

// In simple mode the level comes from the child; the field is only needed without a level or for the first child
const needsLevel = computed(
    () => !props.children.length || !selectedChild.value?.level,
);

// Take the level from the selected child as long as nothing else is entered
watch(
    () => form.child_id,
    (id) => {
        const child = props.children.find((c) => c.id === id);

        if (child?.level) {
            form.level = child.level;
        }
    },
);

type RememberedField = keyof RememberedSettings;

const rememberedFields: RememberedField[] = [
    'purpose',
    'scope',
    'modules',
    'graphics_mode',
];

// Fields the parents changed themselves in this form are no longer overwritten by the remembered settings
const touched = new Set<RememberedField>();
let applying = false;

for (const field of rememberedFields) {
    watch(
        () => form[field],
        () => {
            if (!applying) {
                touched.add(field);
            }
        },
        { flush: 'sync' },
    );
}

function currentSettings(): LessonSettings {
    return {
        purpose: form.purpose,
        scope: form.scope,
        modules: [...form.modules],
        graphics_mode: form.graphics_mode,
    };
}

// Writes the values of a card into the form. Chosen by hand counts as changed,
// then the remembered settings per subject no longer overwrite these fields.
function applySettings(values: LessonSettings, byHand: boolean) {
    applying = !byHand;
    form.purpose = values.purpose;
    form.scope = values.scope;
    form.modules = [...values.modules];
    form.graphics_mode = values.graphics_mode;
    applying = false;
}

// Last lesson of the selected child, whatever the subject
const lastForChild = computed<LessonSettings | null>(() =>
    form.child_id !== null ? (props.lastByChild[form.child_id] ?? null) : null,
);

// «last» only if the last lesson matches no preset
const lastCard = computed(() =>
    lastForChild.value && !matchPreset(lastForChild.value)
        ? lastForChild.value
        : null,
);

// What was set in advanced mode and matches no card, including custom graphic wishes
const customSettings = ref<
    (LessonSettings & { graphics: GraphicWish[] }) | null
>(null);

const preset = ref<PresetChoice>('normal');
let presetByHand = false;

function defaultChoice(): PresetChoice {
    const last = lastForChild.value;

    if (!last) {
        return 'normal';
    }

    return matchPreset(last) ?? 'last';
}

function selectPreset(choice: PresetChoice, byHand: boolean) {
    preset.value = choice;

    if (choice === 'custom') {
        if (customSettings.value) {
            applySettings(customSettings.value, byHand);
            form.graphics = customSettings.value.graphics.map((g) => ({
                ...g,
            }));
        }

        return;
    }

    const values =
        choice === 'last' ? lastForChild.value : presetSettings(choice);

    if (values) {
        applySettings(values, byHand);
        form.graphics = [];
    }
}

function pickPreset(choice: PresetChoice) {
    presetByHand = true;
    selectPreset(choice, true);
}

selectPreset(defaultChoice(), false);

// Other child: determine the default again, unless the parents already chose a card
watch(
    () => form.child_id,
    () => {
        if (!advanced.value && !presetByHand) {
            selectPreset(defaultChoice(), false);
        } else if (!advanced.value && preset.value === 'last') {
            // «last» means the new child's last settings, never the previous child's.
            // Without them (or when they match a card) fall back to that card or «normal».
            selectPreset(
                lastCard.value
                    ? 'last'
                    : (lastForChild.value && matchPreset(lastForChild.value)) ||
                          'normal',
                true,
            );
        }
    },
);

function setMode(next: FormMode, remember = true) {
    if (next === 'simple') {
        // Choose the matching card; otherwise «custom», so nothing is silently overwritten
        const current = currentSettings();
        const match =
            matchPreset(current) ??
            (lastCard.value && sameSettings(current, lastCard.value)
                ? 'last'
                : null);

        if (match) {
            preset.value = match;
        } else {
            customSettings.value = {
                ...current,
                graphics: form.graphics.map((g) => ({ ...g })),
            };
            preset.value = 'custom';
        }
    }

    mode.value = next;

    if (remember) {
        try {
            localStorage.setItem(MODE_KEY, next);
        } catch {
            // Without storage the mode applies only to this visit
        }
    }
}

const appliedFrom = ref<{ child: string; subject: string } | null>(null);

// Take over the settings of the last lesson for this child in this subject.
// Only in advanced mode; in simple mode the subject is usually empty.
function applyRemembered() {
    if (!advanced.value) {
        return;
    }

    const child = props.children.find((c) => c.id === form.child_id);
    const subject = form.subject.trim();
    const remembered = child
        ? props.lastSettings[`${child.id}|${subject.toLowerCase()}`]
        : undefined;

    if (!child || !remembered) {
        appliedFrom.value = null;

        return;
    }

    const values: RememberedSettings = {
        ...remembered,
        modules: [...remembered.modules],
        // Custom graphic wishes apply only to that one lesson
        graphics_mode:
            remembered.graphics_mode === 'custom'
                ? 'auto'
                : remembered.graphics_mode,
    };
    const fields = rememberedFields.filter((field) => !touched.has(field));

    applying = true;

    for (const field of fields) {
        (form as Record<RememberedField, unknown>)[field] = values[field];
    }

    applying = false;
    appliedFrom.value = fields.length ? { child: child.name, subject } : null;
}

watch(
    () => [form.child_id, form.subject.trim().toLowerCase()],
    applyRemembered,
);

// Fields that only exist in advanced mode
const advancedFields = ['subject', 'purpose', 'scope', 'modules', 'graphics'];

// Errors in these fields must be visible
watch(
    () => Object.keys(form.errors),
    (keys) => {
        if (
            !advanced.value &&
            keys.some(
                (key) =>
                    advancedFields.some((field) => key.startsWith(field)) ||
                    (key === 'level' && !needsLevel.value),
            )
        ) {
            setMode('advanced', false);
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
        // In simple mode the AI detects the subject; the level comes from the child
        subject: advanced.value ? data.subject : '',
        level: advanced.value || needsLevel.value ? data.level : '',
        // Send wishes only with graphics mode «custom»
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
                    class="h-9 w-full rounded-md border border-input bg-transparent pr-9 pl-3 text-sm shadow-xs"
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

            <div
                v-if="advanced || needsLevel"
                class="grid gap-4 sm:grid-cols-2"
            >
                <div v-if="advanced" class="grid gap-2">
                    <Label for="subject">Fach (optional)</Label>
                    <Input
                        id="subject"
                        v-model="form.subject"
                        list="subjects"
                        autocomplete="off"
                        placeholder="leer lassen: die KI erkennt das Fach"
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

            <template v-if="advanced">
                <p v-if="appliedFrom" class="text-sm text-muted-foreground">
                    Wie bei der letzten Lernseite für
                    {{ appliedFrom.child }} in {{ appliedFrom.subject }}
                </p>

                <GraphicsField
                    v-model:mode="form.graphics_mode"
                    v-model:graphics="form.graphics"
                    :patterns="patterns"
                    :errors="form.errors"
                />

                <LessonOptions
                    v-model:purpose="form.purpose"
                    v-model:scope="form.scope"
                    v-model:modules="form.modules"
                    :scope-info="scopeInfo"
                    :errors="form.errors"
                />
            </template>

            <PresetPicker
                v-else
                v-model="preset"
                :last="lastCard"
                :custom="customSettings"
                @pick="pickPreset"
            />

            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <Button
                    type="submit"
                    :disabled="form.processing || preparing || !hasSource"
                >
                    <Spinner v-if="form.processing" />
                    Lernseite erstellen
                </Button>
                <Button
                    type="button"
                    variant="link"
                    class="px-0"
                    @click="setMode(advanced ? 'simple' : 'advanced')"
                >
                    {{
                        advanced
                            ? 'Weniger Einstellungen'
                            : 'Alle Einstellungen'
                    }}
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
