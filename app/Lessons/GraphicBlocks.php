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
     * The page shows finished, visible graphics 2 and 3 without a block at the end of the last section.
     * In the edit view they get a block, so the parents can hide them.
     * It goes into the last section that still has room (at most 4 blocks per section).
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
