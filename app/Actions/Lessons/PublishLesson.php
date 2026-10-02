<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Models\Lesson;

/**
 * Publish: the child sees the page through its link.
 */
class PublishLesson
{
    public function handle(Lesson $lesson): void
    {
        $lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);
    }
}
