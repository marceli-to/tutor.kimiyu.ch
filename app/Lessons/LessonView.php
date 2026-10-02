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
     * Die Herkunft (foto/ergaenzt) sehen nur Eltern, und nur bei Lernseiten mit Fotos.
     *
     * @return array<string, mixed>
     */
    public static function page(Lesson $lesson, bool $showOrigin = false): array
    {
        $content = $lesson->content;

        if ($content !== null && (! $showOrigin || $lesson->isFromTopic())) {
            $content = self::withoutOrigin($content);
        }

        return [
            'id' => $lesson->id,
            'subject' => $lesson->subject,
            'level' => $lesson->level,
            'content' => $content,
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

    /**
     * Entfernt «herkunft» in jeder Tiefe.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function withoutOrigin(array $data): array
    {
        unset($data['herkunft']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withoutOrigin($value);
            }
        }

        return $data;
    }
}
