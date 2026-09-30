<?php

namespace App\Lessons;

use App\Lessons\Ai\LanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;
use App\Lessons\Ai\ModelResponse;
use App\Lessons\Ai\Prompts;
use App\Lessons\Ai\UsageAwareModelException;
use App\Models\Lesson;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Die Schritte der Generierung. Jeder Schritt wird von einem eigenen Job aufgerufen.
 */
class LessonGenerator
{
    public function __construct(private LanguageModel $model) {}

    /**
     * Fotos → Inhalt, Zusammenfassung und Plan für die Grafik. Bei Regelverstössen ein Reparatur-Call.
     *
     * @throws GenerationFailed wenn kein brauchbarer Inhalt entsteht
     * @throws ModelException bei API-Fehlern
     */
    public function analyze(Lesson $lesson): void
    {
        $images = [];
        foreach ($lesson->images as $image) {
            $data = Storage::disk('lesson-images')->get($image->path);

            if (is_string($data)) {
                $images[] = ['mime' => $image->mime_type, 'data' => $data];
            }
        }

        if ($images === [] || count($images) !== $lesson->images->count()) {
            throw new GenerationFailed('Es sind keine Fotos mehr vorhanden. Bitte die Lernseite neu erstellen.');
        }

        $data = $this->call($lesson, Prompts::analysis($lesson, $images))->data;

        if (! ($data['quelle']['lesbar'] ?? false) || ! is_array($data['inhalt'] ?? null)) {
            throw new GenerationFailed(
                $data['quelle']['problem'] ?? 'Auf den Fotos war kein Schulstoff zu erkennen.',
            );
        }

        $content = $data['inhalt'];

        // Zuerst die Zusammenfassung speichern, die Reparatur braucht sie
        $lesson->update([
            'source_summary' => (string) ($data['zusammenfassung'] ?? ''),
            'hero_plan' => $data['hero_plan'] ?? null,
        ]);

        $errors = ContentValidator::errors($content, strict: true);

        if ($errors !== []) {
            $content = $this->call($lesson, Prompts::repair($lesson, $content, $errors))->data['inhalt'] ?? [];
            $errors = ContentValidator::errors($content, strict: true);
        }

        // Nach der Reparatur reichen die normalen Regeln; die Eltern prüfen den Rest
        if ($errors !== [] && ContentValidator::errors($content) !== []) {
            throw new GenerationFailed(
                'Die KI hat keinen gültigen Inhalt geliefert.',
                implode(' | ', ContentValidator::errors($content)),
            );
        }

        $lesson->update([
            'title' => $content['meta']['titel'],
            'content' => $content,
            'schema_version' => ContentValidator::SCHEMA_VERSION,
        ]);

        if (config('lessons.delete_images')) {
            $this->deleteImages($lesson);
        }
    }

    /**
     * Zweiter Durchgang, der fachliche Fehler korrigiert. Scheitert er, bleibt der Inhalt wie er ist.
     */
    public function check(Lesson $lesson): void
    {
        try {
            $data = $this->call($lesson, Prompts::check($lesson, $lesson->content))->data;
        } catch (ModelException $e) {
            Log::warning('Prüf-Call fehlgeschlagen', ['lesson' => $lesson->id, 'error' => $e->detail ?? $e->getMessage()]);

            return;
        }

        $checked = $data['inhalt'] ?? null;

        if (! is_array($checked) || ($errors = ContentValidator::errors($checked)) !== []) {
            Log::warning('Prüf-Call lieferte ungültigen Inhalt, Original bleibt', [
                'lesson' => $lesson->id,
                'errors' => $errors ?? ['kein Inhalt'],
            ]);

            return;
        }

        $lesson->update([
            'title' => $checked['meta']['titel'],
            'content' => $checked,
            'check_notes' => array_values($data['aenderungen'] ?? []),
        ]);
    }

    /**
     * Die interaktive Grafik. Scheitert sie, bekommt die Seite keine Grafik, aber einen Hinweis.
     */
    public function hero(Lesson $lesson): void
    {
        if ($lesson->hero_plan === null) {
            $lesson->update(['hero' => null, 'hero_error' => 'Es gibt keinen Plan für die Grafik.']);

            return;
        }

        try {
            $hero = $this->call($lesson, Prompts::hero($lesson))->data;
            $errors = HeroValidator::errors($hero);

            if ($errors !== []) {
                $hero = $this->call($lesson, Prompts::heroRepair($lesson, $hero, $errors))->data;
                $errors = HeroValidator::errors($hero);
            }
        } catch (ModelException $e) {
            $lesson->update(['hero' => null, 'hero_error' => $e->getMessage()]);

            return;
        }

        if ($errors !== []) {
            $lesson->update(['hero' => null, 'hero_error' => 'Die Grafik war fehlerhaft: '.implode(' ', $errors)]);

            return;
        }

        $lesson->update([
            'hero' => [
                'muster' => $hero['muster'],
                'beschreibung' => $hero['beschreibung'],
                'css' => $hero['css'],
                'markup' => $hero['markup'],
                'script' => $hero['script'],
            ],
            'hero_error' => null,
        ]);
    }

    public function deleteImages(Lesson $lesson): void
    {
        foreach ($lesson->images as $image) {
            Storage::disk('lesson-images')->delete($image->path);
            $image->delete();
        }

        Storage::disk('lesson-images')->deleteDirectory((string) $lesson->id);
        $lesson->unsetRelation('images');
    }

    /**
     * Ein API-Call mit Eintrag im Kosten-Log, auch wenn er scheitert.
     */
    private function call(Lesson $lesson, ModelRequest $request): ModelResponse
    {
        $started = hrtime(true);
        $log = fn (string $status, ?ModelResponse $response, ?string $error = null) => $lesson->generations()->create([
            'step' => $request->step,
            'model' => $response->model ?? config('services.anthropic.model'),
            'status' => $status,
            'input_tokens' => $response->inputTokens ?? 0,
            'output_tokens' => $response->outputTokens ?? 0,
            'cache_read_tokens' => $response->cacheReadTokens ?? 0,
            'cache_write_tokens' => $response->cacheWriteTokens ?? 0,
            'cost_usd' => $response?->costUsd() ?? 0,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);

        try {
            $response = $this->model->generate($request);
        } catch (UsageAwareModelException $e) {
            $log('error', $e->response, $e->detail ?? $e->getMessage());

            throw $e;
        } catch (ModelException $e) {
            $log('error', null, $e->detail ?? $e->getMessage());

            throw $e;
        }

        $log('ok', $response);

        return $response;
    }
}
