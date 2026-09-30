<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\LessonGenerator;

/**
 * Letzter Schritt: Die Seite ist bereit zur Prüfung durch die Eltern.
 */
class FinishLesson extends LessonStep
{
    protected function step(): ?string
    {
        return null;
    }

    protected function run(LessonGenerator $generator): void
    {
        $this->lesson->update([
            'status' => LessonStatus::Review,
            'step' => null,
            'error' => null,
            // Neuer Inhalt von der KI: die Eltern prüfen und geben wieder frei
            'published_at' => null,
        ]);
    }
}
