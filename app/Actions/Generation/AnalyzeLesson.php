<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\ContentValidator;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGone;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Step 1 of the generation: analysis, text part, modules, repair and validation.
 */
class AnalyzeLesson
{
    public function __construct(
        private CallModel $callModel,
        private DeleteLessonImages $deleteImages,
    ) {}

    /**
     * Fotos, Auftrag oder (bei alten Lernseiten) Thema → Zusammenfassung und Pläne für die Grafiken; danach der Textteil
     * (eigener Aufruf, zusammen ist das Schema für die API zu gross) und die Module.
     * Bei Regelverstössen ein Reparatur-Call pro betroffenem Teil. Scheitert ein Aufruf, beginnt ein neuer Versuch von vorn.
     *
     * @throws GenerationFailed wenn kein brauchbarer Inhalt entsteht
     * @throws ModelException bei API-Fehlern
     */
    public function handle(Lesson $lesson): void
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

        // A fresh analysis detects the subject again, unless the parents gave it
        if ($lesson->subject_detected) {
            $lesson->update(['subject' => null, 'subject_detected' => false]);
        }

        $data = $this->callModel->handle($lesson, Prompts::analysis($lesson, $images))->data;

        if (! ($data['source']['readable'] ?? false)) {
            throw new GenerationFailed(
                ($data['source']['problem'] ?? null) ?: match (true) {
                    ! $lesson->isFromTopic() => 'Auf den Fotos war kein Schulstoff zu erkennen.',
                    $lesson->prompt !== null => 'Zu diesem Auftrag konnte keine Lernseite erstellt werden.',
                    // Alte Lernseiten aus einem Thema
                    default => 'Zu diesem Thema konnte keine Lernseite erstellt werden.',
                },
            );
        }

        // Zuerst Fach, Zusammenfassung und Pläne speichern, die nächsten Aufrufe brauchen sie.
        // Das Fach nur, wenn die Eltern keines angegeben haben.
        $lesson->update([
            'subject_detected' => $lesson->subject === null,
            'subject' => $lesson->subject ?? self::detectedSubject($data['subject'] ?? null),
            'source_summary' => (string) ($data['summary'] ?? ''),
            // Ohne Fotos ist alles ergänzt, eine Liste wäre bedeutungslos
            'additions' => $lesson->isFromTopic()
                ? null
                : (array_values(array_filter((array) ($data['additions'] ?? []), 'is_string')) ?: null),
        ]);

        $this->storeGraphicPlans($lesson, (array) ($data['graphic_plans'] ?? []));

        // Der Textteil mit denselben Fotos, damit die Begriffe dem Buch folgen
        $page = $this->callModel->handle($lesson, Prompts::pageRequest($lesson, $images))->data['page'] ?? null;

        if (! is_array($page)) {
            throw new GenerationFailed('Die KI hat keinen gültigen Inhalt geliefert.', 'Der Textteil fehlt in der Antwort.');
        }

        // Die ganze Seite ist für eine strukturierte Antwort zu gross, deshalb kommen die Module separat
        $lesson->update(['step' => 'modules']);
        $modules = $this->callModel->handle($lesson, Prompts::modules($lesson, $page))->data['modules'] ?? [];
        $content = self::assemble($page, self::onlyAllowed($lesson, $modules));

        // Fehlerhafte Teile einmal reparieren
        foreach (ContentValidator::errorsByPart($content, strict: true) as $part => $errors) {
            if ($errors === []) {
                continue;
            }

            $repaired = $this->callModel->handle($lesson, Prompts::repair($lesson, $content, $part, $errors))->data[$part] ?? null;

            if (is_array($repaired)) {
                $content = $part === 'modules' ? self::assemble($content, self::onlyAllowed($lesson, $repaired)) : self::assemble($repaired, $content['modules']);
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
            'title' => $content['meta']['title'],
            'content' => $content,
            'schema_version' => ContentValidator::SCHEMA_VERSION,
        ]);

        if (config('lessons.delete_images')) {
            $this->deleteImages->handle($lesson);
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
        // updateOrCreate() would bring back the rows of a deleted lesson
        if (! $lesson->stillExists()) {
            throw new LessonGone;
        }

        $positions = match ($lesson->graphics_mode) {
            'none' => [],
            'custom' => $lesson->graphics()->pluck('position')->all(),
            default => [1],
        };

        $planned = [];

        foreach ($plans as $entry) {
            $number = is_array($entry) ? ($entry['number'] ?? null) : null;

            if (! is_int($number) || ! in_array($number, $positions, true) || isset($planned[$number])) {
                continue;
            }

            $plan = is_array($entry['plan'] ?? null) ? $entry['plan'] : null;
            $hint = is_string($entry['note'] ?? null) && trim($entry['note']) !== '' ? trim($entry['note']) : null;

            $lesson->graphics()->updateOrCreate(['position' => $number], [
                'plan' => $plan,
                'error' => $plan === null ? $hint : null,
            ]);
            $planned[$number] = $plan;
        }

        // Die Eltern sollen wissen, warum ein Wunsch fehlt, auch wenn die KI ihn übergangen hat
        if ($lesson->graphics_mode === 'custom') {
            $lesson->graphics()->whereNotIn('position', array_keys($planned))->update([
                'plan' => null,
                'error' => 'Für diese Grafik hat die KI keinen Plan erstellt.',
            ]);
        } else {
            // A plan from an earlier attempt that the new analysis dropped must not be built; finished graphics stay
            $lesson->graphics()->whereNotIn('position', array_keys($planned))->whereNull('graphic')->delete();
        }
    }

    /**
     * Das von der KI erkannte Fach, gekürzt; ohne brauchbare Antwort «Allgemein».
     */
    private static function detectedSubject(mixed $subject): string
    {
        $subject = is_string($subject) ? Str::limit(trim($subject), 60, '') : '';

        return $subject !== '' ? $subject : 'Allgemein';
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
        $reflect = $page['reflect'] ?? null;
        unset($page['modules'], $page['reflect']);

        return [...$page, 'modules' => $modules, 'reflect' => $reflect];
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
}
