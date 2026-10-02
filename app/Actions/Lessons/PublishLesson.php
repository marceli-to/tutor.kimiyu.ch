<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Models\Lesson;

/**
 * Freigeben: Das Kind sieht die Seite über seinen Link.
 */
class PublishLesson
{
    public function handle(Lesson $lesson): void
    {
        $lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);
    }
}
