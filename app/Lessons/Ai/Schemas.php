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
     * Erster Schritt: Quelle lesen, Zusammenfassung, Plan für die Grafik und der Textteil der Seite.
     *
     * @return array<string, mixed>
     */
    public static function analysis(): array
    {
        return self::object([
            'quelle' => self::object([
                'lesbar' => ['type' => 'boolean', 'description' => 'false, wenn die Fotos unleserlich sind, das Thema unklar ist oder kein Schulstoff erkennbar ist'],
                'problem' => self::nullable(['type' => 'string', 'description' => 'Kurze Erklärung für die Eltern, was mit den Fotos oder dem Thema nicht stimmt']),
            ]),
            'zusammenfassung' => ['type' => 'string', 'description' => 'Neutrale, vollständige Zusammenfassung des Stoffs in eigenen Worten'],
            'hero_plan' => self::heroPlan(),
            'seite' => self::nullable(self::page()),
        ]);
    }

    /**
     * Zweiter Schritt: Quiz, Sortierspiel, Karteikarten, Lückentext.
     *
     * @return array<string, mixed>
     */
    public static function modulesResult(): array
    {
        return self::object(['module' => self::modules()]);
    }

    /**
     * Reparatur oder Prüfung eines Teils. Die ganze Seite ist für eine einzelne
     * strukturierte Antwort zu gross (die API lehnt die Grammatik ab), deshalb getrennt.
     *
     * @return array<string, mixed>
     */
    public static function part(string $part, bool $withChanges = false): array
    {
        $properties = $withChanges ? ['aenderungen' => self::changes()] : [];
        $properties[$part] = $part === 'seite' ? self::page() : self::modules();

        return self::object($properties);
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
     * Wird nicht so an die API geschickt, sondern in page() und modules() geteilt.
     *
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        $schema = self::page();
        $schema['properties']['module'] = self::modules();
        $schema['required'] = array_keys($schema['properties']);

        return $schema;
    }

    /**
     * Textteil der Seite: alles ausser den Modulen.
     *
     * @return array<string, mixed>
     */
    public static function page(): array
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
            'nachdenken' => self::object(['frage' => $text]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function modules(): array
    {
        $text = ['type' => 'string'];
        $texts = ['type' => 'array', 'items' => $text];
        $category = ['type' => 'string', 'enum' => ContentValidator::CATEGORIES];

        return self::object([
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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function changes(): array
    {
        return [
            'type' => 'array',
            'items' => self::object([
                'bereich' => ['type' => 'string', 'description' => 'z. B. «Quiz, Frage 3» oder «Sortierspiel»'],
                'aenderung' => ['type' => 'string', 'description' => 'Was geändert wurde und warum, ein Satz'],
            ]),
        ];
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
