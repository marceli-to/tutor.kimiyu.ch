<?php

namespace App\Actions\Progress;

use App\Lessons\Progress;
use App\Models\Child;
use App\Models\Lesson;

/**
 * Eine Antwort des Kindes. Der Server prüft sie selbst gegen den Inhalt.
 */
class RecordAnswer
{
    /**
     * @return bool|null whether the answer is correct; null when the item does not exist (nothing is stored)
     */
    public function handle(Child $child, Lesson $lesson, string $module, string $itemId, mixed $answer): ?bool
    {
        $correct = Progress::check($lesson, $module, $itemId, is_scalar($answer) ? $answer : null);

        if ($correct !== null) {
            $child->attempts()->create([
                'lesson_id' => $lesson->id,
                'module' => $module,
                'item_id' => $itemId,
                'correct' => $correct,
            ]);
        }

        return $correct;
    }
}
