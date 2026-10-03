<script setup lang="ts">
import { reactive } from 'vue';
import MathText from '@/components/lesson/MathText.vue';
import { checkExercise } from '@/lib/exercise';
import type { Exercise, ExerciseModuleData, ModuleAnswer } from '@/types';

const props = defineProps<{
	data: ExerciseModuleData;
}>();

const emit = defineEmits<{
	answer: [answer: ModuleAnswer];
}>();

type State = {
	value: string;
	wrong: number;
	result: 'right' | 'wrong' | null;
	error: string;
};

const states = reactive<Record<string, State>>(
	Object.fromEntries(
		props.data.entries.map((exercise) => [
			exercise.id,
			{ value: '', wrong: 0, result: null, error: '' },
		]),
	),
);

function check(exercise: Exercise) {
	const current = states[exercise.id];

	if (current.value.trim() === '') {
		current.error = 'Gib zuerst eine Antwort ein.';

		return;
	}

	const correct = checkExercise(current.value, exercise);
	current.result = correct ? 'right' : 'wrong';

	if (!correct) {
		current.wrong++;
	}

	emit('answer', {
		module: 'exercises',
		itemId: exercise.id,
		answer: current.value,
		// The server checks the answer itself
		correct,
	});
}

function edited(id: string) {
	states[id].result = null;
	states[id].error = '';
}

// The solution path after a right answer or the second wrong one
function showsSolution(id: string): boolean {
	const current = states[id];

	return current.result === 'right' || current.wrong >= 2;
}
</script>

<template>
	<div class="ls-panel">
		<ol class="m-0 grid list-none gap-6 p-0">
			<li v-for="(exercise, k) in data.entries" :key="exercise.id">
				<p class="mb-2.5 font-medium">
					<span class="text-ls-muted">{{ k + 1 }}.</span>
					<MathText :text="exercise.question" />
				</p>

				<div class="flex flex-wrap items-center gap-2.5">
					<span class="inline-flex items-center gap-2">
						<input
							v-model="states[exercise.id].value"
							type="text"
							inputmode="decimal"
							class="w-[9em] rounded-lg border-[1.5px] px-2 py-1 text-base text-ls-ink"
							:class="{
								'border-ls-ok bg-ls-ok-bg':
									states[exercise.id].result === 'right',
								'border-ls-bad bg-ls-bad-bg':
									states[exercise.id].result === 'wrong',
								'border-ls-line bg-ls-bg':
									states[exercise.id].result === null,
							}"
							:aria-label="`Antwort zu Aufgabe ${k + 1}`"
							:aria-invalid="
								states[exercise.id].result === 'wrong' ||
								undefined
							"
							autocomplete="off"
							autocapitalize="off"
							spellcheck="false"
							@input="edited(exercise.id)"
							@keydown.enter.prevent="check(exercise)"
						/>
						<span v-if="exercise.unit" class="text-ls-muted">{{
							exercise.unit
						}}</span>
					</span>
					<button
						type="button"
						class="ls-btn ls-btn-primary"
						@click="check(exercise)"
					>
						Prüfen
					</button>
					<span
						v-if="states[exercise.id].error"
						class="text-[0.95rem] text-ls-bad"
					>
						{{ states[exercise.id].error }}
					</span>
				</div>

				<div aria-live="polite">
					<div
						v-if="states[exercise.id].result"
						class="ls-feedback"
						:class="
							states[exercise.id].result === 'right'
								? 'ls-feedback-ok'
								: 'ls-feedback-no'
						"
					>
						<strong>{{
							states[exercise.id].result === 'right'
								? 'Richtig!'
								: 'Noch nicht.'
						}}</strong>
						<template
							v-if="
								states[exercise.id].result === 'wrong' &&
								!showsSolution(exercise.id)
							"
						>
							<template v-if="exercise.hint">
								Tipp: <MathText :text="exercise.hint" />
							</template>
							<template v-else>
								Rechne nochmals nach und versuch es erneut.
							</template>
						</template>
						<p
							v-if="showsSolution(exercise.id)"
							class="mt-1.5 mb-0"
						>
							<span v-if="states[exercise.id].result === 'wrong'"
								>So geht es:
							</span>
							<MathText :text="exercise.solution_path" />
						</p>
					</div>
				</div>
			</li>
		</ol>
	</div>
</template>
