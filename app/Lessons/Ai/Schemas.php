<?php

namespace App\Lessons\Ai;

use App\Lessons\ContentValidator;
use App\Lessons\GraphicPattern;
use App\Lessons\Palettes;
use App\Lessons\Profile;

/**
 * JSON schemas for the structured output.
 *
 * The API only enforces shape and types (no lengths or counts). Everything else
 * is checked afterwards on the server by the ContentValidator or GraphicValidator.
 */
class Schemas
{
	/**
	 * First step: read the source, subject, summary and plans for the graphics. The text part comes
	 * separately (part('page')): together the API rejects the grammar as too large.
	 *
	 * @return array<string, mixed>
	 */
	public static function analysis(): array
	{
		return self::object([
			'source' => self::object([
				'readable' => ['type' => 'boolean', 'description' => 'false, wenn die Fotos unleserlich sind, der Auftrag unklar ist oder kein Schulstoff erkennbar ist'],
				'problem' => self::nullable(['type' => 'string', 'description' => 'Kurze Erklärung für die Eltern, was mit den Fotos oder dem Auftrag nicht stimmt']),
			]),
			'subject' => ['type' => 'string', 'description' => 'Schulfach, z. B. Biologie, Mathematik, Französisch'],
			'summary' => ['type' => 'string', 'description' => 'Neutrale, vollständige Zusammenfassung des Stoffs in eigenen Worten'],
			'additions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pro Ergänzung ein Satz: was auf den Fotos fehlte und was aus Fachwissen ergänzt wurde. Leer, wenn nichts ergänzt wurde oder es keine Fotos gibt.'],
			'graphic_plans' => ['type' => 'array', 'items' => self::object([
				'number' => ['type' => 'integer'],
				'plan' => self::nullable(self::graphicPlan()),
				'note' => self::nullable(['type' => 'string', 'description' => 'Warum der Wunsch nicht passt, ein Satz für die Eltern']),
			])],
		]);
	}

	/**
	 * Second step: quiz, sorting game, flashcards, cloze (as far as the profile offers them).
	 *
	 * @return array<string, mixed>
	 */
	public static function modulesResult(?Profile $profile = null): array
	{
		return self::object(['modules' => self::modules($profile)]);
	}

	/**
	 * New quiz for an existing page.
	 *
	 * @return array<string, mixed>
	 */
	public static function quizResult(): array
	{
		return self::object(['quiz' => self::quiz()]);
	}

	/**
	 * One part of the page: text part (step «page» and repair) or modules (repair). The whole
	 * page is too large for a single structured answer (the API rejects the grammar).
	 *
	 * @return array<string, mixed>
	 */
	public static function part(string $part, ?Profile $profile = null): array
	{
		return self::object([$part => $part === 'page' ? self::page($profile) : self::modules($profile)]);
	}

	/**
	 * Check: only the corrections, not the whole page.
	 *
	 * @return array<string, mixed>
	 */
	public static function checkResult(): array
	{
		return self::object([
			'corrections' => [
				'type' => 'array',
				'items' => self::object([
					'path' => ['type' => 'string', 'description' => 'JSON-Pointer auf den Wert, z. B. /modules/quiz/2/answer oder /sections/0/blocks/1/text. Indizes 0-basiert.'],
					'value' => ['type' => 'string', 'description' => 'Neuer Wert. Text direkt; Zahlen und Listen aus Texten als JSON, z. B. 1 oder ["A","B","C"]'],
					'area' => ['type' => 'string', 'description' => 'z. B. «Quiz, Frage 3» oder «Sortierspiel»'],
					'change' => ['type' => 'string', 'description' => 'Was geändert wurde und warum, ein Satz'],
				]),
			],
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function graphic(): array
	{
		return self::object([
			'pattern' => ['type' => 'string', 'enum' => array_column(GraphicPattern::cases(), 'value')],
			'description' => ['type' => 'string', 'description' => 'Ein bis zwei Sätze: Was zeigt die Grafik, was kann man tun? Wird als Alternativtext verwendet.'],
			'css' => ['type' => 'string'],
			'markup' => ['type' => 'string'],
			'script' => ['type' => 'string'],
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function graphicPlan(): array
	{
		return self::object([
			'pattern' => ['type' => 'string', 'enum' => array_column(GraphicPattern::cases(), 'value')],
			'idea' => ['type' => 'string', 'description' => 'Was die Grafik zeigt, welche Interaktion den Mechanismus sichtbar macht, welche Kategorie-Farbe (cat1–cat3) was bedeutet'],
		]);
	}

	/**
	 * Content of a lesson, matching ContentValidator (schema version 1).
	 * Not sent to the API like this, but split into page() and modules().
	 *
	 * @return array<string, mixed>
	 */
	public static function content(): array
	{
		$schema = self::page();
		$schema['properties']['modules'] = self::modules();
		$schema['required'] = array_keys($schema['properties']);

		return $schema;
	}

	/**
	 * Text part of the page: everything except the modules. With a profile only its blocks, and
	 * «try_it» only if it has experiments: every extra part makes the grammar larger.
	 * Without a profile the full set.
	 *
	 * @return array<string, mixed>
	 */
	public static function page(?Profile $profile = null): array
	{
		$text = ['type' => 'string'];
		$texts = ['type' => 'array', 'items' => $text];
		$category = ['type' => 'string', 'enum' => ContentValidator::CATEGORIES];
		$origin = self::origin();

		$block = fn (string $type, array $properties) => self::object(['type' => ['type' => 'string', 'const' => $type], ...$properties, 'origin' => $origin]);

		$blocks = [
			'paragraph' => $block('paragraph', ['text' => $text]),
			'formula' => $block('formula', ['text' => $text, 'addendum' => self::nullable($text)]),
			'facts' => $block('facts', ['entries' => ['type' => 'array', 'items' => self::object(['title' => $text, 'text' => $text])]]),
			'columns' => $block('columns', ['entries' => ['type' => 'array', 'items' => self::object([
				'title' => $text,
				'category' => $category,
				'paragraphs' => $texts,
			])]]),
			'box' => $block('box', ['title' => $text, 'paragraphs' => $texts]),
			'graphic' => $block('graphic', ['number' => ['type' => 'integer']]),
		];

		if ($profile !== null) {
			$blocks = array_intersect_key($blocks, array_flip($profile->blocks()));
		}

		$schema = self::object([
			'meta' => self::object([
				'title' => ['type' => 'string', 'description' => 'Frage oder Formel, die neugierig macht'],
				'instructions' => ['type' => 'string', 'description' => 'Eine Zeile: was man mit der Grafik tun kann; ohne Grafik: worum es geht'],
				'topic' => $text,
				'key_idea' => ['type' => 'string', 'description' => 'Was das Kind nach dem Lernen verstanden haben muss, ein Satz'],
				'emoji' => $text,
				'palette' => ['type' => 'string', 'enum' => Palettes::keys()],
			]),
			'sections' => [
				'type' => 'array',
				'items' => self::object([
					'title' => $text,
					'blocks' => [
						'type' => 'array',
						'items' => ['anyOf' => array_values($blocks)],
					],
				]),
			],
			'try_it' => self::nullable(self::object([
				'experiments' => $texts,
				'everyday_comparison' => self::nullable($text),
			])),
			'reflect' => self::object(['question' => $text]),
		]);

		if ($profile !== null && ! $profile->allowsExperiments()) {
			unset($schema['properties']['try_it']);
			$schema['required'] = array_keys($schema['properties']);
		}

		return $schema;
	}

	/**
	 * Questions of the quiz.
	 *
	 * @return array<string, mixed>
	 */
	private static function quiz(): array
	{
		$text = ['type' => 'string'];

		return [
			'type' => 'array',
			'items' => self::object([
				'id' => $text,
				'question' => $text,
				'options' => ['type' => 'array', 'items' => $text],
				'answer' => ['type' => 'integer', 'description' => 'Index der richtigen Option, 0-basiert'],
				'hint' => self::nullable($text),
				'explanation' => $text,
				'origin' => self::origin(),
			]),
		];
	}

	/**
	 * Modules not offered by the profile are left out (not nullable); AnalyzeLesson sets them to null.
	 * Without a profile the full set.
	 *
	 * @return array<string, mixed>
	 */
	public static function modules(?Profile $profile = null): array
	{
		$text = ['type' => 'string'];
		$texts = ['type' => 'array', 'items' => $text];
		$category = ['type' => 'string', 'enum' => ContentValidator::CATEGORIES];
		$origin = self::origin();

		$modules = [
			// null if the parents don't want a quiz
			'quiz' => self::nullable(self::quiz()),
			'sorting' => self::nullable(self::object([
				'instructions' => self::nullable($text),
				'categories' => ['type' => 'array', 'items' => self::object([
					'id' => $category,
					'label' => $text,
					'sub' => self::nullable($text),
				])],
				'terms' => ['type' => 'array', 'items' => self::object([
					'id' => $text,
					'text' => $text,
					'category' => $category,
					'explanation' => self::nullable($text),
					'origin' => $origin,
				])],
			])),
			'flashcards' => self::nullable(self::object([
				'instructions' => self::nullable($text),
				'entries' => ['type' => 'array', 'items' => self::object([
					'id' => $text,
					'front' => $text,
					'back' => $text,
					'origin' => $origin,
				])],
			])),
			'cloze' => self::nullable(self::object([
				'instructions' => self::nullable($text),
				'segments' => ['type' => 'array', 'items' => ['anyOf' => [
					self::object(['text' => $text]),
					self::object(['id' => $text, 'answers' => $texts]),
				]]],
				'origin' => $origin,
			])),
		];

		return self::object($profile !== null ? array_intersect_key($modules, array_flip($profile->modules())) : $modules);
	}

	/**
	 * Origin of a block: from the photos or added from subject knowledge.
	 *
	 * @return array<string, mixed>
	 */
	private static function origin(): array
	{
		return ['type' => 'string', 'enum' => ContentValidator::ORIGINS, 'description' => 'added: nicht auf den Fotos, aus Fachwissen ergänzt'];
	}

	/**
	 * Object with all fields required, as the structured output demands.
	 *
	 * @param  array<string, mixed>  $properties
	 * @return array<string, mixed>
	 */
	private static function object(array $properties): array
	{
		return [
			'type' => 'object',
			'properties' => $properties,
			'required' => array_keys($properties),
			'additionalProperties' => false,
		];
	}

	/**
	 * @param  array<string, mixed>  $schema
	 * @return array<string, mixed>
	 */
	private static function nullable(array $schema): array
	{
		return ['anyOf' => [$schema, ['type' => 'null']]];
	}
}
