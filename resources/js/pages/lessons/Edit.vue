<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, reactive, toRaw } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import EditField from '@/components/lesson/EditField.vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { words } from '@/lib/mistake';
import { dashboard } from '@/routes';
import { edit, show, update } from '@/routes/lessons';
import type { CategoryId, Exercise, LessonContent } from '@/types';

const props = defineProps<{
	lesson: {
		id: number;
		status: string;
		childName: string;
		content: LessonContent;
	};
	clozeMarkup: string | null;
	// Only for lessons with photos: what the AI added is marked
	showOrigin: boolean;
	palettes: { value: string; label: string; accent: string }[];
	// Short text per graphic (description, idea or wish), by number
	graphicLabels: Partial<Record<number, string>>;
}>();

setLayoutProps({
	breadcrumbs: [
		{ title: 'Übersicht', href: dashboard() },
		{ title: 'Bearbeiten', href: edit(props.lesson.id) },
	],
});

const form = useForm({
	// Props are reactive proxies; structuredClone can't copy them
	content: JSON.parse(JSON.stringify(props.lesson.content)) as LessonContent,
	clozeMarkup: props.clozeMarkup ?? '',
});

const c = computed(() => form.content);
const errors = computed(
	() => form.errors as Record<string, string | undefined>,
);

function err(path: string): string | undefined {
	return errors.value[`content.${path}`];
}

// Paragraphs are edited as one text field, separated by a blank line
function joinParagraphs(paragraphs: string[]): string {
	return paragraphs.join('\n\n');
}

function splitParagraphs(value: string | null | undefined): string[] {
	return (value ?? '')
		.split(/\n\s*\n/)
		.map((p) => p.trim())
		.filter(Boolean);
}

// Stable keys for blocks, even when one is removed (splice). Only in the browser,
// so the content stays unchanged for the server.
const blockKeys = new WeakMap<object, number>();
let nextBlockKey = 0;

function blockKey(block: object): number {
	const raw = toRaw(block);
	let key = blockKeys.get(raw);

	if (key === undefined) {
		key = nextBlockKey++;
		blockKeys.set(raw, key);
	}

	return key;
}

function newId(prefix: string): string {
	return prefix + Date.now().toString(36).slice(-6);
}

const categories: { value: CategoryId; label: string }[] = [
	{ value: 'cat1', label: 'Farbe 1' },
	{ value: 'cat2', label: 'Farbe 2' },
	{ value: 'cat3', label: 'Farbe 3' },
];

function addQuestion() {
	c.value.modules.quiz?.push({
		id: newId('q'),
		question: '',
		options: ['', '', '', ''],
		answer: 0,
		hint: '',
		explanation: '',
	});
}

function addTerm() {
	const sort = c.value.modules.sorting;

	sort?.terms.push({
		id: newId('s'),
		text: '',
		category: sort.categories[0]?.id ?? 'cat1',
		explanation: '',
	});
}

const exerciseKinds: { value: Exercise['kind']; label: string }[] = [
	{ value: 'number', label: 'Zahl' },
	{ value: 'fraction', label: 'Bruch' },
	{ value: 'text', label: 'Wort' },
];

const texHint = 'Formeln in TeX zwischen $…$, z. B. $\\frac{3}{4}$.';

function addExercise() {
	c.value.modules.exercises?.entries.push({
		id: newId('a'),
		question: '',
		kind: 'number',
		answer: '',
		tolerance: null,
		unit: null,
		hint: null,
		solution_path: '',
	});
}

// The tolerance as typed («0,»), so a half-typed number isn't overwritten while typing
const toleranceInputs = reactive<Record<string, string>>(
	Object.fromEntries(
		(c.value.modules.exercises?.entries ?? []).map((exercise) => [
			exercise.id,
			exercise.tolerance?.toString() ?? '',
		]),
	),
);

// Empty field: no tolerance; a decimal comma is fine
function setTolerance(exercise: Exercise, value: string | null | undefined) {
	const input = (value ?? '').trim();
	const number = Number(input.replace(',', '.'));

	toleranceInputs[exercise.id] = value ?? '';
	exercise.tolerance = input === '' || Number.isNaN(number) ? null : number;
}

function addMistake() {
	c.value.modules.find_the_mistake?.entries.push({
		id: newId('f'),
		sentence: '',
		mistake_word: 0,
		correction: '',
		explanation: '',
	});
}

function addCard() {
	c.value.modules.flashcards?.entries.push({
		id: newId('k'),
		front: '',
		back: '',
	});
}

const errorCount = computed(() => Object.keys(form.errors).length);

function save() {
	form.put(update(props.lesson.id).url, { preserveScroll: true });
}
</script>

<template>
	<Head title="Lernseite bearbeiten" />

	<form
		class="mx-auto w-full max-w-3xl space-y-10 p-4 pb-0 md:p-6 md:pb-0"
		@submit.prevent="save"
	>
		<Heading
			:title="lesson.content.meta.title"
			description="Korrigiere Texte, Quizfragen und Lösungen. Die Änderungen gelten sofort, auch wenn die Seite schon freigegeben ist."
		/>

		<section class="space-y-4">
			<h2 class="text-lg font-semibold">Kopf</h2>
			<EditField
				v-model="c.meta.title"
				label="Titel"
				:error="err('meta.title')"
			/>
			<EditField
				v-model="c.meta.instructions"
				label="Anleitung zur Grafik"
				:error="err('meta.instructions')"
			/>
			<div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
				<EditField
					v-model="c.meta.emoji"
					label="Emoji"
					:error="err('meta.emoji')"
				/>
				<div class="grid gap-1.5">
					<Label for="palette">Farben</Label>
					<select
						id="palette"
						v-model="c.meta.palette"
						class="h-9 w-full rounded-md border border-input bg-transparent pr-9 pl-3 text-sm shadow-xs"
					>
						<option
							v-for="p in palettes"
							:key="p.value"
							:value="p.value"
						>
							{{ p.label }}
						</option>
					</select>
				</div>
			</div>
		</section>

		<section class="space-y-6">
			<h2 class="text-lg font-semibold">Erklärungen</h2>
			<div
				v-for="(section, s) in c.sections"
				:key="s"
				class="space-y-4 rounded-xl border p-4"
			>
				<EditField
					v-model="section.title"
					label="Überschrift"
					:error="err(`sections.${s}.title`)"
				/>

				<div
					v-for="(block, b) in section.blocks"
					:key="blockKey(block)"
					class="space-y-3 border-l-2 pl-4"
				>
					<div
						v-if="showOrigin && block.origin === 'added'"
						class="flex justify-end"
					>
						<OriginBadge :origin="block.origin" variant="app" />
					</div>
					<InputError :message="err(`sections.${s}.blocks.${b}`)" />

					<EditField
						v-if="block.type === 'paragraph'"
						v-model="block.text"
						label="Absatz"
						multiline
					/>

					<template v-else-if="block.type === 'formula'">
						<EditField
							v-model="block.text"
							label="Formel oder Merksatz"
						/>
						<EditField
							v-model="block.addendum"
							label="Zusatz (klein darunter)"
						/>
					</template>

					<template v-else-if="block.type === 'facts'">
						<div
							v-for="(fact, f) in block.entries"
							:key="f"
							class="space-y-2"
						>
							<EditField
								v-model="fact.title"
								:label="`Fakt ${f + 1}: Titel`"
							/>
							<EditField
								v-model="fact.text"
								:label="`Fakt ${f + 1}: Text`"
								multiline
							/>
						</div>
					</template>

					<template v-else-if="block.type === 'columns'">
						<div
							v-for="(column, k) in block.entries"
							:key="k"
							class="space-y-2"
						>
							<EditField
								v-model="column.title"
								:label="`Spalte ${k + 1}: Titel`"
							/>
							<EditField
								:model-value="joinParagraphs(column.paragraphs)"
								:label="`Spalte ${k + 1}: Text`"
								hint="Neuer Absatz: eine Leerzeile."
								multiline
								:rows="4"
								@update:model-value="
									column.paragraphs = splitParagraphs($event)
								"
							/>
						</div>
					</template>

					<template v-else-if="block.type === 'box'">
						<EditField
							v-model="block.title"
							label="Kasten: Titel"
						/>
						<EditField
							:model-value="joinParagraphs(block.paragraphs)"
							label="Kasten: Text"
							hint="Neuer Absatz: eine Leerzeile."
							multiline
							:rows="4"
							@update:model-value="
								block.paragraphs = splitParagraphs($event)
							"
						/>
					</template>

					<template v-else-if="block.type === 'vocabulary'">
						<EditField
							v-model="block.title"
							label="Wortliste: Titel (optional)"
						/>
						<div class="space-y-2">
							<div
								class="hidden gap-2 text-sm font-medium sm:grid sm:grid-cols-[1fr_1fr_1fr_auto]"
							>
								<span>Fremdsprache</span>
								<span>Deutsch</span>
								<span>Zusatz (optional)</span>
								<span class="w-9" />
							</div>
							<div
								v-for="(entry, k) in block.entries"
								:key="k"
								class="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]"
							>
								<Input
									v-model="entry.foreign"
									:aria-label="`Wort ${k + 1}: Fremdsprache`"
									placeholder="Fremdsprache"
								/>
								<Input
									v-model="entry.german"
									:aria-label="`Wort ${k + 1}: Deutsch`"
									placeholder="Deutsch"
								/>
								<Input
									:model-value="entry.info ?? ''"
									:aria-label="`Wort ${k + 1}: Zusatz`"
									placeholder="z. B. m., Verb"
									@update:model-value="
										entry.info = String($event) || null
									"
								/>
								<Button
									type="button"
									variant="ghost"
									size="icon"
									class="text-destructive"
									:aria-label="`Wort ${k + 1} entfernen`"
									:disabled="block.entries.length <= 4"
									@click="block.entries.splice(k, 1)"
								>
									<Trash2 class="size-4" aria-hidden="true" />
								</Button>
							</div>
						</div>
						<Button
							type="button"
							variant="outline"
							size="sm"
							:disabled="block.entries.length >= 30"
							@click="
								block.entries.push({
									foreign: '',
									german: '',
									info: null,
								})
							"
						>
							<Plus class="size-4" aria-hidden="true" />
							Wort hinzufügen
						</Button>
					</template>

					<template v-else-if="block.type === 'conjugation'">
						<div class="grid gap-2 sm:grid-cols-2">
							<EditField v-model="block.verb" label="Verb" />
							<EditField v-model="block.tense" label="Zeitform" />
						</div>
						<div class="space-y-2">
							<div
								v-for="(row, k) in block.forms"
								:key="k"
								class="grid grid-cols-[8rem_1fr] gap-2"
							>
								<Input
									v-model="row.person"
									:aria-label="`Zeile ${k + 1}: Person`"
								/>
								<Input
									v-model="row.form"
									:aria-label="`Zeile ${k + 1}: Form`"
								/>
							</div>
						</div>
					</template>

					<template v-else-if="block.type === 'worked_solution'">
						<EditField
							v-model="block.task"
							label="Beispiel: Aufgabe"
							:hint="texHint"
						/>
						<div class="space-y-2">
							<div
								v-for="(step, k) in block.steps"
								:key="k"
								class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
							>
								<EditField
									v-model="step.text"
									:label="`Schritt ${k + 1}`"
									multiline
									:rows="2"
								/>
								<EditField
									:model-value="step.reason"
									label="Warum (optional)"
									multiline
									:rows="2"
									@update:model-value="
										step.reason = $event || null
									"
								/>
								<Button
									type="button"
									variant="ghost"
									size="icon"
									class="text-destructive sm:mt-6"
									:aria-label="`Schritt ${k + 1} entfernen`"
									:disabled="block.steps.length <= 2"
									@click="block.steps.splice(k, 1)"
								>
									<Trash2 class="size-4" aria-hidden="true" />
								</Button>
							</div>
						</div>
						<Button
							type="button"
							variant="outline"
							size="sm"
							:disabled="block.steps.length >= 8"
							@click="
								block.steps.push({ text: '', reason: null })
							"
						>
							<Plus class="size-4" aria-hidden="true" />
							Schritt hinzufügen
						</Button>
						<EditField
							v-model="block.result"
							label="Beispiel: Ergebnis"
						/>
					</template>

					<div v-else-if="block.type === 'graphic'" class="space-y-1">
						<p class="text-sm font-medium">
							Grafik {{ block.number
							}}<template v-if="graphicLabels[block.number]"
								>: {{ graphicLabels[block.number] }}</template
							>
						</p>
						<p class="text-sm text-muted-foreground">
							Entfernen blendet die Grafik aus. Mit «Grafik neu
							erstellen» kommt sie zurück.
						</p>
					</div>

					<div v-else-if="block.type === 'figure'" class="space-y-1">
						<p class="text-sm font-medium">
							Figur<template v-if="block.title"
								>: {{ block.title }}</template
							>
						</p>
						<p class="text-sm text-muted-foreground">
							Die Figur lässt sich hier nicht bearbeiten, nur
							entfernen.
						</p>
					</div>

					<Button
						type="button"
						variant="ghost"
						size="sm"
						class="text-destructive"
						:disabled="section.blocks.length <= 1"
						@click="section.blocks.splice(b, 1)"
					>
						<Trash2 class="size-4" aria-hidden="true" />
						Baustein entfernen
					</Button>
				</div>
				<InputError :message="err(`sections.${s}.blocks`)" />
			</div>
		</section>

		<section v-if="c.try_it" class="space-y-4">
			<h2 class="text-lg font-semibold">Probier es aus</h2>
			<EditField
				v-for="(experiment, e) in c.try_it.experiments"
				:key="e"
				v-model="c.try_it.experiments[e]"
				:label="`Experiment ${e + 1}`"
				:error="err(`try_it.experiments.${e}`)"
				multiline
			/>
			<EditField
				v-model="c.try_it.everyday_comparison"
				label="Alltagsvergleich"
				multiline
			/>
		</section>

		<!-- No quiz, no editor; adding a quiz doesn't belong here -->
		<section v-if="c.modules.quiz" class="space-y-4">
			<h2 class="text-lg font-semibold">Quiz</h2>
			<InputError :message="err('modules.quiz')" />
			<fieldset
				v-for="(question, q) in c.modules.quiz"
				:key="question.id"
				class="space-y-3 rounded-xl border p-4"
			>
				<legend class="px-1 text-sm font-medium">
					Frage {{ q + 1 }}
					<OriginBadge
						v-if="showOrigin"
						:origin="question.origin"
						variant="app"
						class="ml-1"
					/>
				</legend>
				<EditField
					v-model="question.question"
					label="Frage"
					:error="err(`modules.quiz.${q}.question`)"
					multiline
					:rows="2"
				/>

				<div class="space-y-2">
					<p class="text-sm font-medium">
						Antworten
						<span class="font-normal text-muted-foreground"
							>(richtige Antwort anwählen)</span
						>
					</p>
					<div
						v-for="(_, o) in question.options"
						:key="o"
						class="flex items-center gap-2"
					>
						<input
							v-model="question.answer"
							type="radio"
							:name="`answer-${question.id}`"
							:value="o"
							:aria-label="`Antwort ${o + 1} ist richtig`"
							class="size-4 accent-primary"
						/>
						<input
							v-model="question.options[o]"
							type="text"
							:aria-label="`Antwort ${o + 1}`"
							:class="[
								'h-9 flex-1 rounded-md border bg-transparent px-3 text-sm shadow-xs',
								question.answer === o
									? 'border-green-600 dark:border-green-500'
									: 'border-input',
							]"
						/>
					</div>
					<InputError
						:message="
							err(`modules.quiz.${q}.options`) ??
							err(`modules.quiz.${q}.answer`)
						"
					/>
				</div>

				<EditField v-model="question.hint" label="Tipp" />
				<EditField
					v-model="question.explanation"
					label="Erklärung nach der Antwort"
					:error="err(`modules.quiz.${q}.explanation`)"
					multiline
					:rows="2"
				/>

				<Button
					type="button"
					variant="ghost"
					size="sm"
					class="text-destructive"
					:disabled="c.modules.quiz.length <= 1"
					@click="c.modules.quiz.splice(q, 1)"
				>
					<Trash2 class="size-4" aria-hidden="true" />
					Frage entfernen
				</Button>
			</fieldset>
			<Button
				type="button"
				variant="outline"
				size="sm"
				:disabled="c.modules.quiz.length >= 10"
				@click="addQuestion"
			>
				<Plus class="size-4" aria-hidden="true" />
				Frage hinzufügen
			</Button>
		</section>

		<section v-if="c.modules.sorting" class="space-y-4">
			<h2 class="text-lg font-semibold">Sortier-Spiel</h2>
			<EditField
				v-model="c.modules.sorting.instructions"
				label="Frage zum Sortieren"
			/>
			<div class="grid gap-3 sm:grid-cols-3">
				<div
					v-for="(category, k) in c.modules.sorting.categories"
					:key="category.id"
					class="space-y-2 rounded-xl border p-3"
				>
					<EditField
						v-model="category.label"
						:label="`Korb ${k + 1}`"
						:error="err(`modules.sorting.categories.${k}.label`)"
					/>
					<EditField v-model="category.sub" label="Untertitel" />
				</div>
			</div>
			<InputError :message="err('modules.sorting.terms')" />
			<div
				v-for="(term, t) in c.modules.sorting.terms"
				:key="term.id"
				class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_12rem_auto] sm:items-start"
			>
				<div class="grid gap-1">
					<EditField
						v-model="term.text"
						:label="`Begriff ${t + 1}`"
						:error="
							err(`modules.sorting.terms.${t}.text`) ??
							err(`modules.sorting.terms.${t}.category`)
						"
					/>
					<div v-if="showOrigin && term.origin === 'added'">
						<OriginBadge :origin="term.origin" variant="app" />
					</div>
				</div>
				<div class="grid gap-1.5">
					<Label :for="`korb-${term.id}`">Gehört in</Label>
					<select
						:id="`korb-${term.id}`"
						v-model="term.category"
						class="h-9 rounded-md border border-input bg-transparent pr-9 pl-3 text-sm shadow-xs"
					>
						<option
							v-for="category in c.modules.sorting.categories"
							:key="category.id"
							:value="category.id"
						>
							{{
								category.label ||
								categories.find((x) => x.value === category.id)
									?.label
							}}
						</option>
					</select>
				</div>
				<Button
					type="button"
					variant="ghost"
					size="icon"
					class="text-destructive sm:mt-6"
					:aria-label="`Begriff ${t + 1} entfernen`"
					@click="c.modules.sorting.terms.splice(t, 1)"
				>
					<Trash2 class="size-4" aria-hidden="true" />
				</Button>
				<div class="sm:col-span-3">
					<EditField
						v-model="term.explanation"
						label="Erklärung bei falscher Antwort (optional)"
					/>
				</div>
			</div>
			<Button type="button" variant="outline" size="sm" @click="addTerm">
				<Plus class="size-4" aria-hidden="true" />
				Begriff hinzufügen
			</Button>
		</section>

		<section v-if="c.modules.flashcards" class="space-y-4">
			<h2 class="text-lg font-semibold">Karteikarten</h2>
			<InputError :message="err('modules.flashcards.entries')" />
			<div
				v-for="(card, k) in c.modules.flashcards.entries"
				:key="card.id"
				class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_2fr_auto] sm:items-start"
			>
				<div class="grid gap-1">
					<EditField
						v-model="card.front"
						label="Vorderseite"
						:error="err(`modules.flashcards.entries.${k}.front`)"
					/>
					<div v-if="showOrigin && card.origin === 'added'">
						<OriginBadge :origin="card.origin" variant="app" />
					</div>
				</div>
				<EditField
					v-model="card.back"
					label="Rückseite"
					:error="err(`modules.flashcards.entries.${k}.back`)"
					multiline
					:rows="2"
				/>
				<Button
					type="button"
					variant="ghost"
					size="icon"
					class="text-destructive sm:mt-6"
					:aria-label="`Karte ${k + 1} entfernen`"
					@click="c.modules.flashcards.entries.splice(k, 1)"
				>
					<Trash2 class="size-4" aria-hidden="true" />
				</Button>
			</div>
			<Button type="button" variant="outline" size="sm" @click="addCard">
				<Plus class="size-4" aria-hidden="true" />
				Karte hinzufügen
			</Button>
		</section>

		<section v-if="c.modules.exercises" class="space-y-4">
			<h2 class="text-lg font-semibold">Aufgaben</h2>
			<p class="text-sm text-muted-foreground">
				{{ texHint }} Die Lösung ohne Einheit; das Kind darf die Einheit
				weglassen. Toleranz für gerundete Ergebnisse, z. B. 0,05.
			</p>
			<EditField
				v-model="c.modules.exercises.instructions"
				label="Anleitung (optional)"
				:error="err('modules.exercises.instructions')"
			/>
			<InputError :message="err('modules.exercises.entries')" />
			<div
				v-for="(exercise, k) in c.modules.exercises.entries"
				:key="exercise.id"
				class="space-y-3 rounded-xl border p-3"
			>
				<EditField
					v-model="exercise.question"
					:label="`Aufgabe ${k + 1}`"
					multiline
					:rows="2"
					:error="err(`modules.exercises.entries.${k}.question`)"
				/>
				<div class="grid gap-2 sm:grid-cols-4">
					<div class="grid gap-1.5">
						<Label :for="`kind-${exercise.id}`">Lösung ist</Label>
						<select
							:id="`kind-${exercise.id}`"
							v-model="exercise.kind"
							class="h-9 w-full rounded-md border border-input bg-transparent pr-9 pl-3 text-sm shadow-xs"
						>
							<option
								v-for="kind in exerciseKinds"
								:key="kind.value"
								:value="kind.value"
							>
								{{ kind.label }}
							</option>
						</select>
					</div>
					<EditField
						v-model="exercise.answer"
						label="Lösung"
						:error="err(`modules.exercises.entries.${k}.answer`)"
					/>
					<EditField
						:model-value="exercise.unit"
						label="Einheit (optional)"
						:error="err(`modules.exercises.entries.${k}.unit`)"
						@update:model-value="exercise.unit = $event || null"
					/>
					<EditField
						:model-value="toleranceInputs[exercise.id] ?? ''"
						label="Toleranz (optional)"
						:error="err(`modules.exercises.entries.${k}.tolerance`)"
						@update:model-value="setTolerance(exercise, $event)"
					/>
				</div>
				<EditField
					:model-value="exercise.hint"
					label="Tipp (optional)"
					:error="err(`modules.exercises.entries.${k}.hint`)"
					@update:model-value="exercise.hint = $event || null"
				/>
				<EditField
					v-model="exercise.solution_path"
					label="Lösungsweg"
					multiline
					:rows="2"
					:error="err(`modules.exercises.entries.${k}.solution_path`)"
				/>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					class="text-destructive"
					:disabled="c.modules.exercises.entries.length <= 3"
					@click="c.modules.exercises.entries.splice(k, 1)"
				>
					<Trash2 class="size-4" aria-hidden="true" />
					Aufgabe entfernen
				</Button>
			</div>
			<Button
				type="button"
				variant="outline"
				size="sm"
				:disabled="c.modules.exercises.entries.length >= 8"
				@click="addExercise"
			>
				<Plus class="size-4" aria-hidden="true" />
				Aufgabe hinzufügen
			</Button>
		</section>

		<section v-if="c.modules.find_the_mistake" class="space-y-4">
			<h2 class="text-lg font-semibold">Fehler finden</h2>
			<p class="text-sm text-muted-foreground">
				Pro Satz genau ein falsches Wort. Tippe im Satz das falsche Wort
				an und schreib die Korrektur so, wie sie im Satz stehen muss.
			</p>
			<EditField
				v-model="c.modules.find_the_mistake.instructions"
				label="Anleitung (optional)"
				:error="err('modules.find_the_mistake.instructions')"
			/>
			<InputError :message="err('modules.find_the_mistake.entries')" />
			<div
				v-for="(mistake, k) in c.modules.find_the_mistake.entries"
				:key="mistake.id"
				class="space-y-3 rounded-xl border p-3"
			>
				<EditField
					v-model="mistake.sentence"
					:label="`Satz ${k + 1}`"
					:error="
						err(`modules.find_the_mistake.entries.${k}.sentence`)
					"
				/>
				<div class="grid gap-1.5">
					<span class="text-sm font-medium">Falsches Wort</span>
					<div
						class="flex flex-wrap gap-1"
						role="group"
						:aria-label="`Falsches Wort in Satz ${k + 1}`"
					>
						<Button
							v-for="(word, w) in words(mistake.sentence)"
							:key="w"
							type="button"
							size="sm"
							:variant="
								mistake.mistake_word === w
									? 'default'
									: 'outline'
							"
							:aria-pressed="mistake.mistake_word === w"
							@click="mistake.mistake_word = w"
						>
							{{ word }}
						</Button>
					</div>
					<InputError
						:message="
							err(
								`modules.find_the_mistake.entries.${k}.mistake_word`,
							)
						"
					/>
				</div>
				<EditField
					v-model="mistake.correction"
					label="Korrektur"
					:error="
						err(`modules.find_the_mistake.entries.${k}.correction`)
					"
				/>
				<EditField
					v-model="mistake.explanation"
					label="Erklärung"
					multiline
					:rows="2"
					:error="
						err(`modules.find_the_mistake.entries.${k}.explanation`)
					"
				/>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					class="text-destructive"
					:disabled="c.modules.find_the_mistake.entries.length <= 4"
					@click="c.modules.find_the_mistake.entries.splice(k, 1)"
				>
					<Trash2 class="size-4" aria-hidden="true" />
					Satz entfernen
				</Button>
			</div>
			<Button
				type="button"
				variant="outline"
				size="sm"
				:disabled="c.modules.find_the_mistake.entries.length >= 8"
				@click="addMistake"
			>
				<Plus class="size-4" aria-hidden="true" />
				Satz hinzufügen
			</Button>
		</section>

		<section v-if="c.modules.cloze" class="space-y-4">
			<h2 class="text-lg font-semibold">
				Lückentext
				<OriginBadge
					v-if="showOrigin"
					:origin="c.modules.cloze.origin"
					variant="app"
					class="ml-1"
				/>
			</h2>
			<EditField
				v-model="form.clozeMarkup"
				label="Text mit Lücken"
				hint="Lücken in eckigen Klammern, andere richtige Schreibweisen mit | trennen: [Kohlenstoffdioxid|CO2]"
				multiline
				:rows="6"
				:error="errors.clozeMarkup ?? err('modules.cloze.segments')"
			/>
			<label
				v-if="'case_sensitive' in c.modules.cloze"
				class="flex cursor-pointer items-center gap-3 text-sm"
			>
				<input
					v-model="c.modules.cloze.case_sensitive"
					type="checkbox"
					class="size-4 accent-primary"
				/>
				Gross- und Kleinschreibung zählt
			</label>
		</section>

		<section class="space-y-4">
			<h2 class="text-lg font-semibold">Zum Nachdenken</h2>
			<EditField
				v-model="c.reflect.question"
				label="Frage"
				:error="err('reflect.question')"
				multiline
				:rows="2"
			/>
		</section>

		<div
			class="sticky bottom-0 z-10 -mx-4 border-t bg-background/95 backdrop-blur md:-mx-6"
		>
			<div
				class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-3 md:px-6"
			>
				<Button type="submit" :disabled="form.processing">
					<Spinner v-if="form.processing" />
					Speichern
				</Button>
				<Button variant="outline" as-child>
					<Link :href="show(lesson.id)">Zur Lernseite</Link>
				</Button>
				<span
					v-if="errorCount"
					class="text-sm text-destructive"
					role="alert"
				>
					{{
						errorCount === 1
							? 'Ein Feld braucht'
							: `${errorCount} Felder brauchen`
					}}
					noch eine Korrektur.
				</span>
				<span
					v-else-if="form.isDirty"
					class="text-sm text-muted-foreground"
				>
					Ungespeicherte Änderungen
				</span>
			</div>
		</div>
	</form>
</template>
