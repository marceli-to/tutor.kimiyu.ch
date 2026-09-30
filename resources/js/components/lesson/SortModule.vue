<script setup lang="ts">
import { computed, ref } from 'vue';
import { categoryClasses, shuffle } from '@/lib/lesson';
import type { CategoryId, ModuleAnswer, SortModuleData } from '@/types';

type Term = SortModuleData['begriffe'][number];

const props = defineProps<{
    data: SortModuleData;
}>();

const emit = defineEmits<{
    answer: [answer: ModuleAnswer];
}>();

const order = ref<Term[]>([]);
const position = ref(0);
const right = ref(0);
const bins = ref<Record<string, { text: string; correct: boolean }[]>>({});
const last = ref<{ term: Term; correct: boolean } | null>(null);

const finished = computed(() => position.value >= order.value.length);
const current = computed(() => order.value[position.value]);
const labels = computed(() =>
    Object.fromEntries(props.data.kategorien.map((c) => [c.id, c.label])),
);

function start() {
    order.value = shuffle(props.data.begriffe);
    position.value = 0;
    right.value = 0;
    last.value = null;
    bins.value = Object.fromEntries(
        props.data.kategorien.map((c) => [c.id, []]),
    );
}

function choose(category: CategoryId) {
    const term = current.value;
    const correct = category === term.kategorie;

    if (correct) {
        right.value++;
    }

    bins.value[category].push({ text: term.text, correct });
    last.value = { term, correct };
    position.value++;

    emit('answer', { module: 'sortieren', itemId: term.id, correct });
}

start();
</script>

<template>
    <div class="ls-panel" aria-live="polite">
        <template v-if="finished">
            <p class="ls-progress">Fertig sortiert</p>
            <div class="ls-score">{{ right }} / {{ order.length }}</div>
            <p>
                {{
                    right === order.length
                        ? 'Perfekt sortiert!'
                        : 'Durchgestrichene Begriffe lagen im falschen Korb.'
                }}
            </p>
        </template>

        <template v-else>
            <p class="ls-progress">
                Begriff {{ position + 1 }} von {{ order.length }}
            </p>
            <div
                class="mt-2 mb-4 rounded-2xl border-2 border-dashed border-ls-line px-2 py-5 text-center font-display text-[clamp(1.5rem,6vw,2.2rem)] font-bold"
            >
                {{ current.text }}
            </div>
            <div
                class="grid grid-cols-[repeat(auto-fit,minmax(140px,1fr))] gap-3"
            >
                <button
                    v-for="category in data.kategorien"
                    :key="category.id"
                    type="button"
                    class="cursor-pointer rounded-[14px] border-2 bg-transparent p-3 text-[1.05rem] text-ls-ink"
                    :class="[
                        categoryClasses[category.id].border,
                        categoryClasses[category.id].hover,
                    ]"
                    @click="choose(category.id)"
                >
                    {{ category.label }}
                    <small
                        v-if="category.sub"
                        class="block text-[0.85rem] text-ls-muted"
                    >
                        {{ category.sub }}
                    </small>
                </button>
            </div>
        </template>

        <div
            v-if="last && !finished"
            class="ls-feedback"
            :class="last.correct ? 'ls-feedback-ok' : 'ls-feedback-no'"
        >
            <strong>{{ last.correct ? 'Richtig!' : 'Nicht ganz.' }}</strong>
            «{{ last.term.text }}» gehört zu {{ labels[last.term.kategorie] }}.
            <template v-if="last.term.erklaerung">
                {{ last.term.erklaerung }}
            </template>
        </div>

        <div
            class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(140px,1fr))] gap-3"
        >
            <div
                v-for="category in data.kategorien"
                :key="category.id"
                class="min-h-12 rounded-xl px-3 py-2.5 text-[0.95rem]"
                :class="categoryClasses[category.id].bg"
            >
                <b class="block text-[0.85rem] font-normal text-ls-muted">
                    {{ category.label }}
                </b>
                <span
                    v-for="(tag, k) in bins[category.id]"
                    :key="k"
                    class="mt-1 mr-1.5 inline-block rounded-full bg-ls-card px-2 py-px text-[0.9rem]"
                    :class="{ 'text-ls-bad line-through': !tag.correct }"
                >
                    {{ tag.text }}
                    <span v-if="!tag.correct" class="sr-only">(falsch)</span>
                </span>
            </div>
        </div>

        <div v-if="finished" class="mt-4 flex flex-wrap items-center gap-2.5">
            <button type="button" class="ls-btn ls-btn-primary" @click="start">
                Nochmals sortieren
            </button>
        </div>
    </div>
</template>
