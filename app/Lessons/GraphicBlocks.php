<?php

namespace App\Lessons;

use App\Models\Lesson;
use App\Models\LessonGraphic;

/**
 * Graphic blocks in the lesson content, as the edit view shows them and the update compares them.
 */
class GraphicBlocks
{
    /**
     * Fertige, sichtbare Grafiken 2 und 3 ohne Baustein zeigt die Seite am Ende des letzten Abschnitts.
     * In der Bearbeiten-Ansicht bekommen sie einen Baustein, damit die Eltern sie ausblenden können.
     * Er kommt in den letzten Abschnitt, der noch Platz hat (höchstens 4 Bausteine pro Abschnitt).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function withUnplaced(Lesson $lesson, array $content): array
    {
        $placed = self::numbers($content);

        $unplaced = $lesson->graphics
            ->filter(fn (LessonGraphic $graphic) => $graphic->position > 1
                && $graphic->graphic !== null
                && ! $graphic->hidden
                && ! in_array($graphic->position, $placed, true))
            ->pluck('position');

        foreach ($unplaced as $number) {
            $target = null;

            foreach ($content['sections'] ?? [] as $k => $section) {
                if (count($section['blocks'] ?? []) < 4) {
                    $target = $k;
                }
            }

            if ($target === null) {
                break;
            }

            $content['sections'][$target]['blocks'][] = ['type' => 'graphic', 'number' => $number];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<int>
     */
    public static function numbers(array $content): array
    {
        $numbers = [];

        foreach ($content['sections'] ?? [] as $section) {
            foreach ($section['blocks'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'graphic' && is_int($block['number'] ?? null)) {
                    $numbers[] = $block['number'];
                }
            }
        }

        return array_values(array_unique($numbers));
    }
}
