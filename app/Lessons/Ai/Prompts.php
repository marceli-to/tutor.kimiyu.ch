<?php

namespace App\Lessons\Ai;

use App\Lessons\Palettes;
use App\Models\Lesson;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\File;

/**
 * Baut die Anfragen für die einzelnen Schritte.
 *
 * Die System-Prompts (resources/prompts) sind für alle Lernseiten gleich und werden gecacht.
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
                'inhalt' => LessonFactory::fixture('fotosynthese'),
            ]),
        ]);

        $prompt = implode("\n", array_filter([
            'Erstelle den Inhalt einer Lernseite aus '.(count($images) === 1 ? 'diesem Foto' : 'diesen '.count($images).' Fotos').'.',
            '',
            "Fach: {$lesson->subject}",
            "Stufe: {$lesson->level}",
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
     * @param  array<string, mixed>  $content
     * @param  list<string>  $errors
     */
    public static function repair(Lesson $lesson, array $content, array $errors): ModelRequest
    {
        return new ModelRequest(
            step: 'reparatur',
            system: self::load('reparatur'),
            prompt: implode("\n\n", [
                self::context($lesson),
                "Diese Fehler müssen behoben werden:\n- ".implode("\n- ", $errors),
                "Fehlerhafter Inhalt:\n".self::json($content),
            ]),
            schema: Schemas::repair(),
            maxTokens: config('lessons.max_tokens.reparatur'),
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function check(Lesson $lesson, array $content): ModelRequest
    {
        return new ModelRequest(
            step: 'pruefung',
            system: self::load('pruefung'),
            prompt: implode("\n\n", [
                self::context($lesson),
                "Inhalt der Lernseite:\n".self::json($content),
            ]),
            schema: Schemas::check(),
            maxTokens: config('lessons.max_tokens.pruefung'),
        );
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
