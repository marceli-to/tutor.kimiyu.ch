<?php

namespace App\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\URL;

/**
 * Was die Vue-Komponente LessonPage zum Anzeigen braucht, für Eltern und für den geteilten Link.
 */
class LessonView
{
    /**
     * @return array<string, mixed>
     */
    public static function page(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'subject' => $lesson->subject,
            'level' => $lesson->level,
            'content' => $lesson->content,
            'palette' => $lesson->content ? Palettes::get($lesson->content['meta']['palette'] ?? null) : null,
            'hero' => $lesson->hero ? [
                // Signiert, weil das iframe ohne eigenen Origin keine Session hat
                'url' => URL::signedRoute('lessons.hero', [
                    'lesson' => $lesson,
                    'v' => $lesson->updated_at?->timestamp,
                ]),
                'beschreibung' => $lesson->hero['beschreibung'] ?? '',
            ] : null,
        ];
    }
}
