<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { nextTick } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

export type GraphicsMode = 'none' | 'auto' | 'custom';
export type GraphicWish = { beschreibung: string; muster: string | null };

const props = defineProps<{
    patterns: { value: string; label: string }[];
    errors: Record<string, string | undefined>;
}>();

const mode = defineModel<GraphicsMode>('mode', { required: true });
const graphics = defineModel<GraphicWish[]>('graphics', { required: true });

const MAX_GRAPHICS = 3;
const DESCRIPTION_MAX = 500;

const modes: { value: GraphicsMode; label: string; hint: string }[] = [
    {
        value: 'none',
        label: 'Keine',
        hint: 'Schneller fertig und günstiger. Sinnvoll z. B. bei Rechenverfahren, Grammatikregeln oder Vokabeln.',
    },
    {
        value: 'auto',
        label: 'KI entscheidet',
        hint: 'Eine Grafik, wenn eine zum Stoff passt. Sonst lässt die KI sie weg.',
    },
    {
        value: 'custom',
        label: 'Selbst beschreiben',
        hint: 'Bis zu drei Grafiken nach deinen Wünschen.',
    },
];

function selectMode(value: GraphicsMode) {
    mode.value = value;

    if (value === 'custom' && graphics.value.length === 0) {
        graphics.value = [{ beschreibung: '', muster: null }];
    }
}

async function addGraphic() {
    if (graphics.value.length >= MAX_GRAPHICS) {
        return;
    }

    graphics.value = [...graphics.value, { beschreibung: '', muster: null }];

    await nextTick();
    document.getElementById(`graphic-${graphics.value.length - 1}`)?.focus();
}

function removeGraphic(index: number) {
    graphics.value = graphics.value.filter((_, k) => k !== index);
}

function update(index: number, changes: Partial<GraphicWish>) {
    graphics.value = graphics.value.map((graphic, k) =>
        k === index ? { ...graphic, ...changes } : graphic,
    );
}

function rowError(index: number, field: keyof GraphicWish) {
    return props.errors[`graphics.${index}.${field}`];
}
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="mb-2 text-sm leading-none font-medium">Grafiken</legend>

        <div class="grid gap-2 sm:grid-cols-3">
            <label
                v-for="option in modes"
                :key="option.value"
                class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 shadow-xs has-[:checked]:border-primary has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
            >
                <input
                    type="radio"
                    name="graphics_mode"
                    class="mt-0.5 size-4 accent-primary"
                    :value="option.value"
                    :checked="mode === option.value"
                    :aria-describedby="`graphics-mode-${option.value}`"
                    @change="selectMode(option.value)"
                />
                <span class="grid gap-1">
                    <span class="text-sm font-medium">{{ option.label }}</span>
                    <span
                        :id="`graphics-mode-${option.value}`"
                        class="text-sm text-muted-foreground"
                    >
                        {{ option.hint }}
                    </span>
                </span>
            </label>
        </div>
        <InputError :message="errors.graphics_mode" />

        <div v-if="mode === 'custom'" class="grid gap-4">
            <div
                v-for="(graphic, index) in graphics"
                :key="index"
                class="grid gap-2 rounded-md border p-3"
            >
                <div class="flex items-center justify-between gap-2">
                    <Label :for="`graphic-${index}`">
                        Was soll Grafik {{ index + 1 }} zeigen? Was kann man
                        damit tun?
                    </Label>
                    <Button
                        v-if="graphics.length > 1"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-7 shrink-0"
                        :aria-label="`Grafik ${index + 1} entfernen`"
                        @click="removeGraphic(index)"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </Button>
                </div>
                <textarea
                    :id="`graphic-${index}`"
                    :value="graphic.beschreibung"
                    rows="3"
                    :maxlength="DESCRIPTION_MAX"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                    placeholder="z. B. Ein Blatt, bei dem man Licht und Wasser mit Reglern verändert und sieht, wie viel Zucker entsteht."
                    @input="
                        update(index, {
                            beschreibung: ($event.target as HTMLTextAreaElement)
                                .value,
                        })
                    "
                />
                <div class="flex items-start justify-between gap-4">
                    <InputError :message="rowError(index, 'beschreibung')" />
                    <span
                        class="ml-auto shrink-0 text-sm text-muted-foreground tabular-nums"
                    >
                        {{ graphic.beschreibung.length }}/{{ DESCRIPTION_MAX }}
                    </span>
                </div>

                <div class="grid gap-2 sm:max-w-xs">
                    <Label :for="`graphic-${index}-pattern`">Muster</Label>
                    <select
                        :id="`graphic-${index}-pattern`"
                        :value="graphic.muster ?? ''"
                        class="h-9 w-full rounded-md border border-input bg-transparent pr-9 pl-3 text-sm shadow-xs"
                        @change="
                            update(index, {
                                muster:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">KI wählt</option>
                        <option
                            v-for="pattern in patterns"
                            :key="pattern.value"
                            :value="pattern.value"
                        >
                            {{ pattern.label }}
                        </option>
                    </select>
                    <InputError :message="rowError(index, 'muster')" />
                </div>
            </div>

            <InputError :message="errors.graphics" />

            <div>
                <Button
                    v-if="graphics.length < MAX_GRAPHICS"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addGraphic"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    Weitere Grafik
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                Grafik 1 steht oben auf der Seite, weitere im passenden
                Abschnitt. Jede Grafik verlängert die Erstellung um einige
                Minuten und kostet etwa $0.30–0.60.
            </p>
        </div>
    </fieldset>
</template>
