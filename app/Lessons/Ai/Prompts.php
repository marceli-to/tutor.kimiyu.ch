<?php

namespace App\Lessons\Ai;

use App\Lessons\GraphicPattern;
use App\Lessons\LessonView;
use App\Lessons\Palettes;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\File;

/**
 * Baut die Anfragen für die einzelnen Schritte.
 *
 * Die System-Prompts (resources/prompts) sind für alle Lernseiten gleich.
 * Alles, was sich pro Lernseite ändert, steht im Benutzer-Prompt. Der Name des Kindes wird nie mitgeschickt.
 */
class Prompts
{
    /**
     * Erster Schritt: Quelle prüfen, Zusammenfassung, Ergänzungen und Pläne für die Grafiken.
     * Der Textteil kommt in einem eigenen Aufruf (pageRequest), zusammen ist das Schema für die API zu gross.
     *
     * @param  list<array{mime: string, data: string}>  $images
     */
    public static function analysis(Lesson $lesson, array $images): ModelRequest
    {
        $system = self::analysisSystem([
            'source' => ['readable' => true, 'problem' => null],
            'subject' => 'Biologie',
            'summary' => '…',
            'additions' => [],
            'graphic_plans' => [[
                'number' => 1,
                'plan' => [
                    'pattern' => 'sliders',
                    'idea' => 'Ein Blatt im Querschnitt mit Pfeilen für Licht, CO₂ und Wasser (hinein) sowie Sauerstoff und Traubenzucker (hinaus). Drei Regler steuern Licht, CO₂ und Wasser. Die Pfeile hinaus werden so stark wie die knappste Zutat. Eine Anzeige nennt die Leistung und was gerade bremst, ein Satz darunter erklärt es.',
                ],
                'note' => null,
            ]],
        ]);

        return new ModelRequest(
            step: 'analysis',
            system: $system,
            prompt: implode("\n\n", [
                'Schritt 1 von 2: Liefere nur `source`, `subject`, `summary`, `additions` und `graphic_plans`. Den Textteil (`page`) schreibst du im zweiten Schritt.',
                self::sourceLines($lesson, count($images)),
            ]),
            schema: Schemas::analysis(),
            maxTokens: config('lessons.max_tokens.analysis'),
            images: $images,
        );
    }

    /**
     * Zweiter Teil der Analyse: der Textteil der Seite, mit denselben Fotos (damit die Begriffe dem Buch folgen),
     * der gespeicherten Zusammenfassung, den Ergänzungen und den Plänen für die Grafiken.
     *
     * @param  list<array{mime: string, data: string}>  $images
     */
    public static function pageRequest(Lesson $lesson, array $images): ModelRequest
    {
        $system = self::analysisSystem(['page' => self::page(LessonFactory::fixture('fotosynthese'))]);

        $parts = [
            'Schritt 2 von 2: Liefere nur `page`, den Textteil der Lernseite. Quelle, Zusammenfassung, Ergänzungen und die Pläne für die Grafiken stehen fest (unten), halte dich daran.',
            self::sourceLines($lesson, count($images)),
            "Zusammenfassung des Stoffs:\n{$lesson->source_summary}",
        ];

        if ($lesson->additions) {
            $parts[] = "Ergänzt (nicht auf den Fotos), diese Bausteine haben `origin: \"added\"`:\n- ".implode("\n- ", $lesson->additions);
        }

        $parts[] = self::pagePlanText($lesson);

        return new ModelRequest(
            step: 'page',
            system: $system,
            prompt: implode("\n\n", $parts),
            schema: Schemas::part('page'),
            maxTokens: config('lessons.max_tokens.page'),
            images: $images,
        );
    }

    /**
     * Gemeinsames Regelwerk beider Aufrufe der Analyse, mit dem Beispiel für die Felder des jeweiligen Aufrufs.
     *
     * @param  array<string, mixed>  $example
     */
    private static function analysisSystem(array $example): string
    {
        return strtr(self::load('analysis'), [
            '{{PALETTEN}}' => self::paletteList(),
            '{{BEISPIEL}}' => self::json($example),
        ]);
    }

    /**
     * Quelle und Auftrag, für beide Aufrufe der Analyse gleich.
     */
    private static function sourceLines(Lesson $lesson, int $count): string
    {
        $photos = $count === 1 ? 'diesem Foto' : "diesen {$count} Fotos";

        $source = match (true) {
            $count === 0 && $lesson->prompt !== null => 'Erstelle eine Lernseite nach dem Auftrag der Eltern. Es gibt keine Fotos, arbeite aus deinem Fachwissen (siehe «Nur ein Auftrag, keine Fotos»).',
            // Alte Lernseite aus einem Thema (vor dem Auftrag), z. B. beim erneuten Versuch
            $count === 0 => "Erstelle eine Lernseite zum Thema «{$lesson->topic}». Es gibt keine Fotos, arbeite aus deinem Fachwissen (siehe «Nur ein Auftrag, keine Fotos»).",
            $lesson->prompt !== null => "Erstelle eine Lernseite aus {$photos}. Die Fotos sind der Rahmen, der Auftrag der Eltern setzt den Fokus (siehe «Fotos und Auftrag»).",
            default => "Erstelle eine Lernseite aus {$photos}.",
        };

        return implode("\n", array_filter([
            $source,
            $count > 1 ? 'Die Fotos sind in der Reihenfolge der Seiten: Foto 1 ist die erste Seite.' : null,
            '',
            // Ohne Fach erkennt es die Analyse; im zweiten Aufruf steht es schon fest
            'Fach: '.($lesson->subject ?? 'unbekannt, erkenne es aus den Fotos oder dem Auftrag'),
            "Stufe: {$lesson->level}",
            self::purposeLine($lesson),
            self::scopeLine($lesson),
            self::graphicsWish($lesson),
            self::parentInstruction($lesson),
        ], fn ($line) => $line !== null));
    }

    /**
     * Zweiter Schritt: die Lernmodule aus Zusammenfassung und Textteil.
     *
     * @param  array<string, mixed>  $page
     */
    public static function modules(Lesson $lesson, array $page): ModelRequest
    {
        $system = strtr(self::load('modules'), [
            '{{BEISPIEL}}' => self::json(['modules' => LessonFactory::fixture('fotosynthese')['modules']]),
        ]);

        return new ModelRequest(
            step: 'modules',
            system: $system,
            prompt: implode("\n\n", [
                self::context($lesson),
                self::graphicPlansText($lesson, $page),
                "Textteil der Lernseite:\n".self::json($page),
            ]),
            schema: Schemas::modulesResult(),
            maxTokens: config('lessons.max_tokens.modules'),
        );
    }

    /**
     * Neues Quiz mit anderen Fragen, der Rest der Seite bleibt.
     */
    public static function quiz(Lesson $lesson): ModelRequest
    {
        $system = strtr(self::load('modules'), [
            '{{BEISPIEL}}' => self::json(['modules' => LessonFactory::fixture('fotosynthese')['modules']]),
        ]);

        $count = self::scopeCounts($lesson)['quiz'];

        return new ModelRequest(
            step: 'regenerate-quiz',
            system: $system,
            prompt: implode("\n\n", [
                self::context($lesson),
                "Erstelle nur ein neues Quiz mit genau {$count} Fragen (IDs q1–q{$count}). Frag andere Aspekte ab oder stell die Fragen anders als im bisherigen Quiz. Die Regeln für das Quiz gelten unverändert.",
                "Bisheriges Quiz:\n".self::json($lesson->content['modules']['quiz']),
                "Textteil der Lernseite:\n".self::json(self::page($lesson->content)),
            ]),
            schema: Schemas::quizResult(),
            maxTokens: config('lessons.max_tokens.modules'),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  'page'|'modules'  $part
     * @param  list<string>  $errors
     */
    public static function repair(Lesson $lesson, array $content, string $part, array $errors): ModelRequest
    {
        return new ModelRequest(
            step: "repair-{$part}",
            system: self::load('repair'),
            prompt: implode("\n\n", array_filter([
                self::context($lesson),
                // The text part places the graphic blocks, so it needs the plans
                $part === 'page' ? self::graphicPlansText($lesson, $content) : null,
                "Gib diesen Teil korrigiert zurück: {$part}",
                "Diese Fehler müssen behoben werden:\n- ".implode("\n- ", $errors),
                "Ganze Lernseite:\n".self::json($content),
            ])),
            schema: Schemas::part($part),
            maxTokens: config('lessons.max_tokens.repair'),
        );
    }

    /**
     * Prüfung der ganzen Seite in einem Aufruf. Die Antwort enthält nur Korrekturen.
     *
     * @param  array<string, mixed>  $content
     */
    public static function check(Lesson $lesson, array $content): ModelRequest
    {
        return new ModelRequest(
            step: 'check',
            system: self::load('check'),
            prompt: implode("\n\n", [
                self::context($lesson),
                "Lernseite:\n".self::json($content),
            ]),
            schema: Schemas::checkResult(),
            maxTokens: config('lessons.max_tokens.check'),
        );
    }

    /**
     * Textteil einer Seite: alles ausser den Modulen.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function page(array $content): array
    {
        unset($content['modules']);

        return $content;
    }

    /**
     * Eine Grafik nach ihrem Plan. Grafik 1 steht oben, 2 und 3 im Abschnitt mit ihrem Baustein.
     */
    public static function graphic(Lesson $lesson, LessonGraphic $graphic): ModelRequest
    {
        // Ein Beispiel reicht als Massstab; jedes weitere kostet nur Input
        $example = '### '.LessonFactory::fixture('fotosynthese')['meta']['title']."\n\n```json\n".self::json(LessonFactory::fixture('fotosynthese.graphic'))."\n```";

        $place = null;

        if ($graphic->position > 1) {
            $title = self::graphicSection($lesson->content ?? [], $graphic->position);
            $place = 'Diese Grafik steht '.($title !== null ? "im Abschnitt «{$title}»" : 'weiter unten auf der Seite').' neben dem Text, nicht oben auf der Seite.';
        }

        return new ModelRequest(
            step: 'graphic',
            system: strtr(self::load('graphic'), ['{{BEISPIELE}}' => $example]),
            prompt: implode("\n\n", array_filter([
                self::graphicPlanText($graphic),
                $place,
                // Die Grafik sieht das Kind: ohne Herkunft und ohne Liste der Ergänzungen
                self::context($lesson, forChild: true),
                "Inhalt der Lernseite:\n".self::json(LessonView::withoutOrigin($lesson->content ?? [])),
            ])),
            schema: Schemas::graphic(),
            maxTokens: config('lessons.max_tokens.graphic'),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $errors
     */
    public static function graphicRepair(LessonGraphic $graphic, array $result, array $errors): ModelRequest
    {
        return new ModelRequest(
            step: 'graphic-repair',
            system: self::load('graphic-repair'),
            prompt: implode("\n\n", [
                self::graphicPlanText($graphic),
                "Diese Probleme müssen behoben werden:\n- ".implode("\n- ", $errors),
                "Bisheriges Ergebnis:\n".self::json($result),
            ]),
            schema: Schemas::graphic(),
            maxTokens: config('lessons.max_tokens.graphic'),
        );
    }

    private static function graphicPlanText(LessonGraphic $graphic): string
    {
        return "Plan für die Grafik:\nMuster: {$graphic->plan['pattern']}\n{$graphic->plan['idea']}";
    }

    /**
     * Was die Eltern zu den Grafiken gewählt haben, für die Analyse.
     */
    private static function graphicsWish(Lesson $lesson): string
    {
        return match ($lesson->graphics_mode) {
            'none' => 'Grafiken: keine (von den Eltern abgewählt)',
            'custom' => implode("\n", [
                'Grafiken nach Wunsch der Eltern:',
                ...$lesson->graphics()->get()->map(function (LessonGraphic $graphic) {
                    $pattern = GraphicPattern::tryFrom((string) $graphic->pattern);

                    return "Grafik {$graphic->position}: {$graphic->request}"
                        .($pattern ? " (Muster: {$pattern->value} – {$pattern->label()})" : '');
                }),
            ]),
            default => 'Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht',
        };
    }

    /**
     * Die Pläne aus dem ersten Schritt, für den Textteil: wo Bausteine «graphic» hingehören
     * und ob es Grafik 1 für «meta.instructions» und «try_it» gibt.
     */
    private static function pagePlanText(Lesson $lesson): string
    {
        $graphics = $lesson->graphics()->whereNotNull('plan')->orderBy('position')->get();

        $plans = $graphics->map(fn (LessonGraphic $graphic) => 'Grafik '.$graphic->position
            .($graphic->position === 1 ? ' (oben)' : ' (Baustein `graphic` im passenden Abschnitt)')
            .": Muster {$graphic->plan['pattern']}\n{$graphic->plan['idea']}");

        $lines = $plans->isNotEmpty() ? ["Geplante Grafiken:\n\n".$plans->implode("\n\n")] : [];

        if (! $graphics->contains('position', 1)) {
            $lines[] = 'Diese Seite hat keine Grafik 1: `try_it` ist null, `meta.instructions` sagt in einem Satz, worum es geht.';
        }

        $lines[] = $graphics->contains(fn (LessonGraphic $graphic) => $graphic->position > 1)
            ? 'Setze für jede geplante Grafik ab Nummer 2 genau einen Baustein `graphic` mit ihrer `number`, für andere Nummern keinen.'
            : 'Kein Baustein `graphic`.';

        return implode("\n\n", $lines);
    }

    /**
     * Die geplanten Grafiken mit ihrem Platz auf der Seite, für die Module (and the repair of the text part).
     *
     * @param  array<string, mixed>  $page
     */
    private static function graphicPlansText(Lesson $lesson, array $page): string
    {
        $plans = $lesson->graphics()->whereNotNull('plan')->get()->map(function (LessonGraphic $graphic) use ($page) {
            $place = $graphic->position === 1
                ? 'oben'
                : (($title = self::graphicSection($page, $graphic->position)) !== null ? "im Abschnitt «{$title}»" : 'weiter unten auf der Seite');

            return "Grafik {$graphic->position} ({$place}): Muster {$graphic->plan['pattern']}\n{$graphic->plan['idea']}";
        });

        return $plans->isNotEmpty()
            ? "Geplante Grafiken:\n\n".$plans->implode("\n\n")
            : 'Diese Seite hat keine interaktive Grafik. Keine Quizfrage darf sich auf eine Grafik beziehen.';
    }

    /**
     * Titel des Abschnitts, in dem der Baustein für Grafik $number steht.
     *
     * @param  array<string, mixed>  $content
     */
    public static function graphicSection(array $content, int $number): ?string
    {
        foreach ($content['sections'] ?? [] as $section) {
            foreach ($section['blocks'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'graphic' && ($block['number'] ?? null) === $number) {
                    return $section['title'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Gemeinsamer Teil aller Schritte nach der Analyse: Fach, Stufe, Zweck, Umfang, Module, Auftrag, Zusammenfassung und Ergänzungen.
     * Für Teile, die das Kind sieht ($forChild, z. B. die Grafik), ohne Ergänzungen und ohne Markierung «(ergänzt)»;
     * Zweck, Umfang und Module betreffen dort nichts und bleiben weg.
     */
    private static function context(Lesson $lesson, bool $forChild = false): string
    {
        $header = implode("\n", array_filter([
            "Fach: {$lesson->subject}",
            "Stufe: {$lesson->level}",
            $forChild ? null : self::purposeLine($lesson),
            $forChild ? null : self::scopeLine($lesson),
            $forChild ? null : self::modulesLine($lesson),
            // Damit «keine Fotos → immer added» in modules.md greift
            $lesson->isFromTopic() ? 'Quelle: keine Fotos (Auftrag oder Thema)' : null,
            self::parentInstruction($lesson),
        ], fn ($line) => $line !== null));

        $summary = $forChild
            ? (string) preg_replace('/\s*\(ergänzt\)/u', '', (string) $lesson->source_summary)
            : $lesson->source_summary;

        $parts = [$header, "Zusammenfassung des Stoffs:\n{$summary}"];

        if ($lesson->additions && ! $forChild) {
            $parts[] = "Ergänzt (nicht auf den Fotos):\n- ".implode("\n- ", $lesson->additions);
        }

        return implode("\n\n", $parts);
    }

    private static function purposeLine(Lesson $lesson): string
    {
        return 'Zweck: '.($lesson->purpose === 'exam' ? 'Prüfungsvorbereitung' : 'Neuer Stoff');
    }

    private static function scopeLine(Lesson $lesson): string
    {
        $label = match ($lesson->scope) {
            'short' => 'kurz',
            'detailed' => 'ausführlich',
            default => $lesson->scope,
        };

        return "Umfang: {$label} (".self::scopeCounts($lesson)['sections'].' Abschnitte)';
    }

    /**
     * Die erlaubten Lernmodule mit den Anzahlen für den Umfang; alte Lernseiten ohne Liste erlauben alle.
     */
    private static function modulesLine(Lesson $lesson): string
    {
        $counts = self::scopeCounts($lesson);

        $labels = [
            'quiz' => "Quiz (genau {$counts['quiz']} Fragen)",
            'sorting' => "Sortierspiel ({$counts['terms']} Begriffe)",
            'flashcards' => "Karteikarten ({$counts['flashcards']} Karten)",
            'cloze' => "Lückentext ({$counts['gaps']} Lücken)",
        ];

        return 'Erlaubte Lernmodule: '.implode(', ', array_intersect_key($labels, array_flip($lesson->allowedModules())));
    }

    /**
     * @return array{sections: string, quiz: int, flashcards: string, terms: string, gaps: string}
     */
    private static function scopeCounts(Lesson $lesson): array
    {
        return config("lessons.scope.{$lesson->scope}") ?? config('lessons.scope.normal');
    }

    /**
     * Auftrag der Eltern; alte Lernseiten haben stattdessen Hinweise.
     */
    private static function parentInstruction(Lesson $lesson): ?string
    {
        return match (true) {
            $lesson->prompt !== null => "Auftrag der Eltern: {$lesson->prompt}",
            (bool) $lesson->notes => "Hinweise der Eltern: {$lesson->notes}",
            default => null,
        };
    }

    private static function paletteList(): string
    {
        return collect(Palettes::all())
            ->map(fn (array $palette, string $key) => "- `{$key}`: {$palette['label']}")
            ->implode("\n");
    }

    private static function load(string $name): string
    {
        return File::get(resource_path("prompts/{$name}.md"));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
