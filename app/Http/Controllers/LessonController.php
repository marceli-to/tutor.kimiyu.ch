<?php

namespace App\Http\Controllers;

use App\Enums\LessonStatus;
use App\Http\Requests\StoreLessonRequest;
use App\Lessons\GenerationPipeline;
use App\Lessons\HeroDocument;
use App\Lessons\ImageProcessor;
use App\Lessons\Palettes;
use App\Models\Child;
use App\Models\Lesson;
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
        foreach ($request->file('images') as $index => $file) {
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
                'notes' => $request->string('notes')->trim()->value() ?: null,
            ]);

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

        return $this->render($lesson);
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

        return $this->render($lesson);
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

    private function render(Lesson $lesson): Response
    {
        return Inertia::render('lessons/Show', [
            'lesson' => [
                'id' => $lesson->id,
                'status' => $lesson->status->value,
                'step' => $lesson->step,
                'error' => $lesson->error,
                'canRetry' => GenerationPipeline::canRetry($lesson),
                'subject' => $lesson->subject,
                'level' => $lesson->level,
                'content' => $lesson->content,
                'palette' => $lesson->content ? Palettes::get($lesson->content['meta']['palette'] ?? null) : null,
                'hero' => $lesson->hero ? [
                    'url' => URL::signedRoute('lessons.hero', [
                        'lesson' => $lesson,
                        'v' => $lesson->updated_at?->timestamp,
                    ]),
                    'beschreibung' => $lesson->hero['beschreibung'] ?? '',
                ] : null,
                'heroError' => $lesson->hero_error,
                'checkNotes' => $lesson->check_notes ?? [],
            ],
        ]);
    }
}
