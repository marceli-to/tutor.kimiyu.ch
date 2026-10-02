<?php

namespace App\Lessons;

/**
 * Maps the German keys and values of lessons stored before 2026-10-02 to the English ones and back.
 *
 * Used by the migration that translates the stored data and to convert old fixtures. Keys are renamed
 * in every depth; values only in the fields that hold an enum (block type, origin, palette, pattern).
 * Free text is never touched.
 */
class LegacyKeys
{
    /** @var array<string, string> */
    public const KEYS = [
        'quelle' => 'source',
        'lesbar' => 'readable',
        'fach' => 'subject',
        'zusammenfassung' => 'summary',
        'ergaenzungen' => 'additions',
        'grafik_plaene' => 'graphic_plans',
        'nr' => 'number',
        'hinweis' => 'note',
        'muster' => 'pattern',
        'idee' => 'idea',
        'seite' => 'page',
        'titel' => 'title',
        'anleitung' => 'instructions',
        'thema' => 'topic',
        'kernidee' => 'key_idea',
        'abschnitte' => 'sections',
        'bloecke' => 'blocks',
        'typ' => 'type',
        'zusatz' => 'addendum',
        'eintraege' => 'entries',
        'absaetze' => 'paragraphs',
        'kategorie' => 'category',
        'kategorien' => 'categories',
        'probieren' => 'try_it',
        'experimente' => 'experiments',
        'alltagsvergleich' => 'everyday_comparison',
        'nachdenken' => 'reflect',
        'frage' => 'question',
        'module' => 'modules',
        'sortieren' => 'sorting',
        'karten' => 'flashcards',
        'lueckentext' => 'cloze',
        'optionen' => 'options',
        'loesung' => 'answer',
        'loesungen' => 'answers',
        'tipp' => 'hint',
        'erklaerung' => 'explanation',
        'herkunft' => 'origin',
        'begriffe' => 'terms',
        'vorne' => 'front',
        'hinten' => 'back',
        'segmente' => 'segments',
        'korrekturen' => 'corrections',
        'pfad' => 'path',
        'wert' => 'value',
        'bereich' => 'area',
        'aenderung' => 'change',
        'beschreibung' => 'description',
    ];

    /**
     * Values per (German) field name.
     *
     * @var array<string, array<string, string>>
     */
    public const VALUES = [
        'typ' => [
            'absatz' => 'paragraph',
            'formel' => 'formula',
            'fakten' => 'facts',
            'spalten' => 'columns',
            'box' => 'box',
            'grafik' => 'graphic',
        ],
        'herkunft' => [
            'foto' => 'photo',
            'ergaenzt' => 'added',
        ],
        'muster' => self::PATTERNS,
        'palette' => [
            'petrol' => 'petrol',
            'gruen' => 'green',
            'blau' => 'blue',
            'erde' => 'earth',
            'violett' => 'violet',
            'rot' => 'red',
            'anthrazit' => 'anthracite',
        ],
    ];

    /** @var array<string, string> */
    public const PATTERNS = [
        'regler' => 'sliders',
        'ansichten' => 'views',
        'schritte' => 'steps',
        'zeitstrahl' => 'timeline',
        'hotspots' => 'hotspots',
        'rechner' => 'calculator',
    ];

    /** @var array<string, string> */
    public const PURPOSES = ['neu' => 'new', 'pruefung' => 'exam'];

    /** @var array<string, string> */
    public const SCOPES = ['kurz' => 'short', 'normal' => 'normal', 'ausfuehrlich' => 'detailed'];

    /** @var array<string, string> */
    public const MODULES = ['quiz' => 'quiz', 'sortieren' => 'sorting', 'karten' => 'flashcards', 'lueckentext' => 'cloze'];

    /**
     * Step names are mapped part by part: «grafik-reparatur» → «graphic-repair», «grafik-2» → «graphic-2».
     *
     * @var array<string, string>
     */
    public const STEP_PARTS = [
        'warteschlange' => 'queued',
        'analyse' => 'analysis',
        'seite' => 'page',
        'module' => 'modules',
        'pruefung' => 'check',
        'grafik' => 'graphic',
        'reparatur' => 'repair',
        'neu' => 'regenerate',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function contentToEnglish(array $data): array
    {
        return self::translate($data, self::KEYS, self::VALUES);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function contentToGerman(array $data): array
    {
        $values = [];

        foreach (self::VALUES as $key => $map) {
            $values[self::KEYS[$key] ?? $key] = array_flip($map);
        }

        return self::translate($data, array_flip(self::KEYS), $values);
    }

    public static function stepToEnglish(?string $step): ?string
    {
        return self::translateStep($step, self::STEP_PARTS);
    }

    public static function stepToGerman(?string $step): ?string
    {
        return self::translateStep($step, array_flip(self::STEP_PARTS));
    }

    /**
     * A single value from one of the maps above; unknown values stay as they are.
     *
     * @param  array<string, string>  $map
     */
    public static function value(?string $value, array $map, bool $toGerman = false): ?string
    {
        if ($value === null) {
            return null;
        }

        $map = $toGerman ? array_flip($map) : $map;

        return $map[$value] ?? $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $keys
     * @param  array<string, array<string, string>>  $values  value maps per original key
     * @return array<array-key, mixed>
     */
    private static function translate(array $data, array $keys, array $values): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::translate($value, $keys, $values);
            } elseif (is_string($key) && is_string($value) && isset($values[$key][$value])) {
                $value = $values[$key][$value];
            }

            $result[is_string($key) ? ($keys[$key] ?? $key) : $key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $parts
     */
    private static function translateStep(?string $step, array $parts): ?string
    {
        if ($step === null) {
            return null;
        }

        return implode('-', array_map(fn (string $part) => $parts[$part] ?? $part, explode('-', $step)));
    }
}
