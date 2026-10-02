<?php

namespace App\Lessons;

/**
 * Wendet die Korrekturen des Prüf-Schritts auf eine Lernseite an.
 *
 * Die Prüfung schickt nur, was sie ändert (JSON-Pointer + neuer Wert), statt die ganze Seite neu zu schreiben.
 * Korrekturen am selben Eintrag (gleicher Pointer ohne letztes Segment, z. B. /module/quiz/0) gehören zusammen:
 * Optionen und Lösung einer Quizfrage etwa sind nur gemeinsam gültig. Jede Gruppe wird darum als Ganzes
 * angewendet und nur behalten, wenn die Seite danach gültig ist; sonst wird die ganze Gruppe verworfen.
 */
class Corrections
{
    /**
     * @param  array<string, mixed>  $content
     * @param  list<array{pfad: string, wert: string, bereich: string, aenderung: string}>  $corrections
     * @return array{content: array<string, mixed>, applied: list<array<string, string>>, rejected: list<array<string, string>>}
     */
    public static function apply(array $content, array $corrections): array
    {
        $applied = [];
        $rejected = [];

        foreach (self::groupByItem($corrections) as $group) {
            $candidate = $content;

            foreach ($group as $correction) {
                $candidate = self::replace($candidate, $correction['pfad'], $correction['wert']);

                if ($candidate === null) {
                    break;
                }
            }

            if ($candidate === null || ContentValidator::errors($candidate) !== []) {
                $rejected = [...$rejected, ...$group];

                continue;
            }

            $content = $candidate;
            $applied = [...$applied, ...$group];
        }

        return ['content' => $content, 'applied' => $applied, 'rejected' => $rejected];
    }

    /**
     * Gruppiert Korrekturen nach Eintrag (Pointer ohne letztes Segment), in der Reihenfolge des ersten Auftretens.
     * Ungültige Pointer bilden je eine eigene Gruppe, damit sie keine gültigen Korrekturen mitreissen.
     *
     * @param  list<array{pfad: string, wert: string, bereich: string, aenderung: string}>  $corrections
     * @return list<list<array{pfad: string, wert: string, bereich: string, aenderung: string}>>
     */
    private static function groupByItem(array $corrections): array
    {
        $groups = [];

        foreach ($corrections as $i => $correction) {
            $pointer = $correction['pfad'];
            $key = str_starts_with($pointer, '/')
                ? 'eintrag:'.substr($pointer, 0, (int) strrpos($pointer, '/'))
                : "ungueltig:$i";

            $groups[$key][] = $correction;
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null null, wenn der Pfad nicht existiert oder nicht geändert werden darf
     */
    private static function replace(array $content, string $pointer, string $value): ?array
    {
        if (! str_starts_with($pointer, '/') || str_ends_with($pointer, '/id')) {
            return null;
        }

        $keys = array_map(
            fn (string $key) => str_replace(['~1', '~0'], ['/', '~'], $key),
            explode('/', substr($pointer, 1)),
        );

        return self::replaceAt($content, $keys, $value);
    }

    /**
     * Ersetzt den Wert am Ende von $keys. Arbeitet auf Kopien, die Seite des Aufrufers bleibt unverändert.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $keys
     * @return array<array-key, mixed>|null
     */
    private static function replaceAt(array $node, array $keys, string $value): ?array
    {
        $key = array_shift($keys);

        if ($key === null) {
            return null;
        }

        // In Listen sind die Schlüssel Zahlen, in Objekten Texte.
        $index = array_is_list($node) && ctype_digit($key) ? (int) $key : $key;

        if (! array_key_exists($index, $node)) {
            return null;
        }

        if ($keys !== []) {
            if (! is_array($node[$index])) {
                return null;
            }

            $child = self::replaceAt($node[$index], $keys, $value);

            if ($child === null) {
                return null;
            }

            $node[$index] = $child;

            return $node;
        }

        if (is_string($node[$index])) {
            $node[$index] = $value;

            return $node;
        }

        $decoded = json_decode($value, true);

        if ($decoded === null && trim($value) !== 'null') {
            return null;
        }

        $node[$index] = $decoded;

        return $node;
    }
}
