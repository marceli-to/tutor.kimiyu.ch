<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\LessonGenerator;

class RegenerateHero extends LessonStep
{
    protected function step(): string
    {
        return 'neu-grafik';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->hero($this->lesson, keepExisting: true);
    }

    // Scheitert es, bleibt die Seite wie vorher, auch freigegeben
    protected function statusAfterFailure(): LessonStatus
    {
        return $this->lesson->published_at ? LessonStatus::Published : LessonStatus::Review;
    }
}
