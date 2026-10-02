<script setup lang="ts">
import { computed, nextTick, ref, useId } from 'vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import { resultMessage } from '@/lib/lesson';
import type { ModuleAnswer, QuizQuestion } from '@/types';

const props = defineProps<{
	questions: QuizQuestion[];
}>();

const emit = defineEmits<{
	answer: [answer: ModuleAnswer];
}>();

const groupName = useId();
const index = ref(0);
const score = ref(0);
const selected = ref<number | null>(null);
const checked = ref(false);
const hintShown = ref(false);
const error = ref('');
const questionEl = ref<HTMLElement | null>(null);

const finished = computed(() => index.value >= props.questions.length);
const question = computed(() => props.questions[index.value]);
const isCorrect = computed(() => selected.value === question.value?.answer);
const isLast = computed(() => index.value === props.questions.length - 1);

function check() {
	if (selected.value === null) {
		error.value = 'Wähle zuerst eine Antwort.';

		return;
	}

	checked.value = true;

	if (isCorrect.value) {
		score.value++;
	}

	emit('answer', {
		module: 'quiz',
		itemId: question.value.id,
		answer: selected.value,
		correct: isCorrect.value,
	});
}

async function next() {
	index.value++;
	selected.value = null;
	checked.value = false;
	hintShown.value = false;
	error.value = '';

	await nextTick();
	questionEl.value?.focus();
}

function restart() {
	index.value = -1;
	score.value = 0;
	void next();
}

function optionClass(k: number): string {
	if (!checked.value) {
		return 'border-ls-line hover:border-ls-accent';
	}

	if (k === question.value.answer) {
		return 'border-ls-ok bg-ls-ok-bg';
	}

	if (k === selected.value) {
		return 'border-ls-bad bg-ls-bad-bg';
	}

	return 'border-ls-line';
}
</script>

<template>
	<div class="ls-panel" aria-live="polite">
		<template v-if="finished">
			<p class="ls-progress">Geschafft</p>
			<div class="ls-score">{{ score }} / {{ questions.length }}</div>
			<p>{{ resultMessage(score, questions.length) }}</p>
			<div class="mt-4 flex flex-wrap items-center gap-2.5">
				<button
					type="button"
					class="ls-btn ls-btn-primary"
					@click="restart"
				>
					Nochmals versuchen
				</button>
			</div>
		</template>

		<template v-else>
			<p class="ls-progress">
				Frage {{ index + 1 }} von {{ questions.length }}
			</p>
			<p
				:id="`${groupName}-q`"
				ref="questionEl"
				tabindex="-1"
				class="mb-4 font-display text-[1.3rem] font-medium outline-none"
			>
				{{ question.question }}
				<OriginBadge :origin="question.origin" class="ml-1" />
			</p>

			<div
				class="grid gap-2"
				role="radiogroup"
				:aria-labelledby="`${groupName}-q`"
			>
				<label
					v-for="(option, k) in question.options"
					:key="k"
					class="ls-opt flex cursor-pointer items-start gap-3 rounded-xl border-[1.5px] px-3.5 py-2.5"
					:class="optionClass(k)"
				>
					<input
						v-model="selected"
						type="radio"
						class="mt-1.5 accent-ls-accent"
						:name="groupName"
						:value="k"
						:disabled="checked"
						@change="error = ''"
					/>
					<span>{{ option }}</span>
				</label>
			</div>

			<div class="mt-4 flex flex-wrap items-center gap-2.5">
				<button
					v-if="!checked"
					type="button"
					class="ls-btn ls-btn-primary"
					@click="check"
				>
					Antwort prüfen
				</button>
				<button
					v-else
					type="button"
					class="ls-btn ls-btn-primary"
					@click="next"
				>
					{{ isLast ? 'Resultat anzeigen' : 'Nächste Frage' }}
				</button>
				<button
					v-if="question.hint && !checked"
					type="button"
					class="ls-btn"
					@click="hintShown = true"
				>
					Tipp zeigen
				</button>
				<span v-if="error" class="text-[0.95rem] text-ls-bad">
					{{ error }}
				</span>
			</div>

			<p
				v-if="hintShown && question.hint"
				class="mt-3 mb-0 text-base text-ls-muted italic"
			>
				Tipp: {{ question.hint }}
			</p>

			<div
				v-if="checked"
				class="ls-feedback"
				:class="isCorrect ? 'ls-feedback-ok' : 'ls-feedback-no'"
			>
				<strong>{{ isCorrect ? 'Richtig!' : 'Nicht ganz.' }}</strong>
				{{ question.explanation }}
			</div>
		</template>
	</div>
</template>
