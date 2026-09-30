<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Lessons\LessonView;
use App\Models\Child;
use App\Models\Lesson;
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

        return Inertia::render('shared/Index', [
            'token' => $token,
            'childName' => $child->name,
            'subjects' => $lessons
                ->groupBy('subject')
                ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($lessons, string $subject) => [
                    'name' => $subject,
                    'lessons' => $lessons->map(fn (Lesson $lesson) => [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'emoji' => $lesson->content['meta']['emoji'] ?? null,
                        'kernidee' => $lesson->content['meta']['kernidee'] ?? null,
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

    private function child(string $token): Child
    {
        // Falscher Link: 404 wie bei einer nicht vorhandenen Seite, damit nichts über gültige Links verraten wird
        return Child::query()->where('share_token', $token)->firstOrFail();
    }
}
