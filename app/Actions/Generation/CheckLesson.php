<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\Corrections;
use App\Models\Lesson;
use Illuminate\Support\Facades\Log;

class CheckLesson
{
    public function __construct(private CallModel $callModel) {}

    /**
     * Zweiter Durchgang, der fachliche Fehler korrigiert. Die Prüfung liefert nur Korrekturen;
     * ungültige werden verworfen. Scheitert der Aufruf, bleibt die Seite wie sie ist.
     */
    public function handle(Lesson $lesson): void
    {
        try {
            $corrections = $this->callModel->handle($lesson, Prompts::check($lesson, $lesson->content))->data['corrections'] ?? [];
        } catch (ModelException $e) {
            Log::warning('Prüf-Call fehlgeschlagen', ['lesson' => $lesson->id, 'error' => $e->detail ?? $e->getMessage()]);

            return;
        }

        $result = Corrections::apply($lesson->content, $corrections);

        if ($result['rejected'] !== []) {
            Log::warning('Korrekturen der Prüfung verworfen', ['lesson' => $lesson->id, 'rejected' => $result['rejected']]);
        }

        $lesson->update([
            'title' => $result['content']['meta']['title'],
            'content' => $result['content'],
            // Eine Änderung kann mehrere Korrekturen brauchen (z. B. Optionen und Lösung): ein Hinweis genügt.
            'check_notes' => array_values(array_unique(array_map(
                fn (array $c) => ['area' => $c['area'], 'change' => $c['change']],
                $result['applied'],
            ), SORT_REGULAR)),
        ]);
    }
}
