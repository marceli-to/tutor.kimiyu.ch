<script setup lang="ts">
import InputError from '@/components/InputError.vue';

export type Purpose = 'new' | 'exam';
export type Scope = 'short' | 'normal' | 'detailed';
export type LessonModule = 'quiz' | 'sorting' | 'flashcards' | 'cloze';
export type ScopeInfo = Record<
    Scope,
    {
        sections: string;
        quiz: number;
        flashcards: string;
        terms: string;
        gaps: string;
    }
>;

const props = defineProps<{
    scopeInfo: ScopeInfo;
    errors: Record<string, string | undefined>;
}>();

const purpose = defineModel<Purpose>('purpose', { required: true });
const scope = defineModel<Scope>('scope', { required: true });
const modules = defineModel<LessonModule[]>('modules', { required: true });

const purposes: { value: Purpose; label: string; hint: string }[] = [
    {
        value: 'new',
        label: 'Neuer Stoff',
        hint: 'Erklärt Schritt für Schritt, mit Beispielen aus dem Alltag.',
    },
    {
        value: 'exam',
        label: 'Prüfungsvorbereitung',
        hint: 'Kompakt, mit Fokus auf Fachbegriffe und einer Zusammenfassung am Schluss.',
    },
];

const scopeLabels: Record<Scope, string> = {
    short: 'Kurz',
    normal: 'Normal',
    detailed: 'Ausführlich',
};

const scopes = Object.keys(scopeLabels) as Scope[];

function scopeHint(value: Scope): string {
    const info = props.scopeInfo[value];

    return `${info.quiz} Quizfragen, ${info.sections} Abschnitte`;
}

const moduleLabels: Record<LessonModule, string> = {
    quiz: 'Quiz',
    sorting: 'Sortierspiel',
    flashcards: 'Karteikarten',
    cloze: 'Lückentext',
};

const allModules = Object.keys(moduleLabels) as LessonModule[];

// At least one module stays checked
function isLast(value: LessonModule): boolean {
    return modules.value.length === 1 && modules.value[0] === value;
}

function toggleModule(value: LessonModule, checked: boolean) {
    if (!checked && isLast(value)) {
        return;
    }

    // Order as in the list, whatever order they were checked in
    modules.value = allModules.filter((m) =>
        m === value ? checked : modules.value.includes(m),
    );
}

function moduleError(): string | undefined {
    return (
        props.errors.modules ??
        Object.entries(props.errors).find(([key]) =>
            key.startsWith('modules.'),
        )?.[1]
    );
}
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="mb-2 text-sm leading-none font-medium">Zweck</legend>
        <div class="grid gap-2 sm:grid-cols-2">
            <label
                v-for="option in purposes"
                :key="option.value"
                class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 shadow-xs has-[:checked]:border-primary has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
            >
                <input
                    v-model="purpose"
                    type="radio"
                    name="purpose"
                    class="mt-0.5 size-4 accent-primary"
                    :value="option.value"
                    :aria-describedby="`purpose-${option.value}`"
                />
                <span class="grid gap-1">
                    <span class="text-sm font-medium">{{ option.label }}</span>
                    <span
                        :id="`purpose-${option.value}`"
                        class="text-sm text-muted-foreground"
                    >
                        {{ option.hint }}
                    </span>
                </span>
            </label>
        </div>
        <InputError :message="errors.purpose" />
    </fieldset>

    <fieldset class="grid gap-3">
        <legend class="mb-2 text-sm leading-none font-medium">Umfang</legend>
        <div class="grid gap-2 sm:grid-cols-3">
            <label
                v-for="value in scopes"
                :key="value"
                class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 shadow-xs has-[:checked]:border-primary has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
            >
                <input
                    v-model="scope"
                    type="radio"
                    name="scope"
                    class="mt-0.5 size-4 accent-primary"
                    :value="value"
                    :aria-describedby="`scope-${value}`"
                />
                <span class="grid gap-1">
                    <span class="text-sm font-medium">
                        {{ scopeLabels[value] }}
                    </span>
                    <span
                        :id="`scope-${value}`"
                        class="text-sm text-muted-foreground"
                    >
                        {{ scopeHint(value) }}
                    </span>
                </span>
            </label>
        </div>
        <InputError :message="errors.scope" />
    </fieldset>

    <fieldset class="grid gap-3">
        <legend class="mb-2 text-sm leading-none font-medium">
            Lernmodule
        </legend>
        <div class="grid gap-2 sm:grid-cols-2">
            <label
                v-for="value in allModules"
                :key="value"
                class="flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 shadow-xs has-[:checked]:border-primary has-[:disabled]:cursor-not-allowed has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
            >
                <input
                    type="checkbox"
                    name="modules[]"
                    class="size-4 accent-primary"
                    :value="value"
                    :checked="modules.includes(value)"
                    :disabled="isLast(value)"
                    :title="
                        isLast(value)
                            ? 'Mindestens ein Lernmodul bleibt ausgewählt.'
                            : undefined
                    "
                    @change="
                        toggleModule(
                            value,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                <span class="text-sm font-medium">
                    {{ moduleLabels[value] }}
                </span>
            </label>
        </div>
        <p class="text-sm text-muted-foreground">
            Die KI nimmt nur Module, die zum Stoff passen. Das Quiz kommt immer,
            wenn es angekreuzt ist.
        </p>
        <InputError :message="moduleError()" />
    </fieldset>
</template>
