<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import ClozeModule from '@/components/lesson/ClozeModule.vue';
import FlashcardModule from '@/components/lesson/FlashcardModule.vue';
import GraphicFrame from '@/components/lesson/GraphicFrame.vue';
import LessonBlock from '@/components/lesson/LessonBlock.vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import QuizModule from '@/components/lesson/QuizModule.vue';
import SortModule from '@/components/lesson/SortModule.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useIsDark } from '@/composables/useIsDark';
import type {
    LessonContent,
    LessonGraphics,
    ModuleAnswer,
    Palette,
} from '@/types';

const props = defineProps<{
    content: LessonContent;
    palette: Palette;
    graphics: LessonGraphics;
    subject: string;
    level: string;
}>();

const emit = defineEmits<{
    answer: [answer: ModuleAnswer];
}>();

const isDark = useIsDark();
const { updateAppearance } = useAppearance();

// Themenfarben für hell und dunkel, lesson.css wählt die passende Variante
const paletteStyle = computed(() => {
    const vars: Record<string, string> = {};

    for (const mode of ['light', 'dark'] as const) {
        for (const [name, value] of Object.entries(props.palette[mode])) {
            vars[`--p-${name}-${mode}`] = value;
        }
    }

    return vars;
});

const modules = computed(() => props.content.modules);

function toggleTheme() {
    updateAppearance(isDark.value ? 'light' : 'dark');
}
</script>

<template>
    <div class="lesson min-h-screen" :style="paletteStyle">
        <main class="mx-auto max-w-[780px] px-5 pt-10 pb-16">
            <div class="flex justify-end">
                <button
                    type="button"
                    class="-mt-4 mb-2 cursor-pointer rounded-full p-2 text-ls-muted hover:text-ls-ink"
                    :aria-label="isDark ? 'Helles Design' : 'Dunkles Design'"
                    @click="toggleTheme"
                >
                    <Sun v-if="isDark" class="size-5" aria-hidden="true" />
                    <Moon v-else class="size-5" aria-hidden="true" />
                </button>
            </div>

            <slot name="before" />

            <h1
                class="text-[clamp(2rem,6vw,3rem)] font-bold tracking-[-0.02em] text-ls-accent"
            >
                {{ content.meta.title }}
            </h1>
            <p class="mt-3 mb-0 max-w-[66ch] text-[1.2rem] text-ls-muted">
                {{ content.meta.instructions }}
            </p>

            <GraphicFrame v-if="graphics[1]" :graphic="graphics[1]" />

            <section v-for="(section, k) in content.sections" :key="k">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    {{ section.title }}
                </h2>
                <LessonBlock
                    v-for="(block, n) in section.blocks"
                    :key="n"
                    :block="block"
                    :graphics="graphics"
                />
            </section>

            <section v-if="content.try_it">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Probier es aus
                </h2>
                <div class="mt-4 rounded-2xl bg-ls-accent px-6 py-5 text-ls-bg">
                    <p
                        v-for="(experiment, n) in content.try_it.experiments"
                        :key="n"
                        class="mb-2.5"
                    >
                        {{ experiment }}
                    </p>
                    <p v-if="content.try_it.everyday_comparison" class="m-0">
                        {{ content.try_it.everyday_comparison }}
                    </p>
                </div>
            </section>

            <section v-if="modules.sorting">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Sortier-Spiel
                </h2>
                <p v-if="modules.sorting.instructions" class="mb-4">
                    {{ modules.sorting.instructions }}
                </p>
                <SortModule
                    :data="modules.sorting"
                    @answer="emit('answer', $event)"
                />
            </section>

            <section v-if="modules.flashcards">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">Karteikarten</h2>
                <p v-if="modules.flashcards.instructions" class="mb-4">
                    {{ modules.flashcards.instructions }}
                </p>
                <FlashcardModule :data="modules.flashcards" />
            </section>

            <section v-if="modules.cloze">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Lückentext
                    <OriginBadge :origin="modules.cloze.origin" class="ml-1" />
                </h2>
                <p v-if="modules.cloze.instructions" class="mb-4">
                    {{ modules.cloze.instructions }}
                </p>
                <ClozeModule
                    :data="modules.cloze"
                    @answer="emit('answer', $event)"
                />
            </section>

            <section v-if="modules.quiz?.length">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Teste dich selbst
                </h2>
                <QuizModule
                    :questions="modules.quiz"
                    @answer="emit('answer', $event)"
                />
            </section>

            <section>
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Zum Nachdenken
                </h2>
                <p class="max-w-[66ch]">{{ content.reflect.question }}</p>
            </section>

            <footer
                class="mt-12 border-t border-ls-line pt-4 text-[0.95rem] text-ls-muted"
            >
                Lernseite für die {{ level }} · {{ subject }}
            </footer>
        </main>
    </div>
</template>
