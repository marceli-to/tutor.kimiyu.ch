<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\LessonGenerator;

class RegenerateQuiz extends LessonStep
{
    protected function step(): string
    {
        return 'neu-quiz';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->regenerateQuiz($this->lesson);
    }

    // Scheitert es, bleibt die Seite wie vorher, auch freigegeben
    protected function statusAfterFailure(): LessonStatus
    {
        return $this->lesson->published_at ? LessonStatus::Published : LessonStatus::Review;
    }
}
