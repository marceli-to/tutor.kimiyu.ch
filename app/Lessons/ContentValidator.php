<?php

namespace App\Lessons;

use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the content of a lesson (schema version 1).
 *
 * Non-strict: what a page needs to be shown and edited.
 * Strict: additionally the didactic rules from SKILL.md that apply to freshly generated pages,
 * and with a profile only its blocks, modules and experiments. Non-strict tolerates them, so old content never breaks.
 */
class ContentValidator
{
	public const SCHEMA_VERSION = 1;

	// «graphic» places graphic 2 or 3 in a section; graphic 1 is always at the top.
	// The blocks after it belong to subject profiles (see Profile::blocks()).
	public const BLOCK_TYPES = ['paragraph', 'formula', 'facts', 'columns', 'box', 'graphic', 'vocabulary', 'conjugation', 'worked_solution', 'figure'];

	// «exercises» belongs to the math profile, «find_the_mistake» to german; both are missing on older pages
	public const MODULES = ['quiz', 'sorting', 'flashcards', 'cloze', 'exercises', 'find_the_mistake'];

	public const CATEGORIES = ['cat1', 'cat2', 'cat3'];

	// Origin of a block; missing on old pages
	public const ORIGINS = ['photo', 'added'];

	/**
	 * @param  array<string, mixed>  $content
	 */
	public static function make(array $content, bool $strict = false, ?Profile $profile = null): Validator
	{
		$validator = ValidatorFactory::make($content, self::rules($strict), [], self::attributes());

		$validator->after(function (Validator $validator) use ($content, $strict, $profile) {
			if ($validator->errors()->isNotEmpty()) {
				return;
			}

			(new self($validator, $content, $strict, $profile))->checkConsistency();
		});

		return $validator;
	}

	/**
	 * @param  array<string, mixed>  $content
	 * @return list<string>
	 */
	public static function errors(array $content, bool $strict = false, ?Profile $profile = null): array
	{
		return array_values(self::make($content, $strict, $profile)->errors()->all());
	}

	/**
	 * Errors split by part of the page: «modules» (quiz etc.) and «page» (everything else).
	 *
	 * @param  array<string, mixed>  $content
	 * @return array{page: list<string>, modules: list<string>}
	 */
	public static function errorsByPart(array $content, bool $strict = false, ?Profile $profile = null): array
	{
		$parts = ['page' => [], 'modules' => []];

		foreach (self::make($content, $strict, $profile)->errors()->toArray() as $key => $messages) {
			$part = str_starts_with((string) $key, 'modules') ? 'modules' : 'page';
			array_push($parts[$part], ...$messages);
		}

		return $parts;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function rules(bool $strict): array
	{
		return [
			'meta' => ['required', 'array'],
			'meta.title' => ['required', 'string', 'max:120'],
			'meta.instructions' => ['required', 'string', 'max:200'],
			'meta.topic' => ['required', 'string', 'max:80'],
			'meta.key_idea' => ['required', 'string', 'max:300'],
			'meta.emoji' => ['required', 'string', 'max:16'],
			'meta.palette' => ['required', Rule::in(Palettes::keys())],

			'sections' => ['required', 'array', 'min:1', 'max:4'],
			'sections.*.title' => ['required', 'string', 'max:80'],
			'sections.*.blocks' => ['required', 'array', 'min:1', 'max:4'],
			'sections.*.blocks.*.type' => ['required', Rule::in(self::BLOCK_TYPES)],
			'sections.*.blocks.*.origin' => ['sometimes', Rule::in(self::ORIGINS)],
			'sections.*.blocks.*.number' => ['required_if:sections.*.blocks.*.type,graphic', 'integer', 'min:2', 'max:3'],

			'try_it' => ['present', 'nullable', 'array'],
			'try_it.experiments' => ['required_with:try_it', 'array', 'min:1', 'max:3'],
			'try_it.experiments.*' => ['required', 'string', 'max:500'],
			'try_it.everyday_comparison' => ['nullable', 'string', 'max:400'],

			'modules' => ['required', 'array'],

			// The quiz is optional; the prompt sets the exact number of questions depending on the scope
			'modules.quiz' => $strict ? ['present', 'nullable', 'array', 'min:3', 'max:8'] : ['present', 'nullable', 'array', 'min:1', 'max:10'],
			'modules.quiz.*.id' => ['required', 'string', 'max:20'],
			'modules.quiz.*.question' => ['required', 'string', 'max:300'],
			'modules.quiz.*.options' => ['required', 'array', 'min:3', 'max:4'],
			'modules.quiz.*.options.*' => ['required', 'string', 'max:200'],
			'modules.quiz.*.answer' => ['required', 'integer', 'min:0', 'max:3'],
			'modules.quiz.*.hint' => ['nullable', 'string', 'max:300'],
			'modules.quiz.*.explanation' => ['required', 'string', 'max:500'],
			'modules.quiz.*.origin' => ['sometimes', Rule::in(self::ORIGINS)],

			'modules.sorting' => ['present', 'nullable', 'array'],
			'modules.sorting.instructions' => ['nullable', 'string', 'max:200'],
			'modules.sorting.categories' => ['required_with:modules.sorting', 'array', 'min:2', 'max:3'],
			'modules.sorting.categories.*.id' => ['required', Rule::in(self::CATEGORIES)],
			'modules.sorting.categories.*.label' => ['required', 'string', 'max:40'],
			'modules.sorting.categories.*.sub' => ['nullable', 'string', 'max:40'],
			'modules.sorting.terms' => ['required_with:modules.sorting', 'array', 'min:4', 'max:16'],
			'modules.sorting.terms.*.id' => ['required', 'string', 'max:20'],
			'modules.sorting.terms.*.text' => ['required', 'string', 'max:60'],
			'modules.sorting.terms.*.category' => ['required', Rule::in(self::CATEGORIES)],
			'modules.sorting.terms.*.explanation' => ['nullable', 'string', 'max:300'],
			'modules.sorting.terms.*.origin' => ['sometimes', Rule::in(self::ORIGINS)],

			'modules.flashcards' => ['present', 'nullable', 'array'],
			'modules.flashcards.instructions' => ['nullable', 'string', 'max:200'],
			'modules.flashcards.entries' => ['required_with:modules.flashcards', 'array', 'min:3', 'max:20'],
			'modules.flashcards.entries.*.id' => ['required', 'string', 'max:20'],
			'modules.flashcards.entries.*.front' => ['required', 'string', 'max:80'],
			'modules.flashcards.entries.*.back' => ['required', 'string', 'max:400'],
			'modules.flashcards.entries.*.origin' => ['sometimes', Rule::in(self::ORIGINS)],

			'modules.cloze' => ['present', 'nullable', 'array'],
			'modules.cloze.instructions' => ['nullable', 'string', 'max:200'],
			'modules.cloze.segments' => ['required_with:modules.cloze', 'array', 'min:1', 'max:60'],
			'modules.cloze.origin' => ['sometimes', Rule::in(self::ORIGINS)],
			// Only in the german profile: spelling gaps where upper and lower case count
			'modules.cloze.case_sensitive' => ['sometimes', 'nullable', 'boolean:strict'],

			'modules.exercises' => ['sometimes', 'nullable', 'array'],
			'modules.exercises.instructions' => ['nullable', 'string', 'max:200'],
			'modules.exercises.entries' => ['required_with:modules.exercises', 'array', 'min:3', 'max:8'],
			'modules.exercises.entries.*.id' => ['required', 'string', 'max:20'],
			'modules.exercises.entries.*.question' => ['required', 'string', 'max:400'],
			'modules.exercises.entries.*.kind' => ['required', Rule::in(ExerciseAnswer::KINDS)],
			'modules.exercises.entries.*.answer' => ['required', 'string', 'max:60'],
			'modules.exercises.entries.*.tolerance' => ['nullable', 'numeric', 'min:0'],
			'modules.exercises.entries.*.unit' => ['nullable', 'string', 'max:20'],
			'modules.exercises.entries.*.hint' => ['nullable', 'string', 'max:300'],
			'modules.exercises.entries.*.solution_path' => ['required', 'string', 'max:600'],

			'modules.find_the_mistake' => ['sometimes', 'nullable', 'array'],
			'modules.find_the_mistake.instructions' => ['nullable', 'string', 'max:200'],
			'modules.find_the_mistake.entries' => ['required_with:modules.find_the_mistake', 'array', 'min:4', 'max:8'],
			'modules.find_the_mistake.entries.*.id' => ['required', 'string', 'max:20'],
			'modules.find_the_mistake.entries.*.sentence' => ['required', 'string', 'max:300'],
			'modules.find_the_mistake.entries.*.mistake_word' => ['required', 'integer:strict', 'min:0'],
			'modules.find_the_mistake.entries.*.correction' => ['required', 'string', 'max:80'],
			'modules.find_the_mistake.entries.*.explanation' => ['required', 'string', 'max:400'],

			'reflect' => ['required', 'array'],
			'reflect.question' => ['required', 'string', 'max:400'],
		];
	}

	/**
	 * German field names for the messages parents see (the keys are English).
	 *
	 * @return array<string, string>
	 */
	private static function attributes(): array
	{
		return [
			'meta' => 'Kopf der Seite',
			'meta.title' => 'Titel',
			'meta.instructions' => 'Anleitung',
			'meta.topic' => 'Thema',
			'meta.key_idea' => 'Kernidee',
			'meta.emoji' => 'Emoji',
			'meta.palette' => 'Farbpalette',
			'sections' => 'Abschnitte',
			'sections.*.title' => 'Titel von Abschnitt :position',
			'sections.*.blocks' => 'Bausteine von Abschnitt :position',
			'sections.*.blocks.*.type' => 'Typ des Bausteins',
			'sections.*.blocks.*.origin' => 'Herkunft des Bausteins',
			'sections.*.blocks.*.number' => 'Nummer der Grafik',
			'try_it' => 'Ausprobieren',
			'try_it.experiments' => 'Experimente',
			'try_it.experiments.*' => 'Experiment :position',
			'try_it.everyday_comparison' => 'Alltagsvergleich',
			'modules' => 'Lernmodule',
			'modules.quiz' => 'Quiz',
			'modules.quiz.*.id' => 'ID der Quizfrage :position',
			'modules.quiz.*.question' => 'Quizfrage :position',
			'modules.quiz.*.options' => 'Antworten zu Quizfrage :position',
			'modules.quiz.*.options.*' => 'Antwort zu Quizfrage :position',
			'modules.quiz.*.answer' => 'Lösung von Quizfrage :position',
			'modules.quiz.*.hint' => 'Tipp zu Quizfrage :position',
			'modules.quiz.*.explanation' => 'Erklärung zu Quizfrage :position',
			'modules.quiz.*.origin' => 'Herkunft von Quizfrage :position',
			'modules.sorting' => 'Sortierspiel',
			'modules.sorting.instructions' => 'Anleitung zum Sortierspiel',
			'modules.sorting.categories' => 'Kategorien im Sortierspiel',
			'modules.sorting.categories.*.id' => 'ID der Kategorie :position',
			'modules.sorting.categories.*.label' => 'Name der Kategorie :position',
			'modules.sorting.categories.*.sub' => 'Untertitel der Kategorie :position',
			'modules.sorting.terms' => 'Begriffe im Sortierspiel',
			'modules.sorting.terms.*.id' => 'ID von Begriff :position',
			'modules.sorting.terms.*.text' => 'Begriff :position',
			'modules.sorting.terms.*.category' => 'Kategorie von Begriff :position',
			'modules.sorting.terms.*.explanation' => 'Erklärung zu Begriff :position',
			'modules.sorting.terms.*.origin' => 'Herkunft von Begriff :position',
			'modules.flashcards' => 'Karteikarten',
			'modules.flashcards.instructions' => 'Anleitung zu den Karteikarten',
			'modules.flashcards.entries' => 'Karteikarten',
			'modules.flashcards.entries.*.id' => 'ID von Karte :position',
			'modules.flashcards.entries.*.front' => 'Vorderseite von Karte :position',
			'modules.flashcards.entries.*.back' => 'Rückseite von Karte :position',
			'modules.flashcards.entries.*.origin' => 'Herkunft von Karte :position',
			'modules.cloze' => 'Lückentext',
			'modules.cloze.instructions' => 'Anleitung zum Lückentext',
			'modules.cloze.segments' => 'Lückentext',
			'modules.cloze.origin' => 'Herkunft des Lückentexts',
			'modules.cloze.case_sensitive' => 'Gross- und Kleinschreibung im Lückentext',
			'modules.exercises' => 'Aufgaben',
			'modules.exercises.instructions' => 'Anleitung zu den Aufgaben',
			'modules.exercises.entries' => 'Aufgaben',
			'modules.exercises.entries.*.id' => 'ID von Aufgabe :position',
			'modules.exercises.entries.*.question' => 'Aufgabe :position',
			'modules.exercises.entries.*.kind' => 'Art der Lösung von Aufgabe :position',
			'modules.exercises.entries.*.answer' => 'Lösung von Aufgabe :position',
			'modules.exercises.entries.*.tolerance' => 'Toleranz von Aufgabe :position',
			'modules.exercises.entries.*.unit' => 'Einheit von Aufgabe :position',
			'modules.exercises.entries.*.hint' => 'Tipp zu Aufgabe :position',
			'modules.exercises.entries.*.solution_path' => 'Lösungsweg von Aufgabe :position',
			'modules.find_the_mistake' => 'Fehler finden',
			'modules.find_the_mistake.instructions' => 'Anleitung zu «Fehler finden»',
			'modules.find_the_mistake.entries' => 'Sätze bei «Fehler finden»',
			'modules.find_the_mistake.entries.*.id' => 'ID von Satz :position',
			'modules.find_the_mistake.entries.*.sentence' => 'Satz :position',
			'modules.find_the_mistake.entries.*.mistake_word' => 'Falsches Wort in Satz :position',
			'modules.find_the_mistake.entries.*.correction' => 'Korrektur in Satz :position',
			'modules.find_the_mistake.entries.*.explanation' => 'Erklärung zu Satz :position',
			'reflect' => 'Nachdenken',
			'reflect.question' => 'Frage zum Nachdenken',
		];
	}

	/**
	 * @param  array<string, mixed>  $content
	 */
	private function __construct(
		private Validator $validator,
		private array $content,
		private bool $strict,
		private ?Profile $profile,
	) {}

	private function checkConsistency(): void
	{
		$this->checkBlocks();
		$this->checkModules();
		$this->checkQuiz();
		$this->checkSort();
		$this->checkCloze();
		$this->checkExercises();
		$this->checkMistakes();
		$this->checkUniqueIds();
		$this->checkSwissSpelling($this->content, '');

		if ($this->strict) {
			$this->checkStrictRules();
		}

		if ($this->strict && $this->profile !== null) {
			$this->checkProfile($this->profile);
		}

		if ($this->strict && $this->profile?->rendersMath()) {
			$this->checkTex($this->content, '');
		}
	}

	private function fail(string $key, string $message): void
	{
		$this->validator->errors()->add($key, $message);
	}

	private function checkBlocks(): void
	{
		foreach ($this->content['sections'] as $i => $section) {
			foreach ($section['blocks'] as $j => $block) {
				$key = "sections.$i.blocks.$j";

				$valid = match ($block['type']) {
					'paragraph' => $this->isText($block['text'] ?? null),
					'formula' => $this->isText($block['text'] ?? null)
						&& (! isset($block['addendum']) || is_string($block['addendum'])),
					'box' => $this->isText($block['title'] ?? null) && $this->isTextList($block['paragraphs'] ?? null),
					'graphic' => is_int($block['number'] ?? null),
					'facts' => $this->isList($block['entries'] ?? null, 1, 6, fn ($e) => $this->isText($e['title'] ?? null) && $this->isText($e['text'] ?? null)),
					'columns' => $this->isList($block['entries'] ?? null, 2, 3, fn ($e) => $this->isText($e['title'] ?? null)
						&& in_array($e['category'] ?? null, self::CATEGORIES, true)
						&& $this->isTextList($e['paragraphs'] ?? null)),
					'vocabulary' => $this->isOptionalText($block['title'] ?? null)
						&& $this->isList($block['entries'] ?? null, 4, 30, fn ($e) => $this->isText($e['foreign'] ?? null)
							&& $this->isText($e['german'] ?? null)
							&& $this->isOptionalText($e['info'] ?? null)),
					// One row per person: je, tu, il/elle, nous, vous, ils/elles
					'conjugation' => $this->isText($block['verb'] ?? null)
						&& $this->isText($block['tense'] ?? null)
						&& $this->isList($block['forms'] ?? null, 6, 6, fn ($f) => $this->isText($f['person'] ?? null) && $this->isText($f['form'] ?? null)),
					'worked_solution' => $this->isText($block['task'] ?? null)
						&& $this->isText($block['result'] ?? null)
						&& $this->isList($block['steps'] ?? null, 2, 8, fn ($s) => $this->isText($s['text'] ?? null) && $this->isOptionalText($s['reason'] ?? null)),
					'figure' => $this->checkFigure($block, $key),
					default => false,
				};

				if (! $valid) {
					$this->fail($key, "Block {$key} (Typ «{$block['type']}») ist unvollständig oder hat falsche Felder.");
				}
			}
		}
	}

	/**
	 * Shape of a figure (false: the caller reports the block as incomplete), then its geometry:
	 * unique point ids, lines and angles only between defined points, coordinates within the viewBox.
	 *
	 * @param  array<string, mixed>  $figure
	 */
	private function checkFigure(array $figure, string $key): bool
	{
		$isNumber = fn (mixed $value) => is_int($value) || is_float($value);

		$valid = $this->isOptionalText($figure['title'] ?? null)
			&& $this->isList($figure['points'] ?? null, 2, 12, fn ($p) => $this->isText($p['id'] ?? null)
				&& $isNumber($p['x'] ?? null) && $isNumber($p['y'] ?? null)
				&& $this->isOptionalText($p['label'] ?? null))
			&& $this->isList($figure['lines'] ?? null, 0, 16, fn ($l) => $this->isText($l['from'] ?? null) && $this->isText($l['to'] ?? null)
				&& $this->isOptionalText($l['label'] ?? null)
				&& in_array($l['style'] ?? null, ['solid', 'dashed'], true))
			&& $this->isList($figure['angles'] ?? null, 0, 6, fn ($a) => $this->isText($a['vertex'] ?? null)
				&& $this->isText($a['from'] ?? null) && $this->isText($a['to'] ?? null)
				&& $this->isOptionalText($a['label'] ?? null));

		if (! $valid) {
			return false;
		}

		$ids = array_column($figure['points'], 'id');

		foreach (array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1)) as $id) {
			$this->fail($key, "Figur in {$key}: Die Punkt-ID «{$id}» kommt mehrfach vor.");
		}

		foreach ($figure['points'] as $point) {
			if ($point['x'] < 0 || $point['x'] > 100 || $point['y'] < 0 || $point['y'] > 100) {
				$this->fail($key, "Figur in {$key}: Der Punkt «{$point['id']}» liegt ausserhalb von 0–100.");
			}
		}

		$references = [
			...array_merge(...array_map(fn ($l) => [$l['from'], $l['to']], $figure['lines'])),
			...array_merge(...array_map(fn ($a) => [$a['vertex'], $a['from'], $a['to']], $figure['angles'])),
		];

		foreach (array_unique(array_diff($references, $ids)) as $id) {
			$this->fail($key, "Figur in {$key}: Der Punkt «{$id}» ist nicht definiert.");
		}

		return true;
	}

	private function checkModules(): void
	{
		if (array_filter(array_intersect_key($this->content['modules'], array_flip(self::MODULES))) === []) {
			$this->fail('modules', 'Die Seite braucht mindestens ein Lernmodul.');
		}
	}

	private function checkQuiz(): void
	{
		foreach ($this->content['modules']['quiz'] ?? [] as $i => $question) {
			$options = $question['options'];

			if ($question['answer'] >= count($options)) {
				$this->fail("modules.quiz.$i.answer", "Quizfrage {$question['id']}: Die Lösung zeigt auf eine Option, die es nicht gibt.");
			}

			$normalized = array_map(fn ($o) => mb_strtolower(trim($o)), $options);
			if (count(array_unique($normalized)) !== count($options)) {
				$this->fail("modules.quiz.$i.options", "Quizfrage {$question['id']}: Zwei Antwortoptionen sind gleich.");
			}
		}
	}

	private function checkSort(): void
	{
		$sort = $this->content['modules']['sorting'];
		if ($sort === null) {
			return;
		}

		$categoryIds = array_column($sort['categories'], 'id');
		if (count(array_unique($categoryIds)) !== count($categoryIds)) {
			$this->fail('modules.sorting.categories', 'Sortierspiel: Zwei Kategorien haben dieselbe ID.');
		}

		$used = [];
		foreach ($sort['terms'] as $i => $term) {
			if (! in_array($term['category'], $categoryIds, true)) {
				$this->fail("modules.sorting.terms.$i.category", "Sortierspiel: «{$term['text']}» gehört zu einer Kategorie, die es nicht gibt.");
			}
			$used[$term['category']] = true;
		}

		foreach ($categoryIds as $id) {
			if (! isset($used[$id])) {
				$this->fail('modules.sorting.terms', "Sortierspiel: Die Kategorie {$id} hat keine Begriffe.");
			}
		}

		$texts = array_map(fn ($t) => mb_strtolower(trim($t['text'])), $sort['terms']);
		if (count(array_unique($texts)) !== count($texts)) {
			$this->fail('modules.sorting.terms', 'Sortierspiel: Ein Begriff kommt doppelt vor.');
		}
	}

	private function checkCloze(): void
	{
		$cloze = $this->content['modules']['cloze'];
		if ($cloze === null) {
			return;
		}

		$gaps = 0;
		foreach ($cloze['segments'] as $i => $segment) {
			$isText = is_array($segment) && array_keys($segment) === ['text'] && is_string($segment['text']);
			$isGap = is_array($segment)
				&& isset($segment['id'], $segment['answers'])
				&& count($segment) === 2
				&& is_string($segment['id'])
				&& $this->isTextList($segment['answers']);

			if ($isGap) {
				$gaps++;
			} elseif (! $isText) {
				$this->fail("modules.cloze.segments.$i", "Lückentext: Segment $i ist weder Text noch Lücke.");
			}
		}

		if ($gaps === 0) {
			$this->fail('modules.cloze.segments', 'Lückentext: Es gibt keine Lücke.');
		}
	}

	/**
	 * Every solution must be comparable, otherwise the child can never get the task right.
	 */
	private function checkExercises(): void
	{
		foreach ($this->content['modules']['exercises']['entries'] ?? [] as $i => $entry) {
			if (! ExerciseAnswer::isCheckable($entry['kind'], $entry['answer'])) {
				$expected = $entry['kind'] === 'fraction' ? 'kein Bruch wie 3/4 und keine Zahl' : 'keine Zahl';
				$this->fail("modules.exercises.entries.$i.answer", "Aufgabe {$entry['id']}: Die Lösung «{$entry['answer']}» ist {$expected}.");
			}
		}
	}

	/**
	 * The wrong word must exist, and its correction must differ from it, otherwise the child can never get it right.
	 */
	private function checkMistakes(): void
	{
		foreach ($this->content['modules']['find_the_mistake']['entries'] ?? [] as $i => $entry) {
			$word = MistakeAnswer::words($entry['sentence'])[$entry['mistake_word']] ?? null;

			if ($word === null) {
				$this->fail("modules.find_the_mistake.entries.$i.mistake_word", "Fehler finden, Satz {$entry['id']}: Das falsche Wort zeigt auf ein Wort, das es im Satz nicht gibt.");
			} elseif (trim($entry['correction']) === $word) {
				$this->fail("modules.find_the_mistake.entries.$i.correction", "Fehler finden, Satz {$entry['id']}: Die Korrektur ist gleich wie das falsche Wort «{$word}».");
			}
		}
	}

	private function checkUniqueIds(): void
	{
		$module = $this->content['modules'];

		$ids = [
			...array_column($module['quiz'] ?? [], 'id'),
			...array_column($module['sorting']['terms'] ?? [], 'id'),
			...array_column($module['flashcards']['entries'] ?? [], 'id'),
			...array_column(array_filter($module['cloze']['segments'] ?? [], fn ($s) => isset($s['id'])), 'id'),
			...array_column($module['exercises']['entries'] ?? [], 'id'),
			...array_column($module['find_the_mistake']['entries'] ?? [], 'id'),
		];

		$duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));

		if ($duplicates !== []) {
			$this->fail('modules', 'Diese IDs kommen mehrfach vor: '.implode(', ', $duplicates).'.');
		}
	}

	private function checkSwissSpelling(mixed $value, string $path): void
	{
		if (is_string($value)) {
			if (str_contains($value, 'ß')) {
				$this->fail($path, "Im Feld {$path} steht ein «ß». In der Schweiz schreibt man «ss».");
			}

			return;
		}

		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$this->checkSwissSpelling($item, $path === '' ? (string) $key : "$path.$key");
			}
		}
	}

	private function checkStrictRules(): void
	{
		$module = $this->content['modules'];

		// With fewer than 3 questions the position can be the same by chance
		$positions = array_column($module['quiz'] ?? [], 'answer');
		if (count($positions) >= 3 && count(array_unique($positions)) === 1) {
			$this->fail('modules.quiz', 'Quiz: Die richtige Antwort steht immer an derselben Position.');
		}

		foreach ($this->content['sections'] as $i => $section) {
			if (array_diff(array_column($section['blocks'], 'type'), ['graphic']) === []) {
				$this->fail("sections.$i", "Abschnitt «{$section['title']}»: Eine Grafik braucht erklärenden Text daneben.");
			}
		}
	}

	/**
	 * A fresh page uses only the blocks, modules and experiments of its profile.
	 */
	private function checkProfile(Profile $profile): void
	{
		foreach ($this->content['sections'] as $i => $section) {
			foreach ($section['blocks'] as $j => $block) {
				if (! in_array($block['type'], $profile->blocks(), true)) {
					$this->fail("sections.$i.blocks.$j", "Das Fachprofil «{$profile->label()}» hat keine Bausteine vom Typ «{$block['type']}».");
				}
			}
		}

		foreach ($this->content['modules'] as $module => $value) {
			if ($value !== null && ! in_array($module, $profile->modules(), true)) {
				$this->fail("modules.$module", "Das Fachprofil «{$profile->label()}» hat kein Lernmodul «{$module}».");
			}
		}

		if ($this->content['try_it'] !== null && ! $profile->allowsExperiments()) {
			$this->fail('try_it', "Das Fachprofil «{$profile->label()}» hat keine Experimente («Ausprobieren»).");
		}
	}

	/**
	 * TeX the browser can render: every «$» closed, braces balanced within a formula, only $…$ and $$…$$ as delimiters.
	 * There is no KaTeX in PHP; this catches what breaks a formula as a whole.
	 */
	private function checkTex(mixed $value, string $path): void
	{
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$this->checkTex($item, $path === '' ? (string) $key : "$path.$key");
			}

			return;
		}

		if (! is_string($value)) {
			return;
		}

		if (str_contains($value, '\(') || str_contains($value, '\[')) {
			$this->fail($path, "Im Feld {$path} steht eine Formel mit \\( oder \\[. Formeln gehören zwischen \$…\$ oder \$\$…\$\$.");
		}

		// «\$» is a literal dollar sign
		$text = str_replace('\\$', '', $value);

		if (substr_count($text, '$') % 2 !== 0) {
			$this->fail($path, "Im Feld {$path} ist ein «\$» nicht geschlossen.");

			return;
		}

		preg_match_all('/\$\$(.+?)\$\$|\$(.+?)\$/s', $text, $matches);

		foreach (array_map(null, $matches[1], $matches[2]) as [$display, $inline]) {
			if (! $this->hasBalancedBraces($display !== '' ? $display : $inline)) {
				$this->fail($path, "Im Feld {$path} sind die geschweiften Klammern in einer Formel nicht ausgeglichen.");

				return;
			}
		}
	}

	private function hasBalancedBraces(string $tex): bool
	{
		$depth = 0;

		foreach (str_split(str_replace(['\\{', '\\}'], '', $tex)) as $char) {
			$depth += match ($char) {
				'{' => 1,
				'}' => -1,
				default => 0,
			};

			if ($depth < 0) {
				return false;
			}
		}

		return $depth === 0;
	}

	private function isText(mixed $value): bool
	{
		return is_string($value) && trim($value) !== '';
	}

	private function isOptionalText(mixed $value): bool
	{
		return $value === null || is_string($value);
	}

	private function isTextList(mixed $value): bool
	{
		return $this->isList($value, 1, 20, fn ($v) => $this->isText($v));
	}

	private function isList(mixed $value, int $min, int $max, callable $each): bool
	{
		if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) {
			return false;
		}

		foreach ($value as $item) {
			if (! $each($item)) {
				return false;
			}
		}

		return true;
	}
}
