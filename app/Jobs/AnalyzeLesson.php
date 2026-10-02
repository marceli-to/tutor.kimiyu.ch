<?php

namespace App\Jobs;

use App\Lessons\LessonGenerator;

class AnalyzeLesson extends LessonStep
{
    protected function step(): string
    {
        return 'analysis';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->analyze($this->lesson);
    }
}
