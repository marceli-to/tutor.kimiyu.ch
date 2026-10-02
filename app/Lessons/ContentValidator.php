<?php

namespace App\Lessons;

use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the content of a lesson (schema version 1).
 *
 * Non-strict: what a page needs to be shown and edited.
 * Strict: additionally the didactic rules from SKILL.md that apply to freshly generated pages.
 */
class ContentValidator
{
	public const SCHEMA_VERSION = 1;

	// «graphic» places graphic 2 or 3 in a section; graphic 1 is always at the top
	public const BLOCK_TYPES = ['paragraph', 'formula', 'facts', 'columns', 'box', 'graphic'];

	public const CATEGORIES = ['cat1', 'cat2', 'cat3'];

	// Origin of a block; missing on old pages
	public const ORIGINS = ['photo', 'added'];

	/**
	 * @param  array<string, mixed>  $content
	 */
	public static function make(array $content, bool $strict = false): Validator
	{
		$validator = ValidatorFactory::make($content, self::rules($strict), [], self::attributes());

		$validator->after(function (Validator $validator) use ($content, $strict) {
			if ($validator->errors()->isNotEmpty()) {
				return;
			}

			(new self($validator, $content, $strict))->checkConsistency();
		});

		return $validator;
	}

	/**
	 * @param  array<string, mixed>  $content
	 * @return list<string>
	 */
	public static function errors(array $content, bool $strict = false): array
	{
		return array_values(self::make($content, $strict)->errors()->all());
	}

	/**
	 * Errors split by part of the page: «modules» (quiz etc.) and «page» (everything else).
	 *
	 * @param  array<string, mixed>  $content
	 * @return array{page: list<string>, modules: list<string>}
	 */
	public static function errorsByPart(array $content, bool $strict = false): array
	{
		$parts = ['page' => [], 'modules' => []];

		foreach (self::make($content, $strict)->errors()->toArray() as $key => $messages) {
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
	) {}

	private function checkConsistency(): void
	{
		$this->checkBlocks();
		$this->checkModules();
		$this->checkQuiz();
		$this->checkSort();
		$this->checkCloze();
		$this->checkUniqueIds();
		$this->checkSwissSpelling($this->content, '');

		if ($this->strict) {
			$this->checkStrictRules();
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
					default => false,
				};

				if (! $valid) {
					$this->fail($key, "Block {$key} (Typ «{$block['type']}») ist unvollständig oder hat falsche Felder.");
				}
			}
		}
	}

	private function checkModules(): void
	{
		if (array_filter(array_intersect_key($this->content['modules'], array_flip(['quiz', 'sorting', 'flashcards', 'cloze']))) === []) {
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

	private function checkUniqueIds(): void
	{
		$module = $this->content['modules'];

		$ids = [
			...array_column($module['quiz'] ?? [], 'id'),
			...array_column($module['sorting']['terms'] ?? [], 'id'),
			...array_column($module['flashcards']['entries'] ?? [], 'id'),
			...array_column(array_filter($module['cloze']['segments'] ?? [], fn ($s) => isset($s['id'])), 'id'),
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

	private function isText(mixed $value): bool
	{
		return is_string($value) && trim($value) !== '';
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
