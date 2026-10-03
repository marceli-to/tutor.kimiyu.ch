<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import MathText from '@/components/lesson/MathText.vue';
import { checkAnswer } from '@/lib/lesson';
import type { ClozeModuleData, ModuleAnswer } from '@/types';

const props = defineProps<{
	data: ClozeModuleData;
}>();

const emit = defineEmits<{
	answer: [answer: ModuleAnswer];
}>();

const gaps = computed(() =>
	props.data.segments.flatMap((s) => ('answers' in s ? [s] : [])),
);

const values = reactive<Record<string, string>>({});
const states = reactive<
	Record<string, 'right' | 'almost' | 'wrong' | undefined>
>({});
// Gaps right except for the accents, with the expected spelling
const almost = ref<{ id: string; solution: string }[]>([]);
const error = ref('');
const result = ref<number | null>(null);

function gapNumber(id: string): number {
	return gaps.value.findIndex((g) => g.id === id) + 1;
}

function edited(id: string) {
	states[id] = undefined;
	almost.value = almost.value.filter((a) => a.id !== id);
	error.value = '';
}

function check() {
	if (gaps.value.every((g) => !(values[g.id] ?? '').trim())) {
		error.value = 'Füll zuerst mindestens eine Lücke aus.';

		return;
	}

	let right = 0;
	almost.value = [];

	for (const gap of gaps.value) {
		const value = values[gap.id] ?? '';
		const { result, solution } = checkAnswer(
			value,
			gap.answers,
			props.data.case_sensitive === true,
		);

		states[gap.id] = result;

		if (result === 'right') {
			right++;
		}

		if (result === 'almost') {
			almost.value.push({ id: gap.id, solution });
		}

		if (value.trim() !== '') {
			emit('answer', {
				module: 'cloze',
				itemId: gap.id,
				answer: value,
				// The server decides and counts «almost» as not correct
				correct: result === 'right',
			});
		}
	}

	result.value = right;
}

function showSolution() {
	for (const gap of gaps.value) {
		values[gap.id] = gap.answers[0];
		states[gap.id] = 'right';
	}

	almost.value = [];
	result.value = null;
}

function clear() {
	for (const gap of gaps.value) {
		values[gap.id] = '';
		states[gap.id] = undefined;
	}

	almost.value = [];
	result.value = null;
}

function inputClass(id: string): string {
	return {
		right: 'border-ls-ok bg-ls-ok-bg',
		almost: 'border-ls-accent bg-ls-accent-bg',
		wrong: 'border-ls-bad bg-ls-bad-bg',
		none: 'border-ls-line bg-ls-bg',
	}[states[id] ?? 'none'];
}
</script>

<template>
	<div class="ls-panel">
		<p v-if="data.case_sensitive" class="mb-2 text-[0.95rem] text-ls-muted">
			Gross- und Kleinschreibung zählt.
		</p>
		<p class="leading-[2.3]">
			<template v-for="(segment, k) in data.segments" :key="k">
				<input
					v-if="'answers' in segment"
					v-model="values[segment.id]"
					type="text"
					class="w-[9em] rounded-lg border-[1.5px] px-2 py-0.5 text-base text-ls-ink"
					:class="inputClass(segment.id)"
					:aria-label="`Lücke ${gapNumber(segment.id)}`"
					:aria-invalid="
						(states[segment.id] &&
							states[segment.id] !== 'right') ||
						undefined
					"
					autocomplete="off"
					autocapitalize="off"
					spellcheck="false"
					@input="edited(segment.id)"
					@keydown.enter.prevent="check"
				/>
				<MathText v-else :text="segment.text" />
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
						: 'Markierte Lücken nochmals versuchen.'
				}}
				<p v-for="gap in almost" :key="gap.id" class="mt-1.5 mb-0">
					Lücke {{ gapNumber(gap.id) }}: Fast – achte auf den Akzent:
					<strong>{{ gap.solution }}</strong>
				</p>
			</div>
		</div>
	</div>
</template>
