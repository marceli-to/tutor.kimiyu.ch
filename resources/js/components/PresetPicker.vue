<script setup lang="ts">
import { computed } from 'vue';
import { PRESETS, settingsSummary } from '@/lib/presets';
import type { LessonSettings, PresetKey } from '@/lib/presets';

export type PresetChoice = PresetKey | 'last' | 'custom';

const props = defineProps<{
    // Settings of the child's last lesson, only if they match no preset
    last: LessonSettings | null;
    // Settings changed in advanced mode that match no card
    custom: LessonSettings | null;
}>();

const choice = defineModel<PresetChoice>({ required: true });

const emit = defineEmits<{ pick: [choice: PresetChoice] }>();

const cards = computed(() => [
    ...PRESETS.map((preset) => ({
        value: preset.key as PresetChoice,
        label: preset.label,
        hint: preset.hint,
    })),
    ...(props.last
        ? [
              {
                  value: 'last' as PresetChoice,
                  label: 'Wie letztes Mal',
                  hint: settingsSummary(props.last),
              },
          ]
        : []),
    ...(props.custom
        ? [
              {
                  value: 'custom' as PresetChoice,
                  label: 'Eigene Einstellungen',
                  hint: settingsSummary(props.custom),
              },
          ]
        : []),
]);

function pick(value: PresetChoice) {
    choice.value = value;
    emit('pick', value);
}
</script>

<template>
    <fieldset class="grid gap-3">
        <legend class="mb-2 text-sm leading-none font-medium">
            Was für eine Lernseite?
        </legend>
        <div class="grid gap-2 sm:grid-cols-3">
            <label
                v-for="card in cards"
                :key="card.value"
                class="flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 shadow-xs has-[:checked]:border-primary has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
            >
                <input
                    type="radio"
                    name="preset"
                    class="mt-0.5 size-4 accent-primary"
                    :value="card.value"
                    :checked="choice === card.value"
                    :aria-describedby="`preset-${card.value}`"
                    @change="pick(card.value)"
                />
                <span class="grid gap-1">
                    <span class="text-sm font-medium">{{ card.label }}</span>
                    <span
                        :id="`preset-${card.value}`"
                        class="text-sm text-muted-foreground"
                    >
                        {{ card.hint }}
                    </span>
                </span>
            </label>
        </div>
    </fieldset>
</template>
