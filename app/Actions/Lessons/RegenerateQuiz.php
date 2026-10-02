<?php

namespace App\Actions\Lessons;

use App\Lessons\GenerationPipeline;
use App\Models\Lesson;

/**
 * Regenerates only the quiz.
 */
class RegenerateQuiz
{
	public function handle(Lesson $lesson): void
	{
		GenerationPipeline::regenerate($lesson, 'quiz');
	}
}
