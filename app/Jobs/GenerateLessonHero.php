<?php

namespace App\Jobs;

use App\Lessons\LessonGenerator;

class GenerateLessonHero extends LessonStep
{
    protected function step(): string
    {
        return 'grafik';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->hero($this->lesson);
    }
}
