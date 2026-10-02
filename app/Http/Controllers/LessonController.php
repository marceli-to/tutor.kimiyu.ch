<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Http\Requests\StoreLessonRequest;
use App\Lessons\GenerationPipeline;
use App\Lessons\HeroDocument;
use App\Lessons\HeroPattern;
use App\Lessons\ImageProcessor;
use App\Lessons\LessonGenerator;
use App\Lessons\LessonView;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Database\Eloquent\Collection;
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
            $level = $request->string('level')->trim()->value() ?: (string) $request->childLevel();

            $child = $request->filled('child_id')
                ? $request->user()->children()->findOrFail($request->integer('child_id'))
                : $request->user()->children()->create([
                    'name' => $request->string('child_name')->trim()->value(),
                    'level' => $level,
                ]);

            /** @var Child $child */
            $lesson = $child->lessons()->create([
                'status' => LessonStatus::Draft,
                // Leer: Die KI erkennt das Fach in der Analyse
                'subject' => $request->string('subject')->trim()->value() ?: null,
                'level' => $level,
                'prompt' => $request->string('prompt')->trim()->value() ?: null,
                'photo_count' => count($images),
                'graphics_mode' => $request->validated('graphics_mode'),
                'purpose' => $request->validated('purpose'),
                'scope' => $request->validated('scope'),
                'modules' => array_values($request->validated('modules')),
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
                'canPublish' => $lesson->status === LessonStatus::Review && $lesson->content !== null,
                'canRegenerate' => [
                    'quiz' => GenerationPipeline::canRegenerate($lesson, 'quiz'),
                ],
                // Fehler der Grafiken sehen nur die Eltern
                'graphics' => $lesson->graphics->map(fn (LessonGraphic $graphic) => [
                    'nr' => $graphic->position,
                    'error' => $graphic->error,
                    'canRegenerate' => GenerationPipeline::canRegenerate($lesson, 'grafik', $graphic->position),
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
