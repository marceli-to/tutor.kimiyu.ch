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
     * Die Herkunft (photo/added) sehen nur Eltern, und nur bei Lernseiten mit Fotos.
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
            'subject' => $lesson->subjectLabel(),
            'level' => $lesson->level,
            'content' => $content,
            'palette' => $lesson->content ? Palettes::get($lesson->content['meta']['palette'] ?? null) : null,
            // Grafik 1 steht oben, 2 und 3 an ihrem Block im Inhalt
            'hero' => $graphics[1] ?? null,
            'graphics' => $graphics,
        ];
    }

    /**
     * Nur fertige, nicht ausgeblendete Grafiken, nur URL und Beschreibung: Fehler, Wünsche und Pläne sehen nur die Eltern.
     *
     * @return array<int, array{url: string, description: string}>
     */
    private static function graphics(Lesson $lesson): array
    {
        return $lesson->graphics
            // Ausgeblendete Grafiken zeigt die Seite gar nicht, auch nicht am Ende
            ->filter(fn (LessonGraphic $graphic) => $graphic->graphic !== null && ! $graphic->hidden)
            ->mapWithKeys(fn (LessonGraphic $graphic) => [$graphic->position => [
                // Signiert, weil das iframe ohne eigenen Origin keine Session hat
                'url' => URL::signedRoute('lessons.graphic', [
                    'lesson' => $lesson,
                    'nr' => $graphic->position,
                    'v' => $graphic->updated_at?->timestamp,
                ]),
                'description' => $graphic->graphic['description'] ?? '',
            ]])
            ->all();
    }

    /**
     * Blöcke von Grafiken, die nicht fertig sind, entfernen. Eine fertige Grafik 2 oder 3 ohne Block
     * kommt ans Ende des letzten Abschnitts, damit sie nie verloren geht. Sections left empty are dropped.
     *
     * @param  array<string, mixed>  $content
     * @param  list<int>  $finished
     * @return array<string, mixed>
     */
    private static function placeGraphics(array $content, array $finished): array
    {
        $placed = [];

        foreach ($content['sections'] ?? [] as $k => $section) {
            $content['sections'][$k]['blocks'] = array_values(array_filter(
                $section['blocks'] ?? [],
                function (array $block) use ($finished, &$placed) {
                    if (($block['type'] ?? null) !== 'graphic') {
                        return true;
                    }

                    $nr = $block['number'] ?? null;

                    // Jede Grafik nur einmal zeigen
                    if (! in_array($nr, $finished, true) || in_array($nr, $placed, true)) {
                        return false;
                    }

                    $placed[] = $nr;

                    return true;
                },
            ));
        }

        // Without its graphic a section may be empty: the child never sees an empty heading
        if (isset($content['sections'])) {
            $content['sections'] = array_values(array_filter(
                $content['sections'],
                fn (array $section) => ($section['blocks'] ?? []) !== [],
            ));
        }

        $last = array_key_last($content['sections'] ?? []);

        foreach ($finished as $nr) {
            if ($nr > 1 && $last !== null && ! in_array($nr, $placed, true)) {
                $content['sections'][$last]['blocks'][] = ['type' => 'graphic', 'number' => $nr];
            }
        }

        return $content;
    }

    /**
     * Entfernt «origin» in jeder Tiefe.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function withoutOrigin(array $data): array
    {
        unset($data['origin']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withoutOrigin($value);
            }
        }

        return $data;
    }
}
