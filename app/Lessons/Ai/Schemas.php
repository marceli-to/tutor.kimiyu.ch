<?php

namespace App\Lessons\Ai;

use App\Lessons\ContentValidator;
use App\Lessons\HeroPattern;
use App\Lessons\Palettes;

/**
 * JSON-Schemas für die strukturierte Ausgabe.
 *
 * Die API erzwingt nur Form und Typen (keine Längen oder Anzahlen). Alles Weitere
 * prüft der ContentValidator bzw. HeroValidator danach serverseitig.
 */
class Schemas
{
    /**
     * @return array<string, mixed>
     */
    public static function analysis(): array
    {
        return self::object([
            'quelle' => self::object([
                'lesbar' => ['type' => 'boolean', 'description' => 'false, wenn die Fotos unleserlich sind oder kein Schulstoff erkennbar ist'],
                'problem' => self::nullable(['type' => 'string', 'description' => 'Kurze Erklärung für die Eltern, was mit den Fotos nicht stimmt']),
            ]),
            'zusammenfassung' => ['type' => 'string', 'description' => 'Neutrale, sachliche Zusammenfassung des Stoffs in eigenen Worten'],
            'hero_plan' => self::heroPlan(),
            'inhalt' => self::nullable(self::content()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function repair(): array
    {
        return self::object(['inhalt' => self::content()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function check(): array
    {
        return self::object([
            'aenderungen' => [
                'type' => 'array',
                'items' => self::object([
                    'bereich' => ['type' => 'string', 'description' => 'z. B. «Quiz, Frage 3» oder «Sortierspiel»'],
                    'aenderung' => ['type' => 'string', 'description' => 'Was geändert wurde und warum, ein Satz'],
                ]),
            ],
            'inhalt' => self::content(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function hero(): array
    {
        return self::object([
            'muster' => ['type' => 'string', 'enum' => array_column(HeroPattern::cases(), 'value')],
            'beschreibung' => ['type' => 'string', 'description' => 'Ein bis zwei Sätze: Was zeigt die Grafik, was kann man tun? Wird als Alternativtext verwendet.'],
            'css' => ['type' => 'string'],
            'markup' => ['type' => 'string'],
            'script' => ['type' => 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function heroPlan(): array
    {
        return self::object([
            'muster' => ['type' => 'string', 'enum' => array_column(HeroPattern::cases(), 'value')],
            'idee' => ['type' => 'string', 'description' => 'Was die Grafik zeigt, welche Interaktion den Mechanismus sichtbar macht, welche Kategorie-Farbe (cat1–cat3) was bedeutet'],
        ]);
    }

    /**
     * Inhalt einer Lernseite, passend zu ContentValidator (Schema-Version 1).
     *
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        $text = ['type' => 'string'];
        $texts = ['type' => 'array', 'items' => $text];
        $category = ['type' => 'string', 'enum' => ContentValidator::CATEGORIES];

        $block = fn (string $type, array $properties) => self::object(['typ' => ['type' => 'string', 'const' => $type], ...$properties]);

        return self::object([
            'meta' => self::object([
                'titel' => ['type' => 'string', 'description' => 'Frage oder Formel, die neugierig macht'],
                'anleitung' => ['type' => 'string', 'description' => 'Eine Zeile: was man mit der Grafik tun kann'],
                'thema' => $text,
                'kernidee' => ['type' => 'string', 'description' => 'Was das Kind nach dem Lernen verstanden haben muss, ein Satz'],
                'emoji' => $text,
                'palette' => ['type' => 'string', 'enum' => Palettes::keys()],
            ]),
            'abschnitte' => [
                'type' => 'array',
                'items' => self::object([
                    'titel' => $text,
                    'bloecke' => [
                        'type' => 'array',
                        'items' => ['anyOf' => [
                            $block('absatz', ['text' => $text]),
                            $block('formel', ['text' => $text, 'zusatz' => self::nullable($text)]),
                            $block('fakten', ['eintraege' => ['type' => 'array', 'items' => self::object(['titel' => $text, 'text' => $text])]]),
                            $block('spalten', ['eintraege' => ['type' => 'array', 'items' => self::object([
                                'titel' => $text,
                                'kategorie' => $category,
                                'absaetze' => $texts,
                            ])]]),
                            $block('box', ['titel' => $text, 'absaetze' => $texts]),
                        ]],
                    ],
                ]),
            ],
            'probieren' => self::nullable(self::object([
                'experimente' => $texts,
                'alltagsvergleich' => self::nullable($text),
            ])),
            'module' => self::object([
                'quiz' => [
                    'type' => 'array',
                    'items' => self::object([
                        'id' => $text,
                        'frage' => $text,
                        'optionen' => $texts,
                        'loesung' => ['type' => 'integer', 'description' => 'Index der richtigen Option, 0-basiert'],
                        'tipp' => self::nullable($text),
                        'erklaerung' => $text,
                    ]),
                ],
                'sortieren' => self::nullable(self::object([
                    'anleitung' => self::nullable($text),
                    'kategorien' => ['type' => 'array', 'items' => self::object([
                        'id' => $category,
                        'label' => $text,
                        'sub' => self::nullable($text),
                    ])],
                    'begriffe' => ['type' => 'array', 'items' => self::object([
                        'id' => $text,
                        'text' => $text,
                        'kategorie' => $category,
                        'erklaerung' => self::nullable($text),
                    ])],
                ])),
                'karten' => self::nullable(self::object([
                    'anleitung' => self::nullable($text),
                    'eintraege' => ['type' => 'array', 'items' => self::object([
                        'id' => $text,
                        'vorne' => $text,
                        'hinten' => $text,
                    ])],
                ])),
                'lueckentext' => self::nullable(self::object([
                    'anleitung' => self::nullable($text),
                    'segmente' => ['type' => 'array', 'items' => ['anyOf' => [
                        self::object(['text' => $text]),
                        self::object(['id' => $text, 'loesungen' => $texts]),
                    ]]],
                ])),
            ]),
            'nachdenken' => self::object(['frage' => $text]),
        ]);
    }

    /**
     * Objekt mit allen Feldern als Pflichtfelder, wie es die strukturierte Ausgabe verlangt.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }
}
