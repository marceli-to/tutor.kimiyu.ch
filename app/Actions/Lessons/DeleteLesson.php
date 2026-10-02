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

        // Inhalt und Lernstand verschwinden; die Zeile bleibt nur für die Kostenübersicht
        // (Titel, Fach, Stufe, Kind). «Fehlgeschlagen» statt «Zur Prüfung», weil es keinen Inhalt
        // mehr gibt: so lässt sie sich weder freigeben noch neu erstellen.
        DB::transaction(function () use ($lesson) {
            $lesson->attempts()->delete();
            // Die Grafiken sind Inhalt der Lernseite
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
