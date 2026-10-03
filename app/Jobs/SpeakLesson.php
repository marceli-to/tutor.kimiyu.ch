<?php

namespace App\Jobs;

use App\Actions\Generation\SpeakLesson as SpeakLessonAction;

/**
 * Pronunciation of the foreign words. Never stops the chain: without clips the browser voice reads.
 */
class SpeakLesson extends LessonStep
{
	protected function step(): string
	{
		return 'speech';
	}

	protected function run(): void
	{
		app(SpeakLessonAction::class)->handle($this->lesson);
	}
}
