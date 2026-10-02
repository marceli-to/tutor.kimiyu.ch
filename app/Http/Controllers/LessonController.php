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
use App\Http\Requests\StoreLessonRequest;
use App\Lessons\GenerationPipeline;
use App\Lessons\HeroDocument;
use App\Lessons\HeroPattern;
use App\Lessons\LessonView;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Database\Eloquent\Collection;
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
        // Eine Abfrage für beide Vorgaben: neueste zuerst, gelöschte nicht
        $recent = Lesson::query()
            ->whereIn('child_id', $request->user()->children()->select('id'))
            ->latest()
            ->latest('id')
            ->get(['id', 'child_id', 'subject', 'purpose', 'scope', 'modules', 'graphics_mode', 'created_at']);

        return Inertia::render('lessons/Create', [
            'children' => $request->user()->children()->orderBy('name')->get(['id', 'name', 'level']),
            'maxImages' => config('lessons.images.max_count'),
            'maxEdge' => config('lessons.images.max_edge'),
            'patterns' => array_map(
                fn (HeroPattern $pattern) => ['value' => $pattern->value, 'label' => $pattern->label()],
                HeroPattern::cases(),
            ),
            'lastSettings' => $this->lastSettings($recent),
            'lastByChild' => $this->lastByChild($recent),
            'scopeInfo' => config('lessons.scope'),
        ]);
    }

    /**
     * Einstellungen der jüngsten Lernseite pro Kind und Fach, Schlüssel «{childId}|{fach}».
     * Das Fach ist frei eingegeben, darum klein geschrieben und ohne Leerzeichen am Rand.
     *
     * @param  Collection<int, Lesson>  $recent
     * @return array<string, array{purpose: string, scope: string, modules: list<string>, graphics_mode: string}>
     */
    private function lastSettings(Collection $recent): array
    {
        return $recent
            // Noch nicht erkanntes Fach: gehört zu keinem Fach
            ->whereNotNull('subject')
            ->unique(fn (Lesson $lesson) => self::settingsKey($lesson->child_id, $lesson->subject))
            ->mapWithKeys(fn (Lesson $lesson) => [
                self::settingsKey($lesson->child_id, $lesson->subject) => [
                    'purpose' => $lesson->purpose,
                    'scope' => $lesson->scope,
                    'modules' => $lesson->allowedModules(),
                    'graphics_mode' => $lesson->graphics_mode,
                ],
            ])
            ->all();
    }

    /**
     * Einstellungen der jüngsten Lernseite pro Kind, egal welches Fach, für «Wie letztes Mal».
     * Eigene Grafikwünsche gelten nur für die eine Seite, daraus wird «KI entscheidet».
     *
     * @param  Collection<int, Lesson>  $recent
     * @return array<int, array{purpose: string, scope: string, modules: list<string>, graphics_mode: string}>
     */
    private function lastByChild(Collection $recent): array
    {
        return $recent
            ->unique('child_id')
            ->mapWithKeys(fn (Lesson $lesson) => [
                $lesson->child_id => [
                    'purpose' => $lesson->purpose,
                    'scope' => $lesson->scope,
                    'modules' => $lesson->allowedModules(),
                    'graphics_mode' => $lesson->graphics_mode === 'custom' ? 'auto' : $lesson->graphics_mode,
                ],
            ])
            ->all();
    }

    private static function settingsKey(int $childId, ?string $subject): string
    {
        return $childId.'|'.mb_strtolower(trim((string) $subject));
    }

    public function store(StoreLessonRequest $request, CreateLesson $createLesson): RedirectResponse
    {
        $lesson = $createLesson->handle($request->user(), $request->validated(), $request->childLevel());

        return to_route('lessons.show', $lesson);
    }

    public function show(Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);

        return $this->render($lesson, parent: true);
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

        return $this->render($lesson, parent: false);
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

    /**
     * Welche Grafiken gebaut werden, für die Fortschrittsanzeige. Nach der Analyse die mit Plan,
     * vorher Grafik 1 («KI entscheidet») oder die Wünsche der Eltern.
     *
     * @return list<int>
     */
    private function plannedGraphics(Lesson $lesson): array
    {
        if ($lesson->graphics_mode === 'none') {
            return [];
        }

        if ($lesson->content !== null) {
            return array_values($lesson->graphics
                ->filter(fn (LessonGraphic $graphic) => $graphic->plan !== null)
                ->map(fn (LessonGraphic $graphic) => $graphic->position)
                ->all());
        }

        return $lesson->graphics_mode === 'custom'
            ? array_values($lesson->graphics->map(fn (LessonGraphic $graphic) => $graphic->position)->all())
            : [1];
    }

    private function render(Lesson $lesson, bool $parent): Response
    {
        return Inertia::render('lessons/Show', [
            'parent' => $parent ? [
                'childName' => $lesson->child->name,
                'shareUrl' => $lesson->status === LessonStatus::Published
                    ? route('shared.show', [$lesson->child->share_token, $lesson])
                    : null,
                'canPublish' => $lesson->canBePublished(),
                'canRegenerate' => [
                    'quiz' => GenerationPipeline::canRegenerate($lesson, 'quiz'),
                ],
                // How many questions a new quiz gets, for the confirm dialog
                'quizCount' => config('lessons.scope')[$lesson->scope]['quiz'],
                // Fehler der Grafiken sehen nur die Eltern
                'graphics' => $lesson->graphics->map(fn (LessonGraphic $graphic) => [
                    'number' => $graphic->position,
                    'error' => $graphic->error,
                    'canRegenerate' => GenerationPipeline::canRegenerate($lesson, 'graphic', $graphic->position),
                    'hidden' => $graphic->hidden,
                ])->values()->all(),
                'additions' => $lesson->isFromTopic() ? [] : ($lesson->additions ?? []),
            ] : null,
            'lesson' => [
                ...LessonView::page($lesson, showOrigin: $parent),
                'status' => $lesson->status->value,
                'step' => $lesson->step,
                'error' => $lesson->error,
                'canRetry' => GenerationPipeline::canRetry($lesson),
                'fromTopic' => $lesson->isFromTopic(),
                'plannedGraphics' => $this->plannedGraphics($lesson),
                'checkNotes' => $lesson->check_notes ?? [],
            ],
        ]);
    }
}
