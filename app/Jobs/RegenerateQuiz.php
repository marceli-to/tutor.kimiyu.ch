<?php

namespace App\Jobs;

use App\Actions\Generation\RegenerateQuizQuestions;
use App\Enums\LessonStatus;

/**
 * Writes a new quiz for a finished page. A published page stays online meanwhile.
 */
class RegenerateQuiz extends LessonStep
{
	protected function step(): string
	{
		return 'regenerate-quiz';
	}

	protected function run(): void
	{
		app(RegenerateQuizQuestions::class)->handle($this->lesson);

		$this->lesson->update(RegenerateGraphic::needsReview());
	}

	// If it fails, the page stays as before, published too
	protected function statusAfterFailure(): ?LessonStatus
	{
		return null;
	}
}
