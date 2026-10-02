<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Http\Requests\StoreLessonRequest;
use App\Lessons\GenerationPipeline;
use App\Lessons\HeroDocument;
use App\Lessons\ImageProcessor;
use App\Lessons\LessonGenerator;
use App\Lessons\LessonView;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LessonController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('lessons/Create', [
            'children' => $request->user()->children()->orderBy('name')->get(['id', 'name', 'level']),
            'maxImages' => config('lessons.images.max_count'),
            'maxEdge' => config('lessons.images.max_edge'),
        ]);
    }

    public function store(StoreLessonRequest $request): RedirectResponse
    {
        // Zuerst alle Fotos verarbeiten, damit bei einem kaputten Bild nichts halb gespeichert wird
        $images = [];
        foreach ($request->file('images', []) as $index => $file) {
            try {
                $images[] = ImageProcessor::process($file);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "images.$index" => 'Foto '.($index + 1).' konnte nicht gelesen werden. Bitte als JPEG speichern und nochmals hochladen.',
                ]);
            }
        }

        $lesson = DB::transaction(function () use ($request, $images) {
            $child = $request->filled('child_id')
                ? $request->user()->children()->findOrFail($request->integer('child_id'))
                : $request->user()->children()->create([
                    'name' => $request->string('child_name')->trim()->value(),
                    'level' => $request->string('level')->trim()->value(),
                ]);

            /** @var Child $child */
            $lesson = $child->lessons()->create([
                'status' => LessonStatus::Draft,
                'subject' => $request->string('subject')->trim()->value(),
                'level' => $request->string('level')->trim()->value(),
                'prompt' => $request->string('prompt')->trim()->value() ?: null,
                'photo_count' => count($images),
                'graphics_mode' => $request->validated('graphics_mode'),
                // Übergang bis Teil 2, Task 8: die alte Spalte noch mitführen
                'with_hero' => $request->validated('graphics_mode') !== 'none',
            ]);

            // Wünsche der Eltern als Grafik 1 bis 3
            foreach (array_values($request->validated('graphics', [])) as $index => $wish) {
                $lesson->graphics()->create([
                    'position' => $index + 1,
                    'request' => trim($wish['beschreibung']),
                    'pattern' => $wish['muster'] ?? null,
                ]);
            }

            foreach ($images as $position => $image) {
                $path = $lesson->id.'/'.Str::random(32).'.jpg';
                Storage::disk('lesson-images')->put($path, $image['data']);

                $lesson->images()->create([
                    'path' => $path,
                    'mime_type' => $image['mime'],
                    'size' => strlen($image['data']),
                    'position' => $position,
                ]);
            }

            return $lesson;
        });

        GenerationPipeline::start($lesson);

        return to_route('lessons.show', $lesson);
    }

    public function show(Lesson $lesson): Response
    {
        Gate::authorize('view', $lesson);

        return $this->render($lesson, parent: true);
    }

    public function destroy(Lesson $lesson, LessonGenerator $generator): RedirectResponse
    {
        Gate::authorize('delete', $lesson);

        $generator->deleteImages($lesson);

        // Inhalt und Lernstand verschwinden; die Zeile bleibt nur für die Kostenübersicht
        // (Titel, Fach, Stufe, Kind). «Fehlgeschlagen» statt «Zur Prüfung», weil es keinen Inhalt
        // mehr gibt: so lässt sie sich weder freigeben noch neu erstellen.
        DB::transaction(function () use ($lesson) {
            $lesson->attempts()->delete();
            // Die Grafiken sind Inhalt der Lernseite
            $lesson->graphics()->delete();

            $lesson->updateQuietly([
                'status' => LessonStatus::Failed,
                'published_at' => null,
                'content' => null,
                'prompt' => null,
                'notes' => null,
                'topic' => null,
                'source_summary' => null,
                'additions' => null,
                'hero' => null,
                'hero_plan' => null,
                'hero_error' => null,
                'check_notes' => null,
                'error' => null,
                'step' => null,
            ]);

            $lesson->delete();
        });

        $this->toast('Lernseite gelöscht.');

        return to_route('dashboard');
    }

    /**
     * Freigeben: Das Kind sieht die Seite über seinen Link.
     */
    public function publish(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless($lesson->status === LessonStatus::Review && $lesson->content !== null, 422, 'Diese Lernseite kann nicht freigegeben werden.');

        $lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);

        $this->toast("Freigegeben. {$lesson->child->name} sieht die Seite jetzt über den Link.");

        return back();
    }

    public function unpublish(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless($lesson->status === LessonStatus::Published, 422);

        $lesson->update(['status' => LessonStatus::Review, 'published_at' => null]);

        $this->toast('Die Seite ist für das Kind nicht mehr sichtbar.');

        return back();
    }

    /**
     * Nur das Quiz neu erstellen lassen.
     */
    public function regenerate(Lesson $lesson, string $part): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRegenerate($lesson, $part), 422, 'Das geht bei dieser Lernseite gerade nicht.');

        GenerationPipeline::regenerate($lesson, $part);

        return to_route('lessons.show', $lesson);
    }

    /**
     * Nur eine Grafik neu erstellen lassen; die anderen bleiben.
     */
    public function regenerateGraphic(Lesson $lesson, int $nr): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRegenerate($lesson, 'grafik', $nr), 422, 'Das geht bei dieser Lernseite gerade nicht.');

        GenerationPipeline::regenerate($lesson, 'grafik', $nr);

        return to_route('lessons.show', $lesson);
    }

    public function retry(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('update', $lesson);

        abort_unless(GenerationPipeline::canRetry($lesson), 422, 'Diese Lernseite kann nicht nochmals erstellt werden.');

        GenerationPipeline::start($lesson);

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
        $graphic = $lesson->graphic($nr)?->graphic;

        abort_unless($graphic !== null, 404);

        return HeroDocument::response($lesson, $graphic);
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
                'canPublish' => $lesson->status === LessonStatus::Review && $lesson->content !== null,
                'canRegenerate' => [
                    'quiz' => GenerationPipeline::canRegenerate($lesson, 'quiz'),
                ],
                // Fehler der Grafiken sehen nur die Eltern
                'graphics' => $lesson->graphics->map(fn (LessonGraphic $graphic) => [
                    'nr' => $graphic->position,
                    'error' => $graphic->error,
                    'canRegenerate' => GenerationPipeline::canRegenerate($lesson, 'grafik', $graphic->position),
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
