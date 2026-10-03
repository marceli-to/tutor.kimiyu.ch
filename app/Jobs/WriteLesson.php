<?php

namespace App\Jobs;

use App\Actions\Generation\WriteLesson as WriteLessonAction;

class WriteLesson extends LessonStep
{
	protected function step(): string
	{
		return 'page';
	}

	protected function run(): void
	{
		app(WriteLessonAction::class)->handle($this->lesson);
	}
}
