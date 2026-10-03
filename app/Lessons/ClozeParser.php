<?php

namespace App\Lessons;

use InvalidArgumentException;
use Normalizer;

/**
 * Converts cloze markup («Die Pflanze nimmt [CO₂|CO2] auf.») into segments and back.
 *
 * Segments: ['text' => '…'] or ['id' => 'g1', 'answers' => ['CO₂', 'CO2']].
 */
class ClozeParser
{
	/**
	 * @param  list<string>  $existingIds  IDs that must not be assigned
	 * @return list<array<string, string|list<string>>>
	 */
	public static function parse(string $markup, array $existingIds = []): array
	{
		$segments = [];
		$used = array_flip($existingIds);
		$counter = 0;

		$parts = preg_split('/(\[[^\[\]]*\])/u', $markup, -1, PREG_SPLIT_DELIM_CAPTURE);

		if ($parts === false) {
			throw new InvalidArgumentException('Der Lückentext enthält ungültige Zeichen.');
		}

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			if (str_starts_with($part, '[') && str_ends_with($part, ']')) {
				$solutions = array_values(array_filter(
					array_map('trim', explode('|', mb_substr($part, 1, -1))),
					fn (string $s) => $s !== '',
				));

				if ($solutions === []) {
					throw new InvalidArgumentException('Eine Lücke ist leer: '.$part);
				}

				do {
					$id = 'g'.++$counter;
				} while (isset($used[$id]));
				$used[$id] = true;

				$segments[] = ['id' => $id, 'answers' => $solutions];

				continue;
			}

			if (str_contains($part, '[') || str_contains($part, ']')) {
				throw new InvalidArgumentException('Eine eckige Klammer ist nicht geschlossen.');
			}

			$segments[] = ['text' => $part];
		}

		return $segments;
	}

	/**
	 * @param  list<array<string, mixed>>  $segments
	 */
	public static function toMarkup(array $segments): string
	{
		return implode('', array_map(
			fn (array $s) => isset($s['answers'])
				? '['.implode('|', $s['answers']).']'
				: $s['text'],
			$segments,
		));
	}

	/**
	 * Same normalisation as in the frontend: trim, lower case (unless case counts), collapse whitespace.
	 */
	public static function normalize(string $answer, bool $caseSensitive = false): string
	{
		$answer = trim($answer);

		return preg_replace('/\s+/u', ' ', $caseSensitive ? $answer : mb_strtolower($answer));
	}

	/**
	 * Correct as in normalize(); almost if it only matches without accents (é/e, à/a, ü/u).
	 * Case sensitive in German spelling gaps («cloze.case_sensitive»).
	 *
	 * @param  list<string>  $solutions
	 */
	public static function check(string $answer, array $solutions, bool $caseSensitive = false): AnswerResult
	{
		$normalized = self::normalize($answer, $caseSensitive);
		$solutions = array_map(fn (string $solution) => self::normalize($solution, $caseSensitive), $solutions);

		if (in_array($normalized, $solutions, true)) {
			return AnswerResult::Correct;
		}

		$withoutAccents = self::withoutAccents($normalized);

		foreach ($solutions as $solution) {
			if ($withoutAccents !== '' && self::withoutAccents($solution) === $withoutAccents) {
				return AnswerResult::Almost;
			}
		}

		return AnswerResult::Wrong;
	}

	/**
	 * @param  list<string>  $solutions
	 */
	public static function isCorrect(string $answer, array $solutions): bool
	{
		return self::check($answer, $solutions)->isCorrect();
	}

	/**
	 * Same as in the frontend: decompose (NFD) and drop the combining marks. Not NFKD, so «CO₂» stays apart from «CO2».
	 */
	private static function withoutAccents(string $value): string
	{
		return (string) preg_replace('/\p{Mn}/u', '', (string) Normalizer::normalize($value, Normalizer::FORM_D));
	}
}
