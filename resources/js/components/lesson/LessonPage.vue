<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import ClozeModule from '@/components/lesson/ClozeModule.vue';
import FlashcardModule from '@/components/lesson/FlashcardModule.vue';
import HeroFrame from '@/components/lesson/HeroFrame.vue';
import LessonBlock from '@/components/lesson/LessonBlock.vue';
import QuizModule from '@/components/lesson/QuizModule.vue';
import SortModule from '@/components/lesson/SortModule.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useIsDark } from '@/composables/useIsDark';
import type { LessonContent, LessonHero, ModuleAnswer, Palette } from '@/types';

const props = defineProps<{
    content: LessonContent;
    palette: Palette;
    hero: LessonHero | null;
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

const module = computed(() => props.content.module);

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

            <h1
                class="text-[clamp(2rem,6vw,3rem)] font-bold tracking-[-0.02em] text-ls-accent"
            >
                {{ content.meta.titel }}
            </h1>
            <p class="mt-3 mb-0 max-w-[66ch] text-[1.2rem] text-ls-muted">
                {{ content.meta.anleitung }}
            </p>

            <HeroFrame v-if="hero" :hero="hero" />

            <section v-for="(section, k) in content.abschnitte" :key="k">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    {{ section.titel }}
                </h2>
                <LessonBlock
                    v-for="(block, n) in section.bloecke"
                    :key="n"
                    :block="block"
                />
            </section>

            <section v-if="content.probieren">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Probier es aus
                </h2>
                <div class="mt-4 rounded-2xl bg-ls-accent px-6 py-5 text-ls-bg">
                    <p
                        v-for="(experiment, n) in content.probieren.experimente"
                        :key="n"
                        class="mb-2.5"
                    >
                        {{ experiment }}
                    </p>
                    <p v-if="content.probieren.alltagsvergleich" class="m-0">
                        {{ content.probieren.alltagsvergleich }}
                    </p>
                </div>
            </section>

            <section v-if="module.sortieren">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Sortier-Spiel
                </h2>
                <p v-if="module.sortieren.anleitung" class="mb-4">
                    {{ module.sortieren.anleitung }}
                </p>
                <SortModule
                    :data="module.sortieren"
                    @answer="emit('answer', $event)"
                />
            </section>

            <section v-if="module.karten">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">Karteikarten</h2>
                <p v-if="module.karten.anleitung" class="mb-4">
                    {{ module.karten.anleitung }}
                </p>
                <FlashcardModule :data="module.karten" />
            </section>

            <section v-if="module.lueckentext">
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">Lückentext</h2>
                <p v-if="module.lueckentext.anleitung" class="mb-4">
                    {{ module.lueckentext.anleitung }}
                </p>
                <ClozeModule
                    :data="module.lueckentext"
                    @answer="emit('answer', $event)"
                />
            </section>

            <section>
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Teste dich selbst
                </h2>
                <QuizModule
                    :questions="module.quiz"
                    @answer="emit('answer', $event)"
                />
            </section>

            <section>
                <h2 class="mt-12 mb-3 text-[1.6rem] font-bold">
                    Zum Nachdenken
                </h2>
                <p class="max-w-[66ch]">{{ content.nachdenken.frage }}</p>
            </section>

            <footer
                class="mt-12 border-t border-ls-line pt-4 text-[0.95rem] text-ls-muted"
            >
                Lernseite für die {{ level }} · {{ subject }}
            </footer>
        </main>
    </div>
</template>
