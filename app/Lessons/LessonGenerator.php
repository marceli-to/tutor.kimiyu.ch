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
     * Zweiter Durchgang, der fachliche Fehler korrigiert, getrennt für Textteil und Module.
     * Scheitert ein Teil oder liefert er Ungültiges, bleibt dieser Teil wie er ist.
     */
    public function check(Lesson $lesson): void
    {
        $content = $lesson->content;
        $notes = [];

        foreach (['seite', 'module'] as $part) {
            try {
                $data = $this->call($lesson, Prompts::check($lesson, $content, $part))->data;
            } catch (ModelException $e) {
                Log::warning('Prüf-Call fehlgeschlagen', ['lesson' => $lesson->id, 'part' => $part, 'error' => $e->detail ?? $e->getMessage()]);

                continue;
            }

            $checked = $data[$part] ?? null;
            $candidate = is_array($checked)
                ? ($part === 'module' ? self::assemble($content, $checked) : self::assemble($checked, $content['module']))
                : null;

            if ($candidate === null || ($errors = ContentValidator::errors($candidate)) !== []) {
                Log::warning('Prüf-Call lieferte ungültigen Inhalt, Original bleibt', [
                    'lesson' => $lesson->id,
                    'part' => $part,
                    'errors' => $errors ?? ['kein Inhalt'],
                ]);

                continue;
            }

            $content = $candidate;
            $notes = [...$notes, ...array_values($data['aenderungen'] ?? [])];
        }

        $lesson->update([
            'title' => $content['meta']['titel'],
            'content' => $content,
            'check_notes' => $notes,
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
