<script setup lang="ts">
import { computed, ref } from 'vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import { shuffle } from '@/lib/lesson';
import type { FlashcardModuleData } from '@/types';

const props = defineProps<{
    data: FlashcardModuleData;
}>();

const deck = ref(props.data.eintraege.slice());
const index = ref(0);
const back = ref(false);

const card = computed(() => deck.value[index.value]);

function go(step: number) {
    index.value = (index.value + step + deck.value.length) % deck.value.length;
    back.value = false;
}

function mix() {
    deck.value = shuffle(deck.value);
    index.value = 0;
    back.value = false;
}
</script>

<template>
    <div class="ls-panel">
        <p class="ls-progress">Karte {{ index + 1 }} von {{ deck.length }}</p>
        <button
            type="button"
            class="min-h-40 w-full cursor-pointer rounded-2xl border-2 p-5 text-center text-ls-ink"
            :class="
                back
                    ? 'border-ls-accent bg-ls-accent-bg'
                    : 'border-ls-line bg-ls-bg'
            "
            aria-live="polite"
            @click="back = !back"
        >
            <span v-if="back" class="text-[1.1rem]">{{ card.hinten }}</span>
            <template v-else>
                <span class="font-display text-2xl font-bold">{{
                    card.vorne
                }}</span>
                <OriginBadge :origin="card.herkunft" class="ml-1" />
                <br />
                <small class="text-ls-muted">Tippen zum Umdrehen</small>
            </template>
        </button>
        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            <button type="button" class="ls-btn" @click="go(-1)">Zurück</button>
            <button type="button" class="ls-btn ls-btn-primary" @click="go(1)">
                Weiter
            </button>
            <button type="button" class="ls-btn" @click="mix">Mischen</button>
        </div>
    </div>
</template>
