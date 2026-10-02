<?php

namespace App\Lessons;

use App\Models\Lesson;
use App\Models\LessonGraphic;
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
        $graphics = self::graphics($lesson);
        $content = $lesson->content;

        if ($content !== null) {
            $content = self::placeGraphics($content, array_keys($graphics));
        }

        if ($content !== null && (! $showOrigin || $lesson->isFromTopic())) {
            $content = self::withoutOrigin($content);
        }

        return [
            'id' => $lesson->id,
            'subject' => $lesson->subject,
            'level' => $lesson->level,
            'content' => $content,
            'palette' => $lesson->content ? Palettes::get($lesson->content['meta']['palette'] ?? null) : null,
            // Grafik 1 steht oben, 2 und 3 an ihrem Block im Inhalt
            'hero' => $graphics[1] ?? null,
            'graphics' => $graphics,
        ];
    }

    /**
     * Nur fertige Grafiken, nur URL und Beschreibung: Fehler, Wünsche und Pläne sehen nur die Eltern.
     *
     * @return array<int, array{url: string, beschreibung: string}>
     */
    private static function graphics(Lesson $lesson): array
    {
        return $lesson->graphics
            ->filter(fn (LessonGraphic $graphic) => $graphic->graphic !== null)
            ->mapWithKeys(fn (LessonGraphic $graphic) => [$graphic->position => [
                // Signiert, weil das iframe ohne eigenen Origin keine Session hat
                'url' => URL::signedRoute('lessons.graphic', [
                    'lesson' => $lesson,
                    'nr' => $graphic->position,
                    'v' => $graphic->updated_at?->timestamp,
                ]),
                'beschreibung' => $graphic->graphic['beschreibung'] ?? '',
            ]])
            ->all();
    }

    /**
     * Blöcke von Grafiken, die nicht fertig sind, entfernen. Eine fertige Grafik 2 oder 3 ohne Block
     * kommt ans Ende des letzten Abschnitts, damit sie nie verloren geht.
     *
     * @param  array<string, mixed>  $content
     * @param  list<int>  $finished
     * @return array<string, mixed>
     */
    private static function placeGraphics(array $content, array $finished): array
    {
        $placed = [];

        foreach ($content['abschnitte'] ?? [] as $k => $section) {
            $content['abschnitte'][$k]['bloecke'] = array_values(array_filter(
                $section['bloecke'] ?? [],
                function (array $block) use ($finished, &$placed) {
                    if (($block['typ'] ?? null) !== 'grafik') {
                        return true;
                    }

                    $nr = $block['nr'] ?? null;

                    // Jede Grafik nur einmal zeigen
                    if (! in_array($nr, $finished, true) || in_array($nr, $placed, true)) {
                        return false;
                    }

                    $placed[] = $nr;

                    return true;
                },
            ));
        }

        $last = array_key_last($content['abschnitte'] ?? []);

        foreach ($finished as $nr) {
            if ($nr > 1 && $last !== null && ! in_array($nr, $placed, true)) {
                $content['abschnitte'][$last]['bloecke'][] = ['typ' => 'grafik', 'nr' => $nr];
            }
        }

        return $content;
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
