<?php

namespace App\Actions\Progress;

use App\Lessons\AnswerResult;
use App\Lessons\Progress;
use App\Models\Child;
use App\Models\Lesson;

/**
 * One answer of the child. The server checks it itself against the content.
 */
class RecordAnswer
{
	/**
	 * @return AnswerResult|null null when the item does not exist (nothing is stored); «almost» is stored as not correct
	 */
	public function handle(Child $child, Lesson $lesson, string $module, string $itemId, mixed $answer): ?AnswerResult
	{
		$result = Progress::check($lesson, $module, $itemId, $answer);

		if ($result !== null) {
			$child->attempts()->create([
				'lesson_id' => $lesson->id,
				'module' => $module,
				'item_id' => $itemId,
				'correct' => $result->isCorrect(),
			]);
		}

		return $result;
	}
}
