<?php

namespace App\Lessons\Ai;

use App\Lessons\ContentValidator;
use App\Lessons\GraphicPattern;
use App\Lessons\Palettes;

/**
 * JSON-Schemas für die strukturierte Ausgabe.
 *
 * Die API erzwingt nur Form und Typen (keine Längen oder Anzahlen). Alles Weitere
 * prüft der ContentValidator bzw. GraphicValidator danach serverseitig.
 */
class Schemas
{
    /**
     * Erster Schritt: Quelle lesen, Fach, Zusammenfassung und Pläne für die Grafiken. Der Textteil kommt
     * separat (part('page')): zusammen lehnt die API die Grammatik als zu gross ab.
     *
     * @return array<string, mixed>
     */
    public static function analysis(): array
    {
        return self::object([
            'source' => self::object([
                'readable' => ['type' => 'boolean', 'description' => 'false, wenn die Fotos unleserlich sind, der Auftrag unklar ist oder kein Schulstoff erkennbar ist'],
                'problem' => self::nullable(['type' => 'string', 'description' => 'Kurze Erklärung für die Eltern, was mit den Fotos oder dem Auftrag nicht stimmt']),
            ]),
            'subject' => ['type' => 'string', 'description' => 'Schulfach, z. B. Biologie, Mathematik, Französisch'],
            'summary' => ['type' => 'string', 'description' => 'Neutrale, vollständige Zusammenfassung des Stoffs in eigenen Worten'],
            'additions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pro Ergänzung ein Satz: was auf den Fotos fehlte und was aus Fachwissen ergänzt wurde. Leer, wenn nichts ergänzt wurde oder es keine Fotos gibt.'],
            'graphic_plans' => ['type' => 'array', 'items' => self::object([
                'number' => ['type' => 'integer'],
                'plan' => self::nullable(self::graphicPlan()),
                'note' => self::nullable(['type' => 'string', 'description' => 'Warum der Wunsch nicht passt, ein Satz für die Eltern']),
            ])],
        ]);
    }

    /**
     * Zweiter Schritt: Quiz, Sortierspiel, Karteikarten, Lückentext.
     *
     * @return array<string, mixed>
     */
    public static function modulesResult(): array
    {
        return self::object(['modules' => self::modules()]);
    }

    /**
     * Neues Quiz für eine bestehende Seite.
     *
     * @return array<string, mixed>
     */
    public static function quizResult(): array
    {
        return self::object(['quiz' => self::quiz()]);
    }

    /**
     * Ein Teil der Seite: Textteil (Schritt «page» und Reparatur) oder Module (Reparatur). Die ganze
     * Seite ist für eine einzelne strukturierte Antwort zu gross (die API lehnt die Grammatik ab).
     *
     * @return array<string, mixed>
     */
    public static function part(string $part): array
    {
        return self::object([$part => $part === 'page' ? self::page() : self::modules()]);
    }

    /**
     * Prüfung: nur die Korrekturen, nicht die ganze Seite.
     *
     * @return array<string, mixed>
     */
    public static function checkResult(): array
    {
        return self::object([
            'corrections' => [
                'type' => 'array',
                'items' => self::object([
                    'path' => ['type' => 'string', 'description' => 'JSON-Pointer auf den Wert, z. B. /modules/quiz/2/answer oder /sections/0/blocks/1/text. Indizes 0-basiert.'],
                    'value' => ['type' => 'string', 'description' => 'Neuer Wert. Text direkt; Zahlen und Listen aus Texten als JSON, z. B. 1 oder ["A","B","C"]'],
                    'area' => ['type' => 'string', 'description' => 'z. B. «Quiz, Frage 3» oder «Sortierspiel»'],
                    'change' => ['type' => 'string', 'description' => 'Was geändert wurde und warum, ein Satz'],
                ]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function graphic(): array
    {
        return self::object([
            'pattern' => ['type' => 'string', 'enum' => array_column(GraphicPattern::cases(), 'value')],
            'description' => ['type' => 'string', 'description' => 'Ein bis zwei Sätze: Was zeigt die Grafik, was kann man tun? Wird als Alternativtext verwendet.'],
            'css' => ['type' => 'string'],
            'markup' => ['type' => 'string'],
            'script' => ['type' => 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function graphicPlan(): array
    {
        return self::object([
            'pattern' => ['type' => 'string', 'enum' => array_column(GraphicPattern::cases(), 'value')],
            'idea' => ['type' => 'string', 'description' => 'Was die Grafik zeigt, welche Interaktion den Mechanismus sichtbar macht, welche Kategorie-Farbe (cat1–cat3) was bedeutet'],
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
        $schema['properties']['modules'] = self::modules();
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
        $origin = self::origin();

        $block = fn (string $type, array $properties) => self::object(['type' => ['type' => 'string', 'const' => $type], ...$properties, 'origin' => $origin]);

        return self::object([
            'meta' => self::object([
                'title' => ['type' => 'string', 'description' => 'Frage oder Formel, die neugierig macht'],
                'instructions' => ['type' => 'string', 'description' => 'Eine Zeile: was man mit der Grafik tun kann; ohne Grafik: worum es geht'],
                'topic' => $text,
                'key_idea' => ['type' => 'string', 'description' => 'Was das Kind nach dem Lernen verstanden haben muss, ein Satz'],
                'emoji' => $text,
                'palette' => ['type' => 'string', 'enum' => Palettes::keys()],
            ]),
            'sections' => [
                'type' => 'array',
                'items' => self::object([
                    'title' => $text,
                    'blocks' => [
                        'type' => 'array',
                        'items' => ['anyOf' => [
                            $block('paragraph', ['text' => $text]),
                            $block('formula', ['text' => $text, 'addendum' => self::nullable($text)]),
                            $block('facts', ['entries' => ['type' => 'array', 'items' => self::object(['title' => $text, 'text' => $text])]]),
                            $block('columns', ['entries' => ['type' => 'array', 'items' => self::object([
                                'title' => $text,
                                'category' => $category,
                                'paragraphs' => $texts,
                            ])]]),
                            $block('box', ['title' => $text, 'paragraphs' => $texts]),
                            $block('graphic', ['number' => ['type' => 'integer']]),
                        ]],
                    ],
                ]),
            ],
            'try_it' => self::nullable(self::object([
                'experiments' => $texts,
                'everyday_comparison' => self::nullable($text),
            ])),
            'reflect' => self::object(['question' => $text]),
        ]);
    }

    /**
     * Fragen des Quiz.
     *
     * @return array<string, mixed>
     */
    private static function quiz(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'array',
            'items' => self::object([
                'id' => $text,
                'question' => $text,
                'options' => ['type' => 'array', 'items' => $text],
                'answer' => ['type' => 'integer', 'description' => 'Index der richtigen Option, 0-basiert'],
                'hint' => self::nullable($text),
                'explanation' => $text,
                'origin' => self::origin(),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function modules(): array
    {
        $text = ['type' => 'string'];
        $texts = ['type' => 'array', 'items' => $text];
        $category = ['type' => 'string', 'enum' => ContentValidator::CATEGORIES];
        $origin = self::origin();

        return self::object([
            // null, wenn die Eltern kein Quiz wollen
            'quiz' => self::nullable(self::quiz()),
            'sorting' => self::nullable(self::object([
                'instructions' => self::nullable($text),
                'categories' => ['type' => 'array', 'items' => self::object([
                    'id' => $category,
                    'label' => $text,
                    'sub' => self::nullable($text),
                ])],
                'terms' => ['type' => 'array', 'items' => self::object([
                    'id' => $text,
                    'text' => $text,
                    'category' => $category,
                    'explanation' => self::nullable($text),
                    'origin' => $origin,
                ])],
            ])),
            'flashcards' => self::nullable(self::object([
                'instructions' => self::nullable($text),
                'entries' => ['type' => 'array', 'items' => self::object([
                    'id' => $text,
                    'front' => $text,
                    'back' => $text,
                    'origin' => $origin,
                ])],
            ])),
            'cloze' => self::nullable(self::object([
                'instructions' => self::nullable($text),
                'segments' => ['type' => 'array', 'items' => ['anyOf' => [
                    self::object(['text' => $text]),
                    self::object(['id' => $text, 'answers' => $texts]),
                ]]],
                'origin' => $origin,
            ])),
        ]);
    }

    /**
     * Herkunft eines Bausteins: von den Fotos oder aus Fachwissen ergänzt.
     *
     * @return array<string, mixed>
     */
    private static function origin(): array
    {
        return ['type' => 'string', 'enum' => ContentValidator::ORIGINS, 'description' => 'added: nicht auf den Fotos, aus Fachwissen ergänzt'];
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
