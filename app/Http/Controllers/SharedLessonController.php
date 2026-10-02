<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Lessons\LessonView;
use App\Lessons\Progress;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Was das Kind über seinen Link sieht: nur freigegebene Lernseiten, nur lesen, ohne Login.
 */
class SharedLessonController extends Controller
{
    public function index(string $token): Response
    {
        $child = $this->child($token);

        $lessons = $child->lessons()
            ->where('status', LessonStatus::Published)
            ->latest('published_at')
            ->get();

        $progress = Progress::summaries($child, $lessons);

        return Inertia::render('shared/Index', [
            'token' => $token,
            'childName' => $child->name,
            'subjects' => $lessons
                // Freigegebene Lernseiten haben nach der Analyse immer ein Fach; trotzdem absichern
                ->groupBy(fn (Lesson $lesson) => (string) $lesson->subject)
                ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($lessons, string $subject) => [
                    'name' => $subject !== '' ? $subject : 'Allgemein',
                    'lessons' => $lessons->map(fn (Lesson $lesson) => [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'emoji' => $lesson->content['meta']['emoji'] ?? null,
                        'kernidee' => $lesson->content['meta']['kernidee'] ?? null,
                        'progress' => [
                            'sitzt' => $progress[$lesson->id]['counts']['sitzt'],
                            'total' => $progress[$lesson->id]['total'],
                        ],
                    ])->values(),
                ])
                ->values(),
        ]);
    }

    public function show(string $token, Lesson $lesson): Response
    {
        $child = $this->child($token);

        abort_unless($lesson->child_id === $child->id && $lesson->status === LessonStatus::Published, 404);

        return Inertia::render('shared/Show', [
            'token' => $token,
            'lesson' => LessonView::page($lesson),
        ]);
    }

    /**
     * Eine Antwort des Kindes. Der Server prüft sie selbst gegen den Inhalt.
     */
    public function answer(Request $request, string $token, Lesson $lesson): JsonResponse
    {
        $child = $this->child($token);

        abort_unless($lesson->child_id === $child->id && $lesson->status === LessonStatus::Published, 404);

        $data = $request->validate([
            'module' => ['required', Rule::in(['quiz', 'sortieren', 'lueckentext'])],
            'item_id' => ['required', 'string', 'max:20'],
            'answer' => ['present', 'nullable'],
        ]);

        $answer = $data['answer'];
        $correct = Progress::check($lesson, $data['module'], $data['item_id'], is_scalar($answer) ? $answer : null);

        abort_if($correct === null, 422, 'Diese Aufgabe gibt es nicht.');

        $child->attempts()->create([
            'lesson_id' => $lesson->id,
            'module' => $data['module'],
            'item_id' => $data['item_id'],
            'correct' => $correct,
        ]);

        return response()->json(['correct' => $correct]);
    }

    private function child(string $token): Child
    {
        // Falscher Link: 404 wie bei einer nicht vorhandenen Seite, damit nichts über gültige Links verraten wird
        return Child::query()->where('share_token', $token)->firstOrFail();
    }
}
