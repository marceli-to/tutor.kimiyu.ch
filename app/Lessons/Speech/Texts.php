<?php

namespace App\Lessons\Speech;

/**
 * The foreign texts of a lesson that get a speaker button, and what of them is spoken.
 */
class Texts
{
	/**
	 * Only the word itself: «parlé (parler)» reads «parlé», «le livre / les livres» reads «le livre».
	 * Same rule as the browser fallback in SpeakButton.vue.
	 */
	public static function spokenText(string $text): string
	{
		$spoken = trim((string) preg_replace('/[(\/].*/su', '', $text));

		return $spoken !== '' ? $spoken : trim($text);
	}

	/**
	 * Original texts of the vocabulary entries and flashcard fronts, each once.
	 *
	 * @param  array<string, mixed>  $content
	 * @return list<string>
	 */
	public static function texts(array $content): array
	{
		$texts = [];

		foreach ($content['sections'] ?? [] as $section) {
			foreach ($section['blocks'] ?? [] as $block) {
				if (($block['type'] ?? null) === 'vocabulary') {
					foreach ($block['entries'] ?? [] as $entry) {
						$texts[] = $entry['foreign'] ?? null;
					}
				}
			}
		}

		foreach ($content['modules']['flashcards']['entries'] ?? [] as $card) {
			$texts[] = $card['front'] ?? null;
		}

		return array_values(array_unique(array_filter($texts, fn ($text) => is_string($text) && trim($text) !== '')));
	}

	/**
	 * Key of a clip: another voice or model is another clip.
	 */
	public static function hash(string $spoken, string $lang, string $voiceId, string $model): string
	{
		return hash('sha256', implode('|', [$lang, $voiceId, $model, $spoken]));
	}
}
