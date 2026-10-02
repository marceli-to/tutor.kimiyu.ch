<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Lessons\ClozeParser;
use App\Lessons\ContentValidator;
use App\Lessons\Palettes;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Eltern korrigieren Texte, Quizfragen und Lösungen, bevor sie die Seite freigeben.
 */
class LessonContentController extends Controller
{
    public function edit(Lesson $lesson): Response
    {
        Gate::authorize('update', $lesson);

        abort_unless($this->editable($lesson), 404);

        $cloze = $lesson->content['modules']['cloze'] ?? null;

        return Inertia::render('lessons/Edit', [
            'lesson' => [
                'id' => $lesson->id,
                'status' => $lesson->status->value,
                'childName' => $lesson->child->name,
                'content' => $this->withUnplacedGraphics($lesson, $lesson->content),
            ],
            'showOrigin' => ! $lesson->isFromTopic(),
            'clozeMarkup' => $cloze ? ClozeParser::toMarkup($cloze['segments']) : null,
            'graphicLabels' => $this->graphicLabels($lesson),
            'palettes' => collect(Palettes::all())
                ->map(fn (array $palette, string $key) => ['value' => $key, 'label' => $palette['label'], 'accent' => $palette['light']['accent']])
                ->values(),
        ]);
    }

    public function update(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless($this->editable($lesson), 404);

        $request->validate([
            'content' => ['required', 'array'],
            'clozeMarkup' => ['nullable', 'string', 'max:5000'],
        ]);

        $content = $request->input('content');

        // Der Lückentext wird als Text mit [Lücke|Alternative] bearbeitet
        if (is_array($content['modules']['cloze'] ?? null)) {
            try {
                $content['modules']['cloze']['segments'] = ClozeParser::parse((string) $request->input('clozeMarkup'));

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
        $this->hideRemovedGraphics($lesson, $this->withUnplacedGraphics($lesson, $lesson->content), $content);

        $lesson->update([
            'title' => $content['meta']['title'],
            'content' => $content,
        ]);

        $this->toast('Gespeichert.');

        return back();
    }

    /**
     * Kurzer Text pro Grafik für die Bearbeiten-Ansicht: Beschreibung der fertigen Grafik,
     * sonst die Idee aus dem Plan, sonst der Wunsch der Eltern.
     *
     * @return array<int, string>
     */
    private function graphicLabels(Lesson $lesson): array
    {
        return $lesson->graphics
            ->mapWithKeys(fn (LessonGraphic $graphic) => [$graphic->position => Str::limit(
                (string) ($graphic->graphic['description'] ?? $graphic->plan['idea'] ?? $graphic->request ?? ''),
                120,
                '…',
            )])
            ->all();
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
        $before = $this->graphicBlocks($old);
        $after = $this->graphicBlocks($new);

        $removed = array_diff($before, $after);
        if ($removed !== []) {
            $lesson->graphics()->whereIn('position', $removed)->update(['hidden' => true]);
        }

        if ($after !== []) {
            $lesson->graphics()->whereIn('position', $after)->update(['hidden' => false]);
        }
    }

    /**
     * Fertige, sichtbare Grafiken 2 und 3 ohne Baustein zeigt die Seite am Ende des letzten Abschnitts.
     * In der Bearbeiten-Ansicht bekommen sie einen Baustein, damit die Eltern sie ausblenden können.
     * Er kommt in den letzten Abschnitt, der noch Platz hat (höchstens 4 Bausteine pro Abschnitt).
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function withUnplacedGraphics(Lesson $lesson, array $content): array
    {
        $placed = $this->graphicBlocks($content);

        $unplaced = $lesson->graphics
            ->filter(fn (LessonGraphic $graphic) => $graphic->position > 1
                && $graphic->graphic !== null
                && ! $graphic->hidden
                && ! in_array($graphic->position, $placed, true))
            ->pluck('position');

        foreach ($unplaced as $nr) {
            $target = null;

            foreach ($content['sections'] ?? [] as $k => $section) {
                if (count($section['blocks'] ?? []) < 4) {
                    $target = $k;
                }
            }

            if ($target === null) {
                break;
            }

            $content['sections'][$target]['blocks'][] = ['type' => 'graphic', 'number' => $nr];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<int>
     */
    private function graphicBlocks(array $content): array
    {
        $numbers = [];

        foreach ($content['sections'] ?? [] as $section) {
            foreach ($section['blocks'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'graphic' && is_int($block['number'] ?? null)) {
                    $numbers[] = $block['number'];
                }
            }
        }

        return array_values(array_unique($numbers));
    }

    private function editable(Lesson $lesson): bool
    {
        // Not while a part is being regenerated: the job would overwrite the changes
        return $lesson->content !== null
            && ! $lesson->isRegenerating()
            && in_array($lesson->status, [LessonStatus::Review, LessonStatus::Published], true);
    }
}
