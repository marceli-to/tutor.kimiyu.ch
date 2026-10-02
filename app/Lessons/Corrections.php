<?php

namespace App\Lessons;

/**
 * Wendet die Korrekturen des Prüf-Schritts auf eine Lernseite an.
 *
 * Die Prüfung schickt nur, was sie ändert (JSON-Pointer + neuer Wert), statt die ganze Seite neu zu schreiben.
 * Korrekturen am selben Eintrag (gleicher Pointer ohne letztes Segment, z. B. /modules/quiz/0) gehören zusammen:
 * Optionen und Lösung einer Quizfrage etwa sind nur gemeinsam gültig. Jede Gruppe wird darum als Ganzes
 * angewendet und nur behalten, wenn die Seite danach gültig ist; sonst wird die ganze Gruppe verworfen.
 *
 * Ersetzt werden nur einzelne Werte (Text, Zahl, Wahrheitswert) und Listen aus Texten oder Zahlen
 * (z. B. options, answers), jeweils mit dem gleichen Typ wie bisher. Ganze Objekte, Module, Listen
 * von Objekten und leere Felder (null) bleiben unangetastet, und kein Wert wird auf null gesetzt.
 * IDs und die Herkunft (`origin`) werden nie geändert.
 */
class Corrections
{
    /**
     * @param  array<string, mixed>  $content
     * @param  list<array{path: string, value: string, area: string, change: string}>  $corrections
     * @return array{content: array<string, mixed>, applied: list<array<string, string>>, rejected: list<array<string, string>>}
     */
    public static function apply(array $content, array $corrections): array
    {
        $applied = [];
        $rejected = [];

        foreach (self::groupByItem($corrections) as $group) {
            $candidate = $content;

            foreach ($group as $correction) {
                $candidate = self::replace($candidate, $correction['path'], $correction['value']);

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
     * @param  list<array{path: string, value: string, area: string, change: string}>  $corrections
     * @return list<list<array{path: string, value: string, area: string, change: string}>>
     */
    private static function groupByItem(array $corrections): array
    {
        $groups = [];

        foreach ($corrections as $i => $correction) {
            $pointer = $correction['path'];
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
        // IDs und Herkunft bleiben, wie sie sind: die Herkunft bestimmt, was die Eltern als ergänzt sehen
        if (! str_starts_with($pointer, '/') || str_ends_with($pointer, '/id') || str_ends_with($pointer, '/origin')) {
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

        $converted = self::convert($node[$index], $value);

        if ($converted === null) {
            return null;
        }

        $node[$index] = $converted;

        return $node;
    }

    /**
     * Wandelt den neuen Wert in den Typ des bisherigen um.
     *
     * Texte dürfen direkt oder als JSON-Text kommen; Zahlen, Wahrheitswerte und Listen aus Texten als JSON.
     * Objekte, Listen von Objekten und leere Felder (null) werden nie ersetzt.
     *
     * @return string|int|float|bool|list<string|int|float>|null null, wenn der Wert nicht passt
     */
    private static function convert(mixed $old, string $value): string|int|float|bool|array|null
    {
        if (is_string($old)) {
            $decoded = json_decode($value);

            return is_string($decoded) ? $decoded : $value;
        }

        $decoded = json_decode($value, true);

        return match (true) {
            is_int($old) => is_int($decoded) ? $decoded : null,
            is_float($old) => is_int($decoded) || is_float($decoded) ? $decoded : null,
            is_bool($old) => is_bool($decoded) ? $decoded : null,
            self::isScalarList($old) => self::isScalarList($decoded) && $decoded !== [] ? $decoded : null,
            default => null,
        };
    }

    /**
     * @phpstan-assert-if-true list<string|int|float> $value
     */
    private static function isScalarList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item) && ! is_float($item)) {
                return false;
            }
        }

        return true;
    }
}
