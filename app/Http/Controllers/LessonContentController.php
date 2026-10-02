<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Lessons\ClozeParser;
use App\Lessons\ContentValidator;
use App\Lessons\Palettes;
use App\Models\Lesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        $cloze = $lesson->content['module']['lueckentext'] ?? null;

        return Inertia::render('lessons/Edit', [
            'lesson' => [
                'id' => $lesson->id,
                'status' => $lesson->status->value,
                'childName' => $lesson->child->name,
                'content' => $lesson->content,
            ],
            'showOrigin' => ! $lesson->isFromTopic(),
            'clozeMarkup' => $cloze ? ClozeParser::toMarkup($cloze['segmente']) : null,
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
        if (is_array($content['module']['lueckentext'] ?? null)) {
            try {
                $content['module']['lueckentext']['segmente'] = ClozeParser::parse((string) $request->input('clozeMarkup'));

                // Die Herkunft wird nicht im Markup bearbeitet, also vom gespeicherten Lückentext übernehmen
                $origin = $lesson->content['module']['lueckentext']['herkunft'] ?? null;
                if ($origin !== null) {
                    $content['module']['lueckentext']['herkunft'] = $origin;
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

        $lesson->update([
            'title' => $content['meta']['titel'],
            'content' => $content,
        ]);

        $this->toast('Gespeichert.');

        return back();
    }

    private function editable(Lesson $lesson): bool
    {
        return $lesson->content !== null
            && in_array($lesson->status, [LessonStatus::Review, LessonStatus::Published], true);
    }
}
