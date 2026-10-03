<script setup lang="ts">
import { computed, ref } from 'vue';
import MathText from '@/components/lesson/MathText.vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import SpeakButton from '@/components/lesson/SpeakButton.vue';
import { shuffle } from '@/lib/lesson';
import type { FlashcardModuleData } from '@/types';

const props = defineProps<{
	data: FlashcardModuleData;
	// Languages lesson: «Deutsch zuerst» shows the back (German) first
	reversible?: boolean;
	// Reads the front (the foreign word) aloud
	speechLang?: string | null;
	// Original text → ElevenLabs clip
	speechClips?: Record<string, string>;
}>();

const deck = ref(props.data.entries.slice());
const index = ref(0);
const back = ref(false);
// Only for this page view
const germanFirst = ref(false);

const card = computed(() => deck.value[index.value]);
// Whether the front (foreign word) is the side shown right now
const showsFront = computed(() => back.value === germanFirst.value);

function go(step: number) {
	index.value = (index.value + step + deck.value.length) % deck.value.length;
	back.value = false;
}

function mix() {
	deck.value = shuffle(deck.value);
	index.value = 0;
	back.value = false;
}

function toggleGermanFirst() {
	germanFirst.value = !germanFirst.value;
	back.value = false;
}
</script>

<template>
	<div class="ls-panel">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<p class="ls-progress">
				Karte {{ index + 1 }} von {{ deck.length }}
			</p>
			<label
				v-if="reversible"
				class="mb-3 inline-flex cursor-pointer items-center gap-2 text-[0.95rem] text-ls-muted"
			>
				<input
					type="checkbox"
					class="size-4 accent-ls-accent"
					:checked="germanFirst"
					@change="toggleGermanFirst"
				/>
				Deutsch zuerst
			</label>
		</div>
		<div class="relative">
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
				<span
					:class="
						showsFront
							? 'font-display text-2xl font-bold'
							: 'text-[1.1rem]'
					"
					><MathText :text="showsFront ? card.front : card.back"
				/></span>
				<template v-if="!back">
					<OriginBadge :origin="card.origin" class="ml-1" />
					<br />
					<small class="text-ls-muted">Tippen zum Umdrehen</small>
				</template>
			</button>
			<!-- Next to the card, not inside it: a button can't contain a button -->
			<SpeakButton
				v-if="speechLang && showsFront"
				:text="card.front"
				:lang="speechLang"
				:src="speechClips?.[card.front]"
				class="absolute top-2 right-2"
			/>
		</div>
		<div class="mt-4 flex flex-wrap items-center gap-2.5">
			<button type="button" class="ls-btn" @click="go(-1)">Zurück</button>
			<button type="button" class="ls-btn ls-btn-primary" @click="go(1)">
				Weiter
			</button>
			<button type="button" class="ls-btn" @click="mix">Mischen</button>
		</div>
	</div>
</template>
