<?php

namespace App\Jobs;

use App\Actions\Generation\GenerateGraphic;
use App\Models\Lesson;

/**
 * One graphic of the lesson. Without a plan for this position the job does nothing.
 */
class GenerateLessonGraphic extends LessonStep
{
	public function __construct(Lesson $lesson, public int $position)
	{
		parent::__construct($lesson);
	}

	// For the progress display; the API steps are still called «graphic» and «graphic-repair»
	protected function step(): string
	{
		return "graphic-{$this->position}";
	}

	protected function run(): void
	{
		app(GenerateGraphic::class)->handle($this->lesson, $this->position);
	}
}
