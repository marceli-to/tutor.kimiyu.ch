<?php

namespace App\Lessons;

use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Support\Facades\URL;

/**
 * What the Vue component LessonPage needs for display, for parents and for the shared link.
 */
class LessonView
{
	/**
	 * Only parents see the origin (photo/added), and only for lessons with photos.
	 *
	 * @return array<string, mixed>
	 */
	public static function page(Lesson $lesson, bool $showOrigin = false): array
	{
		$graphics = self::graphics($lesson);
		$content = $lesson->content;

		if ($content !== null) {
			$content = self::placeGraphics($content, array_keys($graphics));
		}

		if ($content !== null && (! $showOrigin || $lesson->isFromTopic())) {
			$content = self::withoutOrigin($content);
		}

		$profile = $lesson->resolvedProfile();

		return [
			'id' => $lesson->id,
			'subject' => $lesson->subjectLabel(),
			'profile' => $profile->value,
			// Read-aloud button for foreign words; null: no button
			'speechLang' => $lesson->speechLang(),
			// TeX between $…$ becomes a formula; elsewhere a «$» stays a dollar sign
			'math' => $profile->rendersMath(),
			'level' => $lesson->level,
			'content' => $content,
			'palette' => $lesson->content ? Palettes::get($lesson->content['meta']['palette'] ?? null) : null,
			'graphics' => $graphics,
		];
	}

	/**
	 * Only finished, not hidden graphics, only URL and description: errors, wishes and plans are for the parents only.
	 *
	 * @return array<int, array{url: string, description: string}>
	 */
	private static function graphics(Lesson $lesson): array
	{
		return $lesson->graphics
			// The page doesn't show hidden graphics at all, not even at the end
			->filter(fn (LessonGraphic $graphic) => $graphic->graphic !== null && ! $graphic->hidden)
			->mapWithKeys(fn (LessonGraphic $graphic) => [$graphic->position => [
				// Signed, because the iframe without its own origin has no session
				'url' => URL::signedRoute('lessons.graphic', [
					'lesson' => $lesson,
					'number' => $graphic->position,
					'v' => $graphic->updated_at?->timestamp,
				]),
				'description' => $graphic->graphic['description'] ?? '',
			]])
			->all();
	}

	/**
	 * Removes blocks of graphics that aren't finished. A finished graphic 2 or 3 without a block
	 * goes to the end of the last section, so it is never lost. Sections left empty are dropped.
	 *
	 * @param  array<string, mixed>  $content
	 * @param  list<int>  $finished
	 * @return array<string, mixed>
	 */
	private static function placeGraphics(array $content, array $finished): array
	{
		$placed = [];

		foreach ($content['sections'] ?? [] as $k => $section) {
			$content['sections'][$k]['blocks'] = array_values(array_filter(
				$section['blocks'] ?? [],
				function (array $block) use ($finished, &$placed) {
					if (($block['type'] ?? null) !== 'graphic') {
						return true;
					}

					$number = $block['number'] ?? null;

					// Show each graphic only once
					if (! in_array($number, $finished, true) || in_array($number, $placed, true)) {
						return false;
					}

					$placed[] = $number;

					return true;
				},
			));
		}

		// Without its graphic a section may be empty: the child never sees an empty heading
		if (isset($content['sections'])) {
			$content['sections'] = array_values(array_filter(
				$content['sections'],
				fn (array $section) => ($section['blocks'] ?? []) !== [],
			));
		}

		$last = array_key_last($content['sections'] ?? []);

		foreach ($finished as $number) {
			if ($number > 1 && $last !== null && ! in_array($number, $placed, true)) {
				$content['sections'][$last]['blocks'][] = ['type' => 'graphic', 'number' => $number];
			}
		}

		return $content;
	}

	/**
	 * Removes «origin» at every depth.
	 *
	 * @param  array<mixed>  $data
	 * @return array<mixed>
	 */
	public static function withoutOrigin(array $data): array
	{
		unset($data['origin']);

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = self::withoutOrigin($value);
			}
		}

		return $data;
	}
}
