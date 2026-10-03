<?php

namespace App\Jobs;

use App\Actions\Generation\PlanLesson as PlanLessonAction;
use App\Enums\LessonStatus;
use App\Lessons\GenerationPipeline;

/**
 * Planning step. Stops for the parents' review, or confirms the plan itself and starts the writing.
 */
class PlanLesson extends LessonStep
{
	protected function step(): string
	{
		return 'analysis';
	}

	protected function run(): void
	{
		app(PlanLessonAction::class)->handle($this->lesson);

		if (! $this->lesson->stillExists()) {
			return;
		}

		if ($this->lesson->review_plan) {
			$this->lesson->update(['status' => LessonStatus::Planned, 'step' => null]);

			return;
		}

		$this->lesson->update(['plan_confirmed_at' => now()]);
		GenerationPipeline::write($this->lesson);
	}
}
