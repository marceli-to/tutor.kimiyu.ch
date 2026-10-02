<?php

namespace App\Lessons;

use App\Models\Attempt;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * Lernstand: pro Aufgabe (Quizfrage, Sortier-Begriff, Lücke) ein Status aus den letzten Antworten.
 *
 * - sitzt: die letzten zwei Antworten waren richtig
 * - fast:  die letzte Antwort war richtig, davor falsch oder nur ein Versuch (beim Quiz kann das geraten sein)
 * - ueben: die letzte Antwort war falsch
 * - offen: noch nie beantwortet
 */
class Progress
{
    public const STATUSES = ['sitzt', 'fast', 'ueben', 'offen'];

    /**
     * Alle Aufgaben einer Lernseite, die der Lernstand erfasst.
     *
     * @return list<array{module: string, id: string, text: string}>
     */
    public static function items(Lesson $lesson): array
    {
        $module = $lesson->content['modules'] ?? [];
        $items = [];

        foreach ($module['quiz'] ?? [] as $question) {
            $items[] = ['module' => 'quiz', 'id' => $question['id'], 'text' => $question['question']];
        }

        foreach ($module['sorting']['terms'] ?? [] as $term) {
            $items[] = ['module' => 'sorting', 'id' => $term['id'], 'text' => $term['text']];
        }

        foreach ($module['cloze']['segments'] ?? [] as $segment) {
            if (isset($segment['answers'])) {
                $items[] = ['module' => 'cloze', 'id' => $segment['id'], 'text' => 'Lücke: '.$segment['answers'][0]];
            }
        }

        return $items;
    }

    /**
     * Prüft eine Antwort gegen den Inhalt. Die Richtigkeit kommt nie vom Browser.
     *
     * @return bool|null null, wenn es die Aufgabe nicht (mehr) gibt
     */
    public static function check(Lesson $lesson, string $module, string $itemId, mixed $answer): ?bool
    {
        $content = $lesson->content['modules'] ?? [];

        $item = match ($module) {
            'quiz' => self::find($content['quiz'] ?? [], $itemId),
            'sorting' => self::find($content['sorting']['terms'] ?? [], $itemId),
            'cloze' => self::find($content['cloze']['segments'] ?? [], $itemId),
            default => null,
        };

        if ($item === null) {
            return null;
        }

        return match ($module) {
            'quiz' => is_int($answer) && $answer === $item['answer'],
            'sorting' => $answer === $item['category'],
            default => is_string($answer) && ClozeParser::isCorrect($answer, $item['answers']),
        };
    }

    /**
     * Status aller Aufgaben einer Lernseite für ein Kind.
     *
     * @return list<array{module: string, id: string, text: string, status: string}>
     */
    public static function forLesson(Child $child, Lesson $lesson): array
    {
        return self::withStatus(self::items($lesson), self::attempts($child, [$lesson->id])[$lesson->id] ?? []);
    }

    /**
     * Zusammenfassung pro Lernseite: Anzahl pro Status und die Aufgaben, die noch nicht sitzen.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return array<int, array{counts: array<string, int>, total: int, open: list<array{module: string, id: string, text: string, status: string}>}>
     */
    public static function summaries(Child $child, Collection $lessons): array
    {
        $attempts = self::attempts($child, array_values($lessons->map(fn (Lesson $lesson) => $lesson->id)->all()));
        $summaries = [];

        foreach ($lessons as $lesson) {
            $items = self::withStatus(self::items($lesson), $attempts[$lesson->id] ?? []);
            $counts = array_fill_keys(self::STATUSES, 0);

            foreach ($items as $item) {
                $counts[$item['status']]++;
            }

            // Zuerst was geübt werden muss, dann was fast sitzt
            $open = array_values(array_filter($items, fn ($item) => in_array($item['status'], ['ueben', 'fast'], true)));
            usort($open, fn ($a, $b) => ($a['status'] === 'ueben' ? 0 : 1) <=> ($b['status'] === 'ueben' ? 0 : 1));

            $summaries[$lesson->id] = [
                'counts' => $counts,
                'total' => count($items),
                'open' => $open,
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<array{module: string, id: string, text: string}>  $items
     * @param  list<Attempt>  $attempts  Antworten einer Lernseite, neueste zuerst
     * @return list<array{module: string, id: string, text: string, status: string}>
     */
    private static function withStatus(array $items, array $attempts): array
    {
        $byItem = [];
        foreach ($attempts as $attempt) {
            $byItem[$attempt->module.':'.$attempt->item_id][] = $attempt->correct;
        }

        return array_map(function (array $item) use ($byItem) {
            $last = array_slice($byItem[$item['module'].':'.$item['id']] ?? [], 0, 2);

            $status = match (true) {
                $last === [] => 'offen',
                ! $last[0] => 'ueben',
                count($last) === 2 && $last[1] => 'sitzt',
                default => 'fast',
            };

            return [...$item, 'status' => $status];
        }, $items);
    }

    /**
     * @param  list<int>  $lessonIds
     * @return array<int, list<Attempt>> Antworten pro Lernseite, neueste zuerst
     */
    private static function attempts(Child $child, array $lessonIds): array
    {
        $grouped = [];

        $query = $child->attempts()
            ->whereIn('lesson_id', $lessonIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        foreach ($query->get() as $attempt) {
            $grouped[$attempt->lesson_id][] = $attempt;
        }

        return $grouped;
    }

    /**
     * @param  array<int, mixed>  $list
     * @return array<string, mixed>|null
     */
    private static function find(array $list, string $id): ?array
    {
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }
}
