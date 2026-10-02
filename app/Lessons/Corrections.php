<?php

namespace App\Lessons;

/**
 * Applies the corrections of the check step to a lesson.
 *
 * The check sends only what it changes (JSON pointer + new value) instead of rewriting the whole page.
 * Corrections to the same entry (same pointer without the last segment, e.g. /modules/quiz/0) belong together:
 * options and solution of a quiz question, for example, are only valid together. So each group is applied
 * as a whole and only kept if the page is valid afterwards; otherwise the whole group is discarded.
 *
 * Only single values (text, number, boolean) and lists of texts or numbers are replaced
 * (e.g. options, answers), each with the same type as before. Whole objects, modules, lists
 * of objects and empty fields (null) stay untouched, and no value is set to null.
 * IDs and the origin (`origin`) are never changed.
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
     * Groups corrections by entry (pointer without the last segment), in order of first appearance.
     * Invalid pointers each form a group of their own, so they don't take valid corrections down with them.
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
     * @return array<string, mixed>|null null if the path doesn't exist or must not be changed
     */
    private static function replace(array $content, string $pointer, string $value): ?array
    {
        // IDs and origin stay as they are: the origin decides what the parents see as added
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
     * Replaces the value at the end of $keys. Works on copies; the caller's page stays unchanged.
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

        // In lists the keys are numbers, in objects strings.
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
     * Converts the new value to the type of the previous one.
     *
     * Strings may come directly or as JSON strings; numbers, booleans and lists of strings as JSON.
     * Objects, lists of objects and empty fields (null) are never replaced.
     *
     * @return string|int|float|bool|list<string|int|float>|null null if the value doesn't fit
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
