<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\CreateLesson;
use App\Actions\Lessons\DeleteLesson;
use App\Actions\Lessons\PublishLesson;
use App\Actions\Lessons\RegenerateGraphic;
use App\Actions\Lessons\RegenerateQuiz;
use App\Actions\Lessons\RetryLesson;
use App\Actions\Lessons\UnpublishLesson;
use App\Enums\LessonStatus;
use App\Http\PageData\CreateLessonPage;
use App\Http\PageData\LessonPage;
use App\Http\Requests\StoreLessonRequest;
use App\Lessons\GenerationPipeline;
use App\Lessons\HeroDocument;
use App\Models\Lesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LessonController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('lessons/Create', (new CreateLessonPage($request->user()))->props());
    }

    public function store(StoreLessonRequest $request, CreateLesson $createLesson): RedirectResponse
    {
        $lesson = $createLesson->handle($request->user(), $request->validated(), $request->childLevel());

        return to_route('lessons.show', $lesson);
    }

    public function show(Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);

        return Inertia::render('lessons/Show', (new LessonPage($lesson, parent: true))->props());
    }

    public function destroy(Lesson $lesson, DeleteLesson $deleteLesson): RedirectResponse
    {
        Gate::authorize('delete', $lesson);

        $deleteLesson->handle($lesson);

        $this->toast('Lernseite gelöscht.');

        return to_route('dashboard');
    }

    /**
     * Freigeben: Das Kind sieht die Seite über seinen Link.
     */
    public function publish(Lesson $lesson, PublishLesson $publishLesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless($lesson->canBePublished(), 422, 'Diese Lernseite kann nicht freigegeben werden.');

        $publishLesson->handle($lesson);

        $this->toast("Freigegeben. {$lesson->child->name} sieht die Seite jetzt über den Link.");

        return back();
    }

    public function unpublish(Lesson $lesson, UnpublishLesson $unpublishLesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless($lesson->status === LessonStatus::Published, 422);

        $unpublishLesson->handle($lesson);

        $this->toast('Die Seite ist für das Kind nicht mehr sichtbar.');

        return back();
    }

    /**
     * Nur das Quiz neu erstellen lassen.
     */
    public function regenerate(Lesson $lesson, string $part, RegenerateQuiz $regenerateQuiz): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRegenerate($lesson, $part), 422, 'Das geht bei dieser Lernseite gerade nicht.');

        $regenerateQuiz->handle($lesson);

        return to_route('lessons.show', $lesson);
    }

    /**
     * Nur eine Grafik neu erstellen lassen; die anderen bleiben.
     */
    public function regenerateGraphic(Lesson $lesson, int $nr, RegenerateGraphic $regenerateGraphic): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRegenerate($lesson, 'graphic', $nr), 422, 'Das geht bei dieser Lernseite gerade nicht.');

        $regenerateGraphic->handle($lesson, $nr);

        return to_route('lessons.show', $lesson);
    }

    public function retry(Lesson $lesson, RetryLesson $retryLesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRetry($lesson), 422, 'Diese Lernseite kann nicht nochmals erstellt werden.');

        $retryLesson->handle($lesson);

        return to_route('lessons.show', $lesson);
    }

    /**
     * Nur lokal: Lernseite ohne Login ansehen, für das Review von Darstellung und Modulen.
     */
    public function preview(Lesson $lesson): Response
    {
        abort_unless(app()->isLocal(), 404);

        return Inertia::render('lessons/Show', (new LessonPage($lesson, parent: false))->props());
    }

    /**
     * Eine Grafik als eigenständiges Dokument für das sandboxed iframe.
     * Signierte URL statt Session, weil das iframe keinen eigenen Origin hat.
     */
    public function graphic(Lesson $lesson, int $nr): HttpResponse
    {
        $graphic = $lesson->graphic($nr);

        // Ausgeblendete Grafiken sind auch über die signierte URL nicht erreichbar
        abort_unless($graphic?->graphic !== null && ! $graphic->hidden, 404);

        return HeroDocument::response($lesson, $graphic->graphic);
    }
}
