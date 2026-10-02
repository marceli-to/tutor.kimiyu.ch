<?php

namespace App\Jobs;

use App\Enums\LessonStatus;

/**
 * Letzter Schritt: Die Seite ist bereit zur Prüfung durch die Eltern.
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
            // Neuer Inhalt von der KI: die Eltern prüfen und geben wieder frei
            'published_at' => null,
        ]);
    }
}
