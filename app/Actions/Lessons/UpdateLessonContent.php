<?php

namespace App\Actions\Lessons;

use App\Actions\Generation\SpeakLesson;
use App\Lessons\ClozeParser;
use App\Lessons\ContentValidator;
use App\Lessons\GraphicBlocks;
use App\Models\Lesson;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Parents correct texts, quiz questions and solutions before they publish the page.
 */
class UpdateLessonContent
{
	/**
	 * @param  array<string, mixed>  $content
	 *
	 * @throws ValidationException when the cloze markup or the content is invalid
	 */
	public function handle(Lesson $lesson, array $content, ?string $clozeMarkup): void
	{
		// The cloze is edited as text with [gap|alternative]
		if (is_array($content['modules']['cloze'] ?? null)) {
			try {
				$content['modules']['cloze']['segments'] = ClozeParser::parse((string) $clozeMarkup);

				// The origin isn't edited in the markup, so take it from the stored cloze
				$origin = $lesson->content['modules']['cloze']['origin'] ?? null;
				if ($origin !== null) {
					$content['modules']['cloze']['origin'] = $origin;
				}
			} catch (InvalidArgumentException $e) {
				throw ValidationException::withMessages(['clozeMarkup' => $e->getMessage()]);
			}
		}

		$validator = ContentValidator::make($content);

		if ($validator->fails()) {
			throw ValidationException::withMessages(
				collect($validator->errors()->toArray())
					->mapWithKeys(fn (array $messages, string $key) => ["content.{$key}" => $messages])
					->all(),
			);
		}

		// Compared against what the edit view showed, including the graphics without a fixed place
		$this->hideRemovedGraphics($lesson, GraphicBlocks::withUnplaced($lesson, $lesson->content), $content);

		$lesson->update([
			'title' => $content['meta']['title'],
			'content' => $content,
		]);

		// New or changed words get their clip; existing ones cost nothing. Not a LessonStep:
		// that would set a step on a finished page.
		if ($lesson->speaksWithElevenLabs()) {
			dispatch(fn (SpeakLesson $speakLesson) => $speakLesson->handle($lesson->fresh() ?? $lesson));
		}
	}

	/**
	 * If a parent removes a graphic's block, the graphic is hidden (it stays
	 * stored). If the block comes back, it is visible again. Graphics that never had a block
	 * (graphic 1, graphics at the end of the last section) stay as they are.
	 *
	 * @param  array<string, mixed>  $old
	 * @param  array<string, mixed>  $new
	 */
	private function hideRemovedGraphics(Lesson $lesson, array $old, array $new): void
	{
		$before = GraphicBlocks::numbers($old);
		$after = GraphicBlocks::numbers($new);

		$removed = array_diff($before, $after);
		if ($removed !== []) {
			$lesson->graphics()->whereIn('position', $removed)->update(['hidden' => true]);
		}

		if ($after !== []) {
			$lesson->graphics()->whereIn('position', $after)->update(['hidden' => false]);
		}
	}
}
