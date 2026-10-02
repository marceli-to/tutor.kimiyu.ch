<?php

namespace App\Lessons\Ai;

use App\Lessons\Palettes;
use App\Models\Lesson;
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
     * @param  list<array{mime: string, data: string}>  $images
     */
    public static function analysis(Lesson $lesson, array $images): ModelRequest
    {
        $system = strtr(self::load('analyse'), [
            '{{PALETTEN}}' => self::paletteList(),
            '{{BEISPIEL}}' => self::json([
                'quelle' => ['lesbar' => true, 'problem' => null],
                'zusammenfassung' => '…',
                'hero_plan' => [
                    'muster' => 'regler',
                    'idee' => 'Ein Blatt im Querschnitt mit Pfeilen für Licht, CO₂ und Wasser (hinein) sowie Sauerstoff und Traubenzucker (hinaus). Drei Regler steuern Licht, CO₂ und Wasser. Die Pfeile hinaus werden so stark wie die knappste Zutat. Eine Anzeige nennt die Leistung und was gerade bremst, ein Satz darunter erklärt es.',
                ],
                'seite' => self::page(LessonFactory::fixture('fotosynthese')),
            ]),
        ]);

        $source = match (true) {
            $lesson->isFromTopic() => "Erstelle den Textteil einer Lernseite zum Thema «{$lesson->topic}». Es gibt keine Fotos, arbeite aus deinem Fachwissen (siehe «Nur ein Thema, keine Fotos»).",
            count($images) === 1 => 'Erstelle den Textteil einer Lernseite aus diesem Foto.',
            default => 'Erstelle den Textteil einer Lernseite aus diesen '.count($images).' Fotos.',
        };

        $prompt = implode("\n", array_filter([
            $source,
            '',
            "Fach: {$lesson->subject}",
            "Stufe: {$lesson->level}",
            'Interaktive Grafik: '.($lesson->with_hero ? 'ja, wenn ein Muster den Stoff sichtbar macht' : 'nein, von den Eltern abgewählt'),
            $lesson->notes ? "Hinweise der Eltern: {$lesson->notes}" : null,
        ], fn ($line) => $line !== null));

        return new ModelRequest(
            step: 'analyse',
            system: $system,
            prompt: $prompt,
            schema: Schemas::analysis(),
            maxTokens: config('lessons.max_tokens.analyse'),
            images: $images,
        );
    }

    /**
     * Zweiter Schritt: die Lernmodule aus Zusammenfassung und Textteil.
     *
     * @param  array<string, mixed>  $page
     */
    public static function modules(Lesson $lesson, array $page): ModelRequest
    {
        $system = strtr(self::load('module'), [
            '{{BEISPIEL}}' => self::json(['module' => LessonFactory::fixture('fotosynthese')['module']]),
        ]);

        return new ModelRequest(
            step: 'module',
            system: $system,
            prompt: implode("\n\n", [
                self::context($lesson),
                self::heroPlanText($lesson),
                "Textteil der Lernseite:\n".self::json($page),
            ]),
            schema: Schemas::modulesResult(),
            maxTokens: config('lessons.max_tokens.module'),
        );
    }

    /**
     * Neues Quiz mit anderen Fragen, der Rest der Seite bleibt.
     */
    public static function quiz(Lesson $lesson): ModelRequest
    {
        $system = strtr(self::load('module'), [
            '{{BEISPIEL}}' => self::json(['module' => LessonFactory::fixture('fotosynthese')['module']]),
        ]);

        return new ModelRequest(
            step: 'neu-quiz',
            system: $system,
            prompt: implode("\n\n", [
                self::context($lesson),
                'Erstelle nur ein neues Quiz mit genau 5 Fragen (IDs q1–q5). Frag andere Aspekte ab oder stell die Fragen anders als im bisherigen Quiz. Die Regeln für das Quiz gelten unverändert.',
                "Bisheriges Quiz:\n".self::json($lesson->content['module']['quiz']),
                "Textteil der Lernseite:\n".self::json(self::page($lesson->content)),
            ]),
            schema: Schemas::quizResult(),
            maxTokens: config('lessons.max_tokens.module'),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  'seite'|'module'  $part
     * @param  list<string>  $errors
     */
    public static function repair(Lesson $lesson, array $content, string $part, array $errors): ModelRequest
    {
        return new ModelRequest(
            step: "reparatur-{$part}",
            system: self::load('reparatur'),
            prompt: implode("\n\n", [
                self::context($lesson),
                "Gib diesen Teil korrigiert zurück: {$part}",
                "Diese Fehler müssen behoben werden:\n- ".implode("\n- ", $errors),
                "Ganze Lernseite:\n".self::json($content),
            ]),
            schema: Schemas::part($part),
            maxTokens: config('lessons.max_tokens.reparatur'),
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
            step: 'pruefung',
            system: self::load('pruefung'),
            prompt: implode("\n\n", [
                self::context($lesson),
                "Lernseite:\n".self::json($content),
            ]),
            schema: Schemas::checkResult(),
            maxTokens: config('lessons.max_tokens.pruefung'),
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
        unset($content['module']);

        return $content;
    }

    public static function hero(Lesson $lesson): ModelRequest
    {
        $examples = collect(LessonFactory::FIXTURES)
            ->map(fn (string $name) => '### '.LessonFactory::fixture($name)['meta']['titel']."\n\n```json\n".self::json(LessonFactory::fixture("$name.hero"))."\n```")
            ->implode("\n\n");

        return new ModelRequest(
            step: 'grafik',
            system: strtr(self::load('grafik'), ['{{BEISPIELE}}' => $examples]),
            prompt: implode("\n\n", [
                "Plan für die Grafik:\nMuster: {$lesson->hero_plan['muster']}\n{$lesson->hero_plan['idee']}",
                self::context($lesson),
                "Inhalt der Lernseite:\n".self::json($lesson->content),
            ]),
            schema: Schemas::hero(),
            maxTokens: config('lessons.max_tokens.grafik'),
        );
    }

    /**
     * @param  array<string, mixed>  $hero
     * @param  list<string>  $errors
     */
    public static function heroRepair(Lesson $lesson, array $hero, array $errors): ModelRequest
    {
        return new ModelRequest(
            step: 'grafik-reparatur',
            system: self::load('grafik-reparatur'),
            prompt: implode("\n\n", [
                "Plan für die Grafik:\nMuster: {$lesson->hero_plan['muster']}\n{$lesson->hero_plan['idee']}",
                "Diese Probleme müssen behoben werden:\n- ".implode("\n- ", $errors),
                "Bisheriges Ergebnis:\n".self::json($hero),
            ]),
            schema: Schemas::hero(),
            maxTokens: config('lessons.max_tokens.grafik'),
        );
    }

    private static function heroPlanText(Lesson $lesson): string
    {
        return $lesson->hero_plan
            ? "Plan für die Grafik:\nMuster: {$lesson->hero_plan['muster']}\n{$lesson->hero_plan['idee']}"
            : 'Diese Seite hat keine interaktive Grafik. Keine Quizfrage darf sich auf eine Grafik beziehen.';
    }

    private static function context(Lesson $lesson): string
    {
        return "Fach: {$lesson->subject}\nStufe: {$lesson->level}\n\nZusammenfassung des Stoffs:\n{$lesson->source_summary}";
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
