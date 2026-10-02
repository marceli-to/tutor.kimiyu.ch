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
     * Fotos, Auftrag oder (bei alten Lernseiten) Thema → Zusammenfassung und Pläne für die Grafiken; danach der Textteil
     * (eigener Aufruf, zusammen ist das Schema für die API zu gross) und die Module.
     * Bei Regelverstössen ein Reparatur-Call pro betroffenem Teil. Scheitert ein Aufruf, beginnt ein neuer Versuch von vorn.
     *
     * @throws GenerationFailed wenn kein brauchbarer Inhalt entsteht
     * @throws ModelException bei API-Fehlern
     */
    public function analyze(Lesson $lesson): void
    {
        $images = [];
        $disk = Storage::disk('lesson-images');

        foreach ($lesson->images as $image) {
            // Die Disk wirft bei fehlenden Dateien; fehlende Fotos werden unten verständlich gemeldet
            $data = $disk->exists($image->path) ? $disk->get($image->path) : null;

            if (is_string($data)) {
                $images[] = ['mime' => $image->mime_type, 'data' => $data];
            }
        }

        $missingImages = $images === [] || count($images) !== $lesson->images->count();

        if (! $lesson->isFromTopic() && $missingImages) {
            throw new GenerationFailed('Es sind keine Fotos mehr vorhanden. Bitte die Lernseite neu erstellen.');
        }

        $data = $this->call($lesson, Prompts::analysis($lesson, $images))->data;

        if (! ($data['quelle']['lesbar'] ?? false)) {
            throw new GenerationFailed(
                ($data['quelle']['problem'] ?? null) ?: match (true) {
                    ! $lesson->isFromTopic() => 'Auf den Fotos war kein Schulstoff zu erkennen.',
                    $lesson->prompt !== null => 'Zu diesem Auftrag konnte keine Lernseite erstellt werden.',
                    // Alte Lernseiten aus einem Thema
                    default => 'Zu diesem Thema konnte keine Lernseite erstellt werden.',
                },
            );
        }

        // Zuerst Zusammenfassung und Pläne speichern, die nächsten Aufrufe brauchen sie
        $lesson->update([
            'source_summary' => (string) ($data['zusammenfassung'] ?? ''),
            // Ohne Fotos ist alles ergänzt, eine Liste wäre bedeutungslos
            'additions' => $lesson->isFromTopic()
                ? null
                : (array_values(array_filter((array) ($data['ergaenzungen'] ?? []), 'is_string')) ?: null),
        ]);

        $this->storeGraphicPlans($lesson, (array) ($data['grafik_plaene'] ?? []));

        // Der Textteil mit denselben Fotos, damit die Begriffe dem Buch folgen
        $page = $this->call($lesson, Prompts::pageRequest($lesson, $images))->data['seite'] ?? null;

        if (! is_array($page)) {
            throw new GenerationFailed('Die KI hat keinen gültigen Inhalt geliefert.', 'Der Textteil fehlt in der Antwort.');
        }

        // Die ganze Seite ist für eine strukturierte Antwort zu gross, deshalb kommen die Module separat
        $lesson->update(['step' => 'module']);
        $modules = $this->call($lesson, Prompts::modules($lesson, $page))->data['module'] ?? [];
        $content = self::assemble($page, self::onlyAllowed($lesson, $modules));

        // Fehlerhafte Teile einmal reparieren
        foreach (ContentValidator::errorsByPart($content, strict: true) as $part => $errors) {
            if ($errors === []) {
                continue;
            }

            $repaired = $this->call($lesson, Prompts::repair($lesson, $content, $part, $errors))->data[$part] ?? null;

            if (is_array($repaired)) {
                $content = $part === 'module' ? self::assemble($content, self::onlyAllowed($lesson, $repaired)) : self::assemble($repaired, $content['module']);
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
     * Pläne der Analyse pro Grafik speichern. «auto»: nur Grafik 1; «custom»: nur gewünschte Grafiken;
     * «none»: keine. Ohne Plan steht der Hinweis der KI als Fehler bei der Grafik.
     *
     * @param  array<mixed>  $plans
     */
    private function storeGraphicPlans(Lesson $lesson, array $plans): void
    {
        $positions = match ($lesson->graphics_mode) {
            'none' => [],
            'custom' => $lesson->graphics()->pluck('position')->all(),
            default => [1],
        };

        $planned = [];

        foreach ($plans as $entry) {
            $nr = is_array($entry) ? ($entry['nr'] ?? null) : null;

            if (! is_int($nr) || ! in_array($nr, $positions, true) || isset($planned[$nr])) {
                continue;
            }

            $plan = is_array($entry['plan'] ?? null) ? $entry['plan'] : null;
            $hint = is_string($entry['hinweis'] ?? null) && trim($entry['hinweis']) !== '' ? trim($entry['hinweis']) : null;

            $lesson->graphics()->updateOrCreate(['position' => $nr], [
                'plan' => $plan,
                'error' => $plan === null ? $hint : null,
            ]);
            $planned[$nr] = $plan;
        }

        // Die Eltern sollen wissen, warum ein Wunsch fehlt, auch wenn die KI ihn übergangen hat
        if ($lesson->graphics_mode === 'custom') {
            $lesson->graphics()->whereNotIn('position', array_keys($planned))->update([
                'plan' => null,
                'error' => 'Für diese Grafik hat die KI keinen Plan erstellt.',
            ]);
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
     * Module, welche die Eltern nicht erlaubt haben, auf null setzen, auch wenn die KI sie trotzdem liefert.
     * Bleibt keines übrig, meldet das die Prüfung des Inhalts wie jeden anderen Fehler.
     *
     * @param  array<string, mixed>  $modules
     * @return array<string, mixed>
     */
    private static function onlyAllowed(Lesson $lesson, array $modules): array
    {
        foreach (array_diff(Lesson::MODULES, $lesson->allowedModules()) as $module) {
            $modules[$module] = null;
        }

        return $modules;
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
     * Die Grafik an Position $position. Scheitert sie, fehlt nur diese Grafik, mit einem Hinweis.
     * Beim Neu-Erstellen ($keepExisting) bleibt die bisherige Grafik, wenn die neue scheitert.
     * Ohne Plan (abgewählt, kein Muster passt oder der Wunsch passt nicht zum Stoff) passiert nichts.
     */
    public function graphic(Lesson $lesson, int $position, bool $keepExisting = false): void
    {
        $graphic = $lesson->graphic($position);

        if ($graphic?->plan === null) {
            return;
        }

        $fail = function (string $message) use ($graphic, $keepExisting) {
            $old = $keepExisting ? $graphic->graphic : null;

            $graphic->update([
                'graphic' => $old,
                'error' => $old ? $message.' Die bisherige Grafik bleibt.' : $message,
            ]);
        };

        try {
            $hero = $this->call($lesson, Prompts::hero($lesson, $graphic))->data;
            $errors = HeroValidator::errors($hero);

            if ($errors !== []) {
                $hero = $this->call($lesson, Prompts::heroRepair($graphic, $hero, $errors))->data;
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

        $graphic->update([
            'graphic' => [
                'muster' => $hero['muster'],
                'beschreibung' => $hero['beschreibung'],
                'css' => $hero['css'],
                'markup' => $hero['markup'],
                'script' => $hero['script'],
            ],
            'error' => null,
            // Eine neu erstellte Grafik ist wieder sichtbar (am Ende des letzten Abschnitts, ohne Baustein)
            'hidden' => false,
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
        $userId = $lesson->child?->user_id;

        // Ohne Kind kein Konto für die Kosten: dann gar nicht erst aufrufen
        if ($userId === null) {
            throw new GenerationFailed('Diese Lernseite gehört zu keinem Kind mehr.');
        }

        $log = fn (string $status, ?ModelResponse $response, ?string $error = null) => $lesson->generations()->create([
            'user_id' => $userId,
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
