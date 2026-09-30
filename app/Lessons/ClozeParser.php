<?php

namespace App\Lessons;

use InvalidArgumentException;

/**
 * Wandelt Lückentext-Markup («Die Pflanze nimmt [CO₂|CO2] auf.») in Segmente um und zurück.
 *
 * Segmente: ['text' => '…'] oder ['id' => 'g1', 'loesungen' => ['CO₂', 'CO2']].
 */
class ClozeParser
{
    /**
     * @param  list<string>  $existingIds  IDs, die nicht vergeben werden dürfen
     * @return list<array<string, string|list<string>>>
     */
    public static function parse(string $markup, array $existingIds = []): array
    {
        $segments = [];
        $used = array_flip($existingIds);
        $counter = 0;

        $parts = preg_split('/(\[[^\[\]]*\])/u', $markup, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            throw new InvalidArgumentException('Der Lückentext enthält ungültige Zeichen.');
        }

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (str_starts_with($part, '[') && str_ends_with($part, ']')) {
                $solutions = array_values(array_filter(
                    array_map('trim', explode('|', mb_substr($part, 1, -1))),
                    fn (string $s) => $s !== '',
                ));

                if ($solutions === []) {
                    throw new InvalidArgumentException('Eine Lücke ist leer: '.$part);
                }

                do {
                    $id = 'g'.++$counter;
                } while (isset($used[$id]));
                $used[$id] = true;

                $segments[] = ['id' => $id, 'loesungen' => $solutions];

                continue;
            }

            if (str_contains($part, '[') || str_contains($part, ']')) {
                throw new InvalidArgumentException('Eine eckige Klammer ist nicht geschlossen.');
            }

            $segments[] = ['text' => $part];
        }

        return $segments;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public static function toMarkup(array $segments): string
    {
        return implode('', array_map(
            fn (array $s) => isset($s['loesungen'])
                ? '['.implode('|', $s['loesungen']).']'
                : $s['text'],
            $segments,
        ));
    }

    /**
     * Gleiche Normalisierung wie im Frontend: trimmen, Kleinbuchstaben, Leerraum zusammenfassen.
     */
    public static function normalize(string $answer): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($answer)));
    }

    /**
     * @param  list<string>  $solutions
     */
    public static function isCorrect(string $answer, array $solutions): bool
    {
        $normalized = self::normalize($answer);

        foreach ($solutions as $solution) {
            if (self::normalize($solution) === $normalized) {
                return true;
            }
        }

        return false;
    }
}
