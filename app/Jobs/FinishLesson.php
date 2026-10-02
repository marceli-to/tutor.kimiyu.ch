<?php

namespace App\Jobs;

use App\Enums\LessonStatus;

/**
 * Last step: the page is ready for review by the parents.
 */
class FinishLesson extends LessonStep
{
    protected function step(): ?string
    {
        return null;
    }

    protected function run(): void
    {
        $this->lesson->update([
            'status' => LessonStatus::Review,
            'step' => null,
            'error' => null,
            // New content from the AI: the parents review and publish again
            'published_at' => null,
        ]);
    }
}
