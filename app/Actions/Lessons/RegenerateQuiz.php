<?php

namespace App\Actions\Lessons;

use App\Lessons\GenerationPipeline;
use App\Models\Lesson;

/**
 * Nur das Quiz neu erstellen lassen.
 */
class RegenerateQuiz
{
    public function handle(Lesson $lesson): void
    {
        GenerationPipeline::regenerate($lesson, 'quiz');
    }
}
