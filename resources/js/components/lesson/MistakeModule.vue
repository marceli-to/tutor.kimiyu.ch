<script setup lang="ts">
import { nextTick, reactive } from 'vue';
import { bareWord, checkMistake, words } from '@/lib/mistake';
import type { Mistake, MistakeModuleData, ModuleAnswer } from '@/types';

const props = defineProps<{
	data: MistakeModuleData;
}>();

const emit = defineEmits<{
	answer: [answer: ModuleAnswer];
}>();

type State = {
	word: number | null;
	value: string;
	wrong: number;
	result: 'right' | 'wrong' | null;
	// After a wrong answer: was the tapped word the right one?
	rightWord: boolean;
	error: string;
};

const states = reactive<Record<string, State>>(
	Object.fromEntries(
		props.data.entries.map((mistake) => [
			mistake.id,
			{
				word: null,
				value: '',
				wrong: 0,
				result: null,
				rightWord: false,
				error: '',
			},
		]),
	),
);

const inputs: Record<string, HTMLInputElement | null> = {};

async function pick(mistake: Mistake, index: number) {
	const current = states[mistake.id];

	if (current.result === 'right') {
		return;
	}

	current.word = index;
	current.value = bareWord(words(mistake.sentence)[index]);
	current.result = null;
	current.error = '';

	await nextTick();
	inputs[mistake.id]?.focus();
	inputs[mistake.id]?.select();
}

function check(mistake: Mistake) {
	const current = states[mistake.id];

	if (current.word === null) {
		current.error = 'Tipp zuerst das falsche Wort an.';

		return;
	}

	if (current.value.trim() === '') {
		current.error = 'Schreib das Wort richtig.';

		return;
	}

	const correct = checkMistake(mistake, current.word, current.value);
	current.result = correct ? 'right' : 'wrong';
	current.rightWord = current.word === mistake.mistake_word;

	if (!correct) {
		current.wrong++;
	}

	emit('answer', {
		module: 'find_the_mistake',
		itemId: mistake.id,
		answer: { word: current.word, correction: current.value },
		// The server checks the answer itself
		correct,
	});
}

function edited(id: string) {
	states[id].result = null;
	states[id].error = '';
}

// The solution after a right answer or the second wrong one
function showsSolution(id: string): boolean {
	const current = states[id];

	return current.result === 'right' || current.wrong >= 2;
}

function wordClass(mistake: Mistake, index: number): string {
	const current = states[mistake.id];

	if (current.word !== index) {
		return 'border-transparent hover:border-ls-line';
	}

	return {
		right: 'border-ls-ok bg-ls-ok-bg',
		wrong: 'border-ls-bad bg-ls-bad-bg',
		none: 'border-ls-accent bg-ls-accent-bg',
	}[current.result ?? 'none'];
}
</script>

<template>
	<div class="ls-panel">
		<ol class="m-0 grid list-none gap-6 p-0">
			<li v-for="(mistake, k) in data.entries" :key="mistake.id">
				<p
					class="mb-2.5 flex flex-wrap items-baseline gap-x-0.5 gap-y-1 leading-relaxed"
					role="group"
					:aria-label="`Satz ${k + 1}: Tipp das falsche Wort an`"
				>
					<span class="mr-1.5 text-ls-muted">{{ k + 1 }}.</span>
					<button
						v-for="(word, w) in words(mistake.sentence)"
						:key="w"
						type="button"
						class="rounded-md border-[1.5px] px-1 text-ls-ink"
						:class="wordClass(mistake, w)"
						:aria-pressed="states[mistake.id].word === w"
						:disabled="states[mistake.id].result === 'right'"
						@click="pick(mistake, w)"
					>
						{{ word }}
					</button>
				</p>

				<div
					v-if="states[mistake.id].word !== null"
					class="flex flex-wrap items-center gap-2.5"
				>
					<input
						:ref="
							(el) =>
								(inputs[mistake.id] =
									el as HTMLInputElement | null)
						"
						v-model="states[mistake.id].value"
						type="text"
						class="w-[12em] rounded-lg border-[1.5px] px-2 py-1 text-base text-ls-ink"
						:class="{
							'border-ls-ok bg-ls-ok-bg':
								states[mistake.id].result === 'right',
							'border-ls-bad bg-ls-bad-bg':
								states[mistake.id].result === 'wrong',
							'border-ls-line bg-ls-bg':
								states[mistake.id].result === null,
						}"
						:aria-label="`Korrektur zu Satz ${k + 1}`"
						:aria-invalid="
							states[mistake.id].result === 'wrong' || undefined
						"
						:readonly="states[mistake.id].result === 'right'"
						autocomplete="off"
						autocapitalize="off"
						spellcheck="false"
						@input="edited(mistake.id)"
						@keydown.enter.prevent="check(mistake)"
					/>
					<button
						v-if="states[mistake.id].result !== 'right'"
						type="button"
						class="ls-btn ls-btn-primary"
						@click="check(mistake)"
					>
						Prüfen
					</button>
				</div>

				<p
					v-if="states[mistake.id].error"
					class="mt-1.5 mb-0 text-[0.95rem] text-ls-bad"
				>
					{{ states[mistake.id].error }}
				</p>

				<div aria-live="polite">
					<div
						v-if="states[mistake.id].result"
						class="ls-feedback"
						:class="
							states[mistake.id].result === 'right'
								? 'ls-feedback-ok'
								: 'ls-feedback-no'
						"
					>
						<strong>{{
							states[mistake.id].result === 'right'
								? 'Richtig!'
								: 'Noch nicht.'
						}}</strong>
						<template
							v-if="
								states[mistake.id].result === 'wrong' &&
								!showsSolution(mistake.id)
							"
						>
							{{
								states[mistake.id].rightWord
									? 'Das Wort stimmt, aber so ist es noch nicht richtig geschrieben.'
									: 'Der Fehler steckt in einem anderen Wort.'
							}}
						</template>
						<p v-if="showsSolution(mistake.id)" class="mt-1.5 mb-0">
							<template
								v-if="states[mistake.id].result === 'wrong'"
							>
								Richtig ist
								<strong>{{ mistake.correction }}</strong>
								statt
								<strong>{{
									words(mistake.sentence)[
										mistake.mistake_word
									]
								}}</strong
								>.
							</template>
							{{ mistake.explanation }}
						</p>
					</div>
				</div>
			</li>
		</ol>
	</div>
</template>
