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
     * Fotos oder Thema → Zusammenfassung, Plan für die Grafik, Textteil; danach die Module.
     * Bei Regelverstössen ein Reparatur-Call pro betroffenem Teil.
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

        $missingImages = $images === [] || count($images) !== $lesson->images->count();

        if (! $lesson->isFromTopic() && $missingImages) {
            throw new GenerationFailed('Es sind keine Fotos mehr vorhanden. Bitte die Lernseite neu erstellen.');
        }

        $data = $this->call($lesson, Prompts::analysis($lesson, $images))->data;

        if (! ($data['quelle']['lesbar'] ?? false) || ! is_array($data['seite'] ?? null)) {
            throw new GenerationFailed(
                ($data['quelle']['problem'] ?? null) ?: ($lesson->isFromTopic()
                    ? 'Zu diesem Thema konnte keine Lernseite erstellt werden.'
                    : 'Auf den Fotos war kein Schulstoff zu erkennen.'),
            );
        }

        // Zuerst Zusammenfassung und Plan speichern, die nächsten Aufrufe brauchen sie
        $lesson->update([
            'step' => 'module',
            'source_summary' => (string) ($data['zusammenfassung'] ?? ''),
            'additions' => array_values(array_filter((array) ($data['ergaenzungen'] ?? []), 'is_string')) ?: null,
            'hero_plan' => $data['hero_plan'] ?? null,
        ]);

        // Die ganze Seite ist für eine strukturierte Antwort zu gross, deshalb kommen die Module separat
        $page = $data['seite'];
        $modules = $this->call($lesson, Prompts::modules($lesson, $page))->data['module'] ?? [];
        $content = self::assemble($page, $modules);

        // Fehlerhafte Teile einmal reparieren
        foreach (ContentValidator::errorsByPart($content, strict: true) as $part => $errors) {
            if ($errors === []) {
                continue;
            }

            $repaired = $this->call($lesson, Prompts::repair($lesson, $content, $part, $errors))->data[$part] ?? null;

            if (is_array($repaired)) {
                $content = $part === 'module' ? self::assemble($content, $repaired) : self::assemble($repaired, $content['module']);
            }
        }

        $errors = ContentValidator::errors($content, strict: true);

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
     * Zweiter Durchgang, der fachliche Fehler korrigiert. Die Prüfung liefert nur Korrekturen;
     * ungültige werden verworfen. Scheitert der Aufruf, bleibt die Seite wie sie ist.
     */
    public function check(Lesson $lesson): void
    {
        try {
            $corrections = $this->call($lesson, Prompts::check($lesson, $lesson->content))->data['korrekturen'] ?? [];
        } catch (ModelException $e) {
            Log::warning('Prüf-Call fehlgeschlagen', ['lesson' => $lesson->id, 'error' => $e->detail ?? $e->getMessage()]);

            return;
        }

        $result = Corrections::apply($lesson->content, $corrections);

        if ($result['rejected'] !== []) {
            Log::warning('Korrekturen der Prüfung verworfen', ['lesson' => $lesson->id, 'rejected' => $result['rejected']]);
        }

        $lesson->update([
            'title' => $result['content']['meta']['titel'],
            'content' => $result['content'],
            // Eine Änderung kann mehrere Korrekturen brauchen (z. B. Optionen und Lösung): ein Hinweis genügt.
            'check_notes' => array_values(array_unique(array_map(
                fn (array $c) => ['bereich' => $c['bereich'], 'aenderung' => $c['aenderung']],
                $result['applied'],
            ), SORT_REGULAR)),
        ]);
    }

    /**
     * Setzt Textteil und Module zu einer Seite zusammen, in der Reihenfolge der Fixtures.
     *
     * @param  array<string, mixed>  $page  Textteil (oder ganze Seite, deren Module ersetzt werden)
     * @param  array<string, mixed>  $modules
     * @return array<string, mixed>
     */
    private static function assemble(array $page, array $modules): array
    {
        $nachdenken = $page['nachdenken'] ?? null;
        unset($page['module'], $page['nachdenken']);

        return [...$page, 'module' => $modules, 'nachdenken' => $nachdenken];
    }

    /**
     * Neues Quiz für eine bestehende Seite.
     *
     * @throws GenerationFailed wenn das neue Quiz ungültig ist; das alte bleibt dann
     */
    public function regenerateQuiz(Lesson $lesson): void
    {
        $quiz = $this->call($lesson, Prompts::quiz($lesson))->data['quiz'] ?? null;

        $content = $lesson->content;
        $content['module']['quiz'] = $quiz;

        if (! is_array($quiz) || ($errors = ContentValidator::errors($content)) !== []) {
            throw new GenerationFailed('Das neue Quiz war fehlerhaft. Das bisherige Quiz bleibt.', implode(' | ', $errors ?? []));
        }

        $lesson->update(['content' => $content]);
    }

    /**
     * Die interaktive Grafik. Scheitert sie, bekommt die Seite keine Grafik, aber einen Hinweis.
     * Beim Neu-Erstellen ($keepExisting) bleibt die bisherige Grafik, wenn die neue scheitert.
     */
    public function hero(Lesson $lesson, bool $keepExisting = false): void
    {
        $fail = function (string $message) use ($lesson, $keepExisting) {
            $lesson->update([
                'hero' => $keepExisting ? $lesson->hero : null,
                'hero_error' => $keepExisting && $lesson->hero ? $message.' Die bisherige Grafik bleibt.' : $message,
            ]);
        };

        // Kein Plan: Die Eltern haben die Grafik abgewählt oder kein Muster passt zum Stoff
        if ($lesson->hero_plan === null) {
            $lesson->update(['hero' => $keepExisting ? $lesson->hero : null, 'hero_error' => null]);

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
            $fail($e->getMessage());

            return;
        }

        if ($errors !== []) {
            $fail('Die Grafik war fehlerhaft: '.implode(' ', $errors));

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
        // Kind nur einmal laden, nicht bei jedem Aufruf neu
        $lesson->loadMissing('child');
        $log = fn (string $status, ?ModelResponse $response, ?string $error = null) => $lesson->generations()->create([
            'user_id' => $lesson->child->user_id,
            'step' => $request->step,
            'model' => $response->model ?? $request->model(),
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
