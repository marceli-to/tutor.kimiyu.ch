<?php

namespace App\Actions\Lessons;

use App\Lessons\ClozeParser;
use App\Lessons\ContentValidator;
use App\Lessons\GraphicBlocks;
use App\Models\Lesson;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Eltern korrigieren Texte, Quizfragen und Lösungen, bevor sie die Seite freigeben.
 */
class UpdateLessonContent
{
    /**
     * @param  array<string, mixed>  $content
     *
     * @throws ValidationException when the cloze markup or the content is invalid
     */
    public function handle(Lesson $lesson, array $content, ?string $clozeMarkup): void
    {
        // Der Lückentext wird als Text mit [Lücke|Alternative] bearbeitet
        if (is_array($content['modules']['cloze'] ?? null)) {
            try {
                $content['modules']['cloze']['segments'] = ClozeParser::parse((string) $clozeMarkup);

                // Die Herkunft wird nicht im Markup bearbeitet, also vom gespeicherten Lückentext übernehmen
                $origin = $lesson->content['modules']['cloze']['origin'] ?? null;
                if ($origin !== null) {
                    $content['modules']['cloze']['origin'] = $origin;
                }
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['clozeMarkup' => $e->getMessage()]);
            }
        }

        $validator = ContentValidator::make($content);

        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->toArray())
                    ->mapWithKeys(fn (array $messages, string $key) => ["content.{$key}" => $messages])
                    ->all(),
            );
        }

        // Verglichen wird mit dem, was die Bearbeiten-Ansicht gezeigt hat, inklusive der Grafiken ohne festen Platz
        $this->hideRemovedGraphics($lesson, GraphicBlocks::withUnplaced($lesson, $lesson->content), $content);

        $lesson->update([
            'title' => $content['meta']['title'],
            'content' => $content,
        ]);
    }

    /**
     * Entfernt die Mutter oder der Vater den Baustein einer Grafik, wird sie ausgeblendet (sie bleibt
     * gespeichert). Kommt der Baustein zurück, ist sie wieder sichtbar. Grafiken, die nie einen Baustein
     * hatten (Grafik 1, Grafiken am Ende des letzten Abschnitts), bleiben, wie sie sind.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function hideRemovedGraphics(Lesson $lesson, array $old, array $new): void
    {
        $before = GraphicBlocks::numbers($old);
        $after = GraphicBlocks::numbers($new);

        $removed = array_diff($before, $after);
        if ($removed !== []) {
            $lesson->graphics()->whereIn('position', $removed)->update(['hidden' => true]);
        }

        if ($after !== []) {
            $lesson->graphics()->whereIn('position', $after)->update(['hidden' => false]);
        }
    }
}
