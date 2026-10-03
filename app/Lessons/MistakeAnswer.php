<?php

namespace App\Lessons;

/**
 * Checks the answer to a sentence of the module «find_the_mistake»: the child taps the wrong word
 * and writes it correctly. The frontend mirrors it in resources/js/lib/mistake.ts for the
 * immediate feedback; this check is the one that counts.
 */
class MistakeAnswer
{
	/**
	 * Words of a sentence: split on spaces, punctuation stays attached («hoffe,»).
	 *
	 * @return list<string>
	 */
	public static function words(string $sentence): array
	{
		return array_values(array_filter(preg_split('/\s+/u', trim($sentence)) ?: [], fn (string $word) => $word !== ''));
	}

	/**
	 * Right word and correction exactly as expected (case counts). The punctuation around the word in
	 * the sentence may be left out: «dass» counts for «dass,», but a missing comma the correction adds doesn't.
	 *
	 * @param  array<string, mixed>  $entry  one sentence of the module
	 * @param  array<array-key, mixed>  $answer  {word: int, correction: string}
	 */
	public static function check(array $entry, array $answer): AnswerResult
	{
		$word = $answer['word'] ?? null;
		$correction = $answer['correction'] ?? null;

		if (! is_int($word) || ! is_string($correction) || $word !== ($entry['mistake_word'] ?? null)) {
			return AnswerResult::Wrong;
		}

		$original = self::words((string) $entry['sentence'])[$word] ?? '';
		preg_match('/^(\p{P}*).*?(\p{P}*)$/su', $original, $match);
		[, $before, $after] = $match + ['', '', ''];

		$correction = trim($correction);
		$candidates = [$correction, $before.$correction, $correction.$after, $before.$correction.$after];

		return $correction !== '' && in_array(trim((string) $entry['correction']), $candidates, true) ? AnswerResult::Correct : AnswerResult::Wrong;
	}
}
