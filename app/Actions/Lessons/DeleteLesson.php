<?php

namespace App\Actions\Lessons;

use App\Actions\Generation\DeleteLessonImages;
use App\Enums\LessonStatus;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/**
 * Deletes photos, progress, content and graphics; the soft-deleted row stays for the cost overview.
 */
class DeleteLesson
{
    public function __construct(private DeleteLessonImages $deleteImages) {}

    public function handle(Lesson $lesson): void
    {
        $this->deleteImages->handle($lesson);

        // Content and progress disappear; the row stays only for the cost overview
        // (title, subject, level, child). «failed» instead of «review», because there is no content
        // any more: so it can neither be published nor regenerated.
        DB::transaction(function () use ($lesson) {
            $lesson->attempts()->delete();
            // The graphics are content of the lesson
            $lesson->graphics()->delete();

            $lesson->updateQuietly([
                'status' => LessonStatus::Failed,
                'published_at' => null,
                'content' => null,
                'prompt' => null,
                'notes' => null,
                'topic' => null,
                'source_summary' => null,
                'additions' => null,
                'check_notes' => null,
                'error' => null,
                'step' => null,
            ]);

            $lesson->delete();
        });
    }
}
