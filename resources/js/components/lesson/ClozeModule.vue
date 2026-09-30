<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { normalizeAnswer } from '@/lib/lesson';
import type { ClozeModuleData, ModuleAnswer } from '@/types';

const props = defineProps<{
    data: ClozeModuleData;
}>();

const emit = defineEmits<{
    answer: [answer: ModuleAnswer];
}>();

const gaps = computed(() =>
    props.data.segmente.flatMap((s) => ('loesungen' in s ? [s] : [])),
);

const values = reactive<Record<string, string>>({});
const states = reactive<Record<string, 'right' | 'wrong' | undefined>>({});
const error = ref('');
const result = ref<number | null>(null);

function gapNumber(id: string): number {
    return gaps.value.findIndex((g) => g.id === id) + 1;
}

function edited(id: string) {
    states[id] = undefined;
    error.value = '';
}

function check() {
    if (gaps.value.every((g) => !(values[g.id] ?? '').trim())) {
        error.value = 'Füll zuerst mindestens eine Lücke aus.';

        return;
    }

    let right = 0;

    for (const gap of gaps.value) {
        const value = normalizeAnswer(values[gap.id] ?? '');
        const correct = gap.loesungen.some((a) => normalizeAnswer(a) === value);

        states[gap.id] = correct ? 'right' : 'wrong';

        if (correct) {
            right++;
        }

        if (value !== '') {
            emit('answer', {
                module: 'lueckentext',
                itemId: gap.id,
                answer: values[gap.id] ?? '',
                correct,
            });
        }
    }

    result.value = right;
}

function showSolution() {
    for (const gap of gaps.value) {
        values[gap.id] = gap.loesungen[0];
        states[gap.id] = 'right';
    }

    result.value = null;
}

function clear() {
    for (const gap of gaps.value) {
        values[gap.id] = '';
        states[gap.id] = undefined;
    }

    result.value = null;
}

function inputClass(id: string): string {
    return {
        right: 'border-ls-ok bg-ls-ok-bg',
        wrong: 'border-ls-bad bg-ls-bad-bg',
        none: 'border-ls-line bg-ls-bg',
    }[states[id] ?? 'none'];
}
</script>

<template>
    <div class="ls-panel">
        <p class="leading-[2.3]">
            <template v-for="(segment, k) in data.segmente" :key="k">
                <input
                    v-if="'loesungen' in segment"
                    v-model="values[segment.id]"
                    type="text"
                    class="w-[9em] rounded-lg border-[1.5px] px-2 py-0.5 text-base text-ls-ink"
                    :class="inputClass(segment.id)"
                    :aria-label="`Lücke ${gapNumber(segment.id)}`"
                    :aria-invalid="states[segment.id] === 'wrong' || undefined"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    @input="edited(segment.id)"
                    @keydown.enter.prevent="check"
                />
                <template v-else>{{ segment.text }}</template>
            </template>
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            <button type="button" class="ls-btn ls-btn-primary" @click="check">
                Prüfen
            </button>
            <button type="button" class="ls-btn" @click="showSolution">
                Lösung zeigen
            </button>
            <button type="button" class="ls-btn" @click="clear">Leeren</button>
            <span v-if="error" class="text-[0.95rem] text-ls-bad">
                {{ error }}
            </span>
        </div>

        <div aria-live="polite">
            <div
                v-if="result !== null"
                class="ls-feedback"
                :class="
                    result === gaps.length ? 'ls-feedback-ok' : 'ls-feedback-no'
                "
            >
                <strong>{{ result }} von {{ gaps.length }} richtig.</strong>
                {{
                    result === gaps.length
                        ? 'Super!'
                        : 'Rot markierte Lücken nochmals versuchen.'
                }}
            </div>
        </div>
    </div>
</template>
