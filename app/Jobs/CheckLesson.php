<?php

namespace App\Jobs;

use App\Lessons\LessonGenerator;

class CheckLesson extends LessonStep
{
    protected function step(): string
    {
        return 'pruefung';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->check($this->lesson);
    }
}
