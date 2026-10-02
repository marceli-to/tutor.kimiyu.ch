<script setup lang="ts">
import GraphicFrame from '@/components/lesson/GraphicFrame.vue';
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
        v-if="block.origin === 'added'"
        class="mt-4 mb-1 flex justify-end"
        :class="{ 'max-w-[66ch]': block.type === 'paragraph' }"
    >
        <OriginBadge :origin="block.origin" />
    </div>

    <p v-if="block.type === 'paragraph'" class="mb-4 max-w-[66ch]">
        {{ block.text }}
    </p>

    <div
        v-else-if="block.type === 'formula'"
        class="mt-4 overflow-x-auto rounded-[14px] border border-ls-line bg-ls-card px-5 py-4 font-display text-[clamp(1.1rem,3.6vw,1.45rem)] font-medium"
    >
        {{ block.text }}
        <small
            v-if="block.addendum"
            class="mt-1.5 block font-reading text-base text-ls-muted"
        >
            {{ block.addendum }}
        </small>
    </div>

    <div v-else-if="block.type === 'facts'" class="mt-4 grid gap-5">
        <div
            v-for="(fact, k) in block.entries"
            :key="k"
            class="border-l-4 border-ls-accent pl-4"
        >
            <h3 class="mb-1.5 text-[1.15rem] font-medium">{{ fact.title }}</h3>
            <p class="m-0 max-w-[66ch]">{{ fact.text }}</p>
        </div>
    </div>

    <div
        v-else-if="block.type === 'columns'"
        class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4"
    >
        <div
            v-for="(column, k) in block.entries"
            :key="k"
            class="rounded-2xl px-5 py-4"
            :class="categoryClasses[column.category].bg"
        >
            <h3
                class="mb-1.5 text-[1.15rem] font-medium"
                :class="categoryClasses[column.category].text"
            >
                {{ column.title }}
            </h3>
            <p
                v-for="(paragraph, n) in column.paragraphs"
                :key="n"
                class="mb-2 last:mb-0"
            >
                {{ paragraph }}
            </p>
        </div>
    </div>

    <figure
        v-else-if="block.type === 'graphic' && graphics[block.number]"
        class="m-0"
    >
        <GraphicFrame :graphic="graphics[block.number]!" />
        <figcaption
            v-if="graphics[block.number]!.description"
            class="mt-2 max-w-[66ch] text-base text-ls-muted"
        >
            {{ graphics[block.number]!.description }}
        </figcaption>
    </figure>

    <div
        v-else-if="block.type === 'box'"
        class="mt-4 rounded-2xl border border-ls-line bg-ls-card px-5 py-4"
    >
        <h3 class="mb-1.5 text-[1.15rem] font-medium">{{ block.title }}</h3>
        <p
            v-for="(paragraph, n) in block.paragraphs"
            :key="n"
            class="mb-4 max-w-[66ch] last:mb-0"
        >
            {{ paragraph }}
        </p>
    </div>
</template>
