<?php

namespace App\Lessons;

/**
 * Subject profile of a lesson: the same page layout, but per profile a prompt addendum,
 * the allowed blocks and modules and some defaults. Stored on the lesson only when the
 * parents chose one; otherwise derived from the subject (see config lessons.profiles).
 */
enum Profile: string
{
	case Science = 'science';
	case General = 'general';
	case Languages = 'languages';
	case Math = 'math';
	case Geometry = 'geometry';
	case German = 'german';

	private const BASE_BLOCKS = ['paragraph', 'formula', 'facts', 'columns', 'box', 'graphic'];

	private const BASE_MODULES = ['quiz', 'sorting', 'flashcards', 'cloze'];

	/**
	 * Profile for a subject as typed by the parents or detected by the AI; unknown subjects are general.
	 */
	public static function forSubject(?string $subject): self
	{
		$profile = config('lessons.profiles')[mb_strtolower(trim((string) $subject))] ?? null;

		return is_string($profile) ? (self::tryFrom($profile) ?? self::General) : self::General;
	}

	public function label(): string
	{
		return match ($this) {
			self::Science => 'Naturwissenschaften',
			self::General => 'Allgemein',
			self::Languages => 'Sprachen',
			self::Math => 'Mathematik',
			self::Geometry => 'Geometrie',
			self::German => 'Deutsch',
		};
	}

	/**
	 * Block types the text part may use.
	 *
	 * @return list<string>
	 */
	public function blocks(): array
	{
		return match ($this) {
			// No formulas in a language lesson: the room in the schema goes to the word list and verb table
			self::Languages => ['paragraph', 'facts', 'columns', 'box', 'graphic', 'vocabulary', 'conjugation'],
			// No columns: math compares procedures in a box or facts; the room goes to the worked solution
			self::Math => ['paragraph', 'formula', 'facts', 'box', 'graphic', 'worked_solution'],
			default => self::BASE_BLOCKS,
		};
	}

	/**
	 * Learning modules the profile offers.
	 *
	 * @return list<string>
	 */
	public function modules(): array
	{
		return match ($this) {
			// Exercises instead of the sorting game: with all five modules the API rejects the grammar as too large
			self::Math => ['quiz', 'flashcards', 'cloze', 'exercises'],
			default => self::BASE_MODULES,
		};
	}

	/**
	 * Prompt addendum for the steps after the analysis.
	 */
	public function promptFile(): string
	{
		return resource_path("prompts/profile/{$this->value}.md");
	}

	/**
	 * Fixture used as the example in the page and modules prompts; «fotosynthese» until a profile has its own.
	 */
	public function fixture(): string
	{
		return match ($this) {
			self::Languages => 'passe-compose',
			self::Math => 'dreisatz',
			default => 'fotosynthese',
		};
	}

	/**
	 * Language for reading foreign words aloud (BCP 47), only in a languages lesson with a known subject.
	 */
	public function speechLang(?string $subject): ?string
	{
		if ($this !== self::Languages) {
			return null;
		}

		$lang = config('lessons.speech_langs')[mb_strtolower(trim((string) $subject))] ?? null;

		return is_string($lang) ? $lang : null;
	}

	/**
	 * TeX between $…$ is rendered as a formula (KaTeX) and checked on fresh pages. Elsewhere a «$» stays a dollar sign.
	 */
	public function rendersMath(): bool
	{
		return in_array($this, [self::Science, self::Math, self::Geometry], true);
	}

	/**
	 * Only science pages have «try_it» (experiments with graphic 1 and an everyday comparison).
	 */
	public function allowsExperiments(): bool
	{
		return $this === self::Science;
	}
}
