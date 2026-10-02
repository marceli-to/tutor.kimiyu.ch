<?php

namespace App\Actions\Generation;

use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the photos of a lesson, files and rows.
 */
class DeleteLessonImages
{
    public function handle(Lesson $lesson): void
    {
        foreach ($lesson->images as $image) {
            Storage::disk('lesson-images')->delete($image->path);
            $image->delete();
        }

        Storage::disk('lesson-images')->deleteDirectory((string) $lesson->id);
        $lesson->unsetRelation('images');
    }
}
