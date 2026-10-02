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
use App\Lessons\GraphicDocument;
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
     * Publish: the child sees the page through its link.
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
     * Regenerates only the quiz.
     */
    public function regenerate(Lesson $lesson, string $part, RegenerateQuiz $regenerateQuiz): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRegenerate($lesson, $part), 422, 'Das geht bei dieser Lernseite gerade nicht.');

        $regenerateQuiz->handle($lesson);

        return to_route('lessons.show', $lesson);
    }

    /**
     * Regenerates only one graphic; the others stay.
     */
    public function regenerateGraphic(Lesson $lesson, int $number, RegenerateGraphic $regenerateGraphic): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRegenerate($lesson, 'graphic', $number), 422, 'Das geht bei dieser Lernseite gerade nicht.');

        $regenerateGraphic->handle($lesson, $number);

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
     * Local only: view a lesson without login, for reviewing layout and modules.
     */
    public function preview(Lesson $lesson): Response
    {
        abort_unless(app()->isLocal(), 404);

        return Inertia::render('lessons/Show', (new LessonPage($lesson, parent: false))->props());
    }

    /**
     * A graphic as a standalone document for the sandboxed iframe.
     * Signed URL instead of a session, because the iframe has no origin of its own.
     */
    public function graphic(Lesson $lesson, int $number): HttpResponse
    {
        $graphic = $lesson->graphic($number);

        // Hidden graphics can't be reached through the signed URL either
        abort_unless($graphic?->graphic !== null && ! $graphic->hidden, 404);

        return GraphicDocument::response($lesson, $graphic->graphic);
    }
}
