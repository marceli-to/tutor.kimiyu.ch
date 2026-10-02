<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Models\Lesson;

/**
 * Takes the page back: the child no longer sees it, the parents can review it again.
 */
class UnpublishLesson
{
    public function handle(Lesson $lesson): void
    {
        $lesson->update(['status' => LessonStatus::Review, 'published_at' => null]);
    }
}
