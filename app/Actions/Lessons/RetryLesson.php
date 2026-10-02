<?php

namespace App\Actions\Lessons;

use App\Lessons\GenerationPipeline;
use App\Models\Lesson;

/**
 * New attempt for a failed lesson; finished steps are skipped.
 */
class RetryLesson
{
    public function handle(Lesson $lesson): void
    {
        GenerationPipeline::start($lesson);
    }
}
