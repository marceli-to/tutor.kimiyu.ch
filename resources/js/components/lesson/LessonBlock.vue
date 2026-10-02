<script setup lang="ts">
import HeroFrame from '@/components/lesson/HeroFrame.vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import { categoryClasses } from '@/lib/lesson';
import type { LessonBlock, LessonGraphics } from '@/types';

defineProps<{
    block: LessonBlock;
    graphics: LessonGraphics;
}>();
</script>

<template>
    <!-- Eigene Zeile über dem Baustein, damit das Layout ohne Marke gleich bleibt -->
    <div
        v-if="block.herkunft === 'ergaenzt'"
        class="mt-4 mb-1 flex justify-end"
        :class="{ 'max-w-[66ch]': block.typ === 'absatz' }"
    >
        <OriginBadge :origin="block.herkunft" />
    </div>

    <p v-if="block.typ === 'absatz'" class="mb-4 max-w-[66ch]">
        {{ block.text }}
    </p>

    <div
        v-else-if="block.typ === 'formel'"
        class="mt-4 overflow-x-auto rounded-[14px] border border-ls-line bg-ls-card px-5 py-4 font-display text-[clamp(1.1rem,3.6vw,1.45rem)] font-medium"
    >
        {{ block.text }}
        <small
            v-if="block.zusatz"
            class="mt-1.5 block font-reading text-base text-ls-muted"
        >
            {{ block.zusatz }}
        </small>
    </div>

    <div v-else-if="block.typ === 'fakten'" class="mt-4 grid gap-5">
        <div
            v-for="(fact, k) in block.eintraege"
            :key="k"
            class="border-l-4 border-ls-accent pl-4"
        >
            <h3 class="mb-1.5 text-[1.15rem] font-medium">{{ fact.titel }}</h3>
            <p class="m-0 max-w-[66ch]">{{ fact.text }}</p>
        </div>
    </div>

    <div
        v-else-if="block.typ === 'spalten'"
        class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4"
    >
        <div
            v-for="(column, k) in block.eintraege"
            :key="k"
            class="rounded-2xl px-5 py-4"
            :class="categoryClasses[column.kategorie].bg"
        >
            <h3
                class="mb-1.5 text-[1.15rem] font-medium"
                :class="categoryClasses[column.kategorie].text"
            >
                {{ column.titel }}
            </h3>
            <p
                v-for="(paragraph, n) in column.absaetze"
                :key="n"
                class="mb-2 last:mb-0"
            >
                {{ paragraph }}
            </p>
        </div>
    </div>

    <figure
        v-else-if="block.typ === 'grafik' && graphics[block.nr]"
        class="m-0"
    >
        <HeroFrame :hero="graphics[block.nr]!" />
        <figcaption
            v-if="graphics[block.nr]!.beschreibung"
            class="mt-2 max-w-[66ch] text-base text-ls-muted"
        >
            {{ graphics[block.nr]!.beschreibung }}
        </figcaption>
    </figure>

    <div
        v-else-if="block.typ === 'box'"
        class="mt-4 rounded-2xl border border-ls-line bg-ls-card px-5 py-4"
    >
        <h3 class="mb-1.5 text-[1.15rem] font-medium">{{ block.titel }}</h3>
        <p
            v-for="(paragraph, n) in block.absaetze"
            :key="n"
            class="mb-4 max-w-[66ch] last:mb-0"
        >
            {{ paragraph }}
        </p>
    </div>
</template>
