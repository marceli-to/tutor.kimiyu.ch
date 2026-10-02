<?php

namespace App\Actions\Lessons;

use App\Lessons\GenerationPipeline;
use App\Models\Lesson;

/**
 * Regenerates only one graphic; the others stay.
 */
class RegenerateGraphic
{
    public function handle(Lesson $lesson, int $position): void
    {
        GenerationPipeline::regenerate($lesson, 'graphic', $position);
    }
}
