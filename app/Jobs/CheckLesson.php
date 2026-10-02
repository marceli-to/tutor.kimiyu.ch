<?php

namespace App\Jobs;

use App\Actions\Generation\CheckLesson as CheckLessonAction;

class CheckLesson extends LessonStep
{
    protected function step(): string
    {
        return 'check';
    }

    protected function run(): void
    {
        app(CheckLessonAction::class)->handle($this->lesson);
    }
}
