<?php

namespace App\Jobs;

use App\Actions\Generation\AnalyzeLesson as AnalyzeLessonAction;

class AnalyzeLesson extends LessonStep
{
    protected function step(): string
    {
        return 'analysis';
    }

    protected function run(): void
    {
        app(AnalyzeLessonAction::class)->handle($this->lesson);
    }
}
