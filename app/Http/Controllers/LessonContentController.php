<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\UpdateLessonContent;
use App\Lessons\ClozeParser;
use App\Lessons\GraphicBlocks;
use App\Lessons\Palettes;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Eltern korrigieren Texte, Quizfragen und Lösungen, bevor sie die Seite freigeben.
 */
class LessonContentController extends Controller
{
    public function edit(Lesson $lesson): Response
    {
        Gate::authorize('update', $lesson);

        abort_unless($lesson->isEditable(), 404);

        $cloze = $lesson->content['modules']['cloze'] ?? null;

        return Inertia::render('lessons/Edit', [
            'lesson' => [
                'id' => $lesson->id,
                'status' => $lesson->status->value,
                'childName' => $lesson->child->name,
                'content' => GraphicBlocks::withUnplaced($lesson, $lesson->content),
            ],
            'showOrigin' => ! $lesson->isFromTopic(),
            'clozeMarkup' => $cloze ? ClozeParser::toMarkup($cloze['segments']) : null,
            'graphicLabels' => $this->graphicLabels($lesson),
            'palettes' => collect(Palettes::all())
                ->map(fn (array $palette, string $key) => ['value' => $key, 'label' => $palette['label'], 'accent' => $palette['light']['accent']])
                ->values(),
        ]);
    }

    public function update(Request $request, Lesson $lesson, UpdateLessonContent $updateContent): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless($lesson->isEditable(), 404);

        $request->validate([
            'content' => ['required', 'array'],
            'clozeMarkup' => ['nullable', 'string', 'max:5000'],
        ]);

        $updateContent->handle($lesson, $request->input('content'), $request->input('clozeMarkup'));

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
}
