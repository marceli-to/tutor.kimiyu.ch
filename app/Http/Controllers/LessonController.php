<?php

namespace App\Http\Controllers;

use App\Lessons\HeroDocument;
use App\Lessons\Palettes;
use App\Models\Lesson;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LessonController extends Controller
{
    public function show(Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);

        return $this->render($lesson);
    }

    /**
     * Nur lokal: Lernseite ohne Login ansehen, für das Review von Darstellung und Modulen.
     */
    public function preview(Lesson $lesson): Response
    {
        abort_unless(app()->isLocal(), 404);

        return $this->render($lesson);
    }

    private function render(Lesson $lesson): Response
    {
        return Inertia::render('lessons/Show', [
            'lesson' => [
                'id' => $lesson->id,
                'subject' => $lesson->subject,
                'level' => $lesson->level,
                'content' => $lesson->content,
                'palette' => Palettes::get($lesson->content['meta']['palette'] ?? null),
                'hero' => $lesson->hero ? [
                    'url' => URL::signedRoute('lessons.hero', [
                        'lesson' => $lesson,
                        'v' => $lesson->updated_at?->timestamp,
                    ]),
                    'beschreibung' => $lesson->hero['beschreibung'] ?? '',
                ] : null,
            ],
        ]);
    }

    /**
     * Hero-Grafik als eigenständiges Dokument für das sandboxed iframe.
     * Signierte URL statt Session, weil das iframe keinen eigenen Origin hat.
     */
    public function hero(Lesson $lesson): HttpResponse
    {
        abort_unless($lesson->hero !== null, 404);

        return HeroDocument::response($lesson);
    }
}
