<?php

namespace App\Actions\Lessons;

use App\Lessons\GenerationPipeline;
use App\Models\Lesson;

/**
 * Nur eine Grafik neu erstellen lassen; die anderen bleiben.
 */
class RegenerateGraphic
{
    public function handle(Lesson $lesson, int $position): void
    {
        GenerationPipeline::regenerate($lesson, 'graphic', $position);
    }
}
